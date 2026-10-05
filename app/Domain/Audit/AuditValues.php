<?php

namespace App\Domain\Audit;

use App\Domain\Integrations\Google\GoogleDisconnectReason;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\BillingType;
use App\Enums\ConversationType;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use App\Support\Duration;
use App\Support\LocalTime;
use App\Support\RichText;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Valores legibles de la auditoría (D-074): nombres en lugar de ids, fechas en Madrid, duraciones
 * h:mm, importes en euros (solo lo ve el admin, que puede ver los datos económicos), enumerados
 * con su etiqueta y los textos largos recortados y sin HTML.
 *
 * Uso: prepare() con todos los cambios de la página (una consulta por tipo de referencia, nunca
 * una por fila) y después label() y format() campo a campo.
 */
final class AuditValues
{
    /** Longitud máxima de un texto en el detalle. */
    public const int TEXT_LIMIT = 300;

    /** Campos que apuntan a otra tabla → tipo de referencia. */
    private const array REFERENCES = [
        'user_id' => 'users',
        'owner_user_id' => 'users',
        'assignee_user_id' => 'users',
        'approved_by' => 'users',
        'reviewed_by' => 'users',
        'closed_by' => 'users',
        'created_by' => 'users',
        'locked_by' => 'users',
        'unlocked_by' => 'users',
        'subject_user_id' => 'users',
        'requested_by' => 'users',
        'project_id' => 'projects',
        'client_id' => 'clients',
        'hour_bank_id' => 'hour_banks',
        'renewed_from_id' => 'hour_banks',
        'task_id' => 'tasks',
        'parent_task_id' => 'tasks',
        'task_type_id' => 'task_types',
        'status_id' => 'task_statuses',
        'department_id' => 'departments',
        'recurring_task_rule_id' => 'recurring_rules',
        'conversation_id' => 'conversations',
    ];

    /** Duraciones en minutos (h:mm). */
    private const array MINUTES = [
        'minutes', 'overage_minutes', 'estimated_minutes', 'budget_minutes', 'total_minutes',
        'consumed_minutes', 'closed_remaining_minutes', 'partial_minutes',
    ];

    /** Importes (decimal en string). */
    private const array MONEY = [
        'hourly_rate', 'fixed_price_amount', 'price_amount', 'default_hourly_rate',
        'hourly_rate_snapshot', 'hourly_cost_snapshot', 'hourly_cost',
    ];

    /** Fechas locales sin hora. */
    private const array DATES = [
        'date', 'start_date', 'due_date', 'end_date', 'week_start', 'week', 'starts_on', 'ends_on',
        'last_generated_on', 'date_from', 'date_to', 'occurrence_date', 'valid_from', 'valid_to',
    ];

    /** Textos que pueden llevar HTML del editor: se muestran en texto plano y recortados. */
    private const array RICH_TEXT = ['description', 'body', 'notes', 'review_comment', 'comment'];

    /** Enumerados por campo (y, si depende, por log_name). */
    private const array ENUMS = [
        'billing_type' => BillingType::class,
        'priority' => TaskPriority::class,
        'overage_policy' => OveragePolicy::class,
        'category' => TaskStatusCategory::class,
    ];

    private const array ENUMS_BY_LOG = [
        'projects' => ['status' => ProjectStatus::class],
        'hour_banks' => ['status' => HourBankStatus::class],
        'time_entries' => ['status' => TimeEntryStatus::class],
        'timesheet_periods' => ['status' => TimesheetStatus::class, 'from' => TimesheetStatus::class],
        'absences' => ['status' => AbsenceStatus::class, 'type' => AbsenceType::class],
        'integrations' => ['reason' => GoogleDisconnectReason::class],
        'task_statuses' => ['from' => TaskStatusCategory::class, 'to' => TaskStatusCategory::class],
    ];

    /** @var array<string, array<int, string>> tipo de referencia → id → nombre */
    private array $names = [];

    /**
     * Carga los nombres de todas las referencias citadas (una consulta por tipo).
     *
     * @param  iterable<array<string, mixed>>  $changeSets  cada uno, campo → valor
     * @param  list<int>  $extraUserIds  personas que se necesitan además (quien hizo el cambio…)
     */
    public function prepare(iterable $changeSets, array $extraUserIds = []): void
    {
        $ids = array_fill_keys(array_unique(array_values(self::REFERENCES)), []);
        $ids['users'] = $extraUserIds;

        foreach ($changeSets as $values) {
            foreach ($values as $field => $value) {
                $type = self::REFERENCES[$field] ?? null;

                if ($type !== null && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                    $ids[$type][] = (int) $value;
                }
            }
        }

        $this->names = [];

        foreach ($ids as $type => $list) {
            $list = array_values(array_unique(array_filter($list, fn (int $id): bool => $id > 0)));
            $this->names[$type] = $list === [] ? [] : $this->load($type, $list);
        }
    }

    /** Nombre de una persona ya cargada con prepare(). */
    public function userName(int $id): ?string
    {
        return $this->names['users'][$id] ?? null;
    }

    /**
     * Nombre legible del campo (lang/es/audit.php: fields_by_log.{log}.{campo} y fields.{campo}).
     */
    public function label(string $field, ?string $logName = null): string
    {
        foreach ([$logName !== null ? "audit.fields_by_log.{$logName}.{$field}" : null, "audit.fields.{$field}"] as $key) {
            if ($key === null) {
                continue;
            }

            $label = __($key);

            if (is_string($label) && $label !== $key) {
                return $label;
            }
        }

        return Str::ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Valor legible, o null si no hay valor (el detalle muestra «—»).
     */
    public function format(string $field, mixed $value, ?string $logName = null): ?string
    {
        if (str_starts_with($field, 'retention_')) {
            return $value === null || $value === '' ? $this->text('audit.values.unlimited') : $this->choice('audit.values.months', (int) $value);
        }

        if ($value === null || $value === '') {
            return $field === 'attachments_warning_gb' ? $this->text('audit.values.no_warning') : null;
        }

        if (is_bool($value)) {
            return $this->text($value ? 'audit.values.yes' : 'audit.values.no');
        }

        if (is_array($value)) {
            return $this->formatList($field, $value);
        }

        if (isset(self::REFERENCES[$field]) && is_numeric($value)) {
            return $this->names[self::REFERENCES[$field]][(int) $value] ?? $this->text('audit.values.missing', ['id' => (int) $value]);
        }

        $enum = self::ENUMS_BY_LOG[$logName ?? ''][$field] ?? self::ENUMS[$field] ?? null;

        if ($enum !== null && (is_string($value) || is_int($value))) {
            return $this->enumLabel($enum, $value);
        }

        return match (true) {
            in_array($field, self::MINUTES, true) && is_numeric($value) => Duration::format((int) $value),
            in_array($field, self::MONEY, true) && is_numeric($value) => self::money((string) $value),
            in_array($field, self::DATES, true) && is_string($value) => self::date($value),
            (str_ends_with($field, '_at')) && is_string($value) => self::dateTime($value),
            in_array($field, self::RICH_TEXT, true) && is_string($value) => Str::limit(RichText::toPlainText($value), self::TEXT_LIMIT),
            $field === 'structure' => $this->text('audit.values.structure'),
            $field === 'is_default' || str_starts_with($field, 'is_') || str_starts_with($field, 'allow_') || str_starts_with($field, 'require_') => $this->text($value ? 'audit.values.yes' : 'audit.values.no'),
            $field === 'weekday' && is_numeric($value) => $this->text('audit.values.weekdays.'.((int) $value)),
            in_array($field, ['disk_warning_percent', 'occupancy_low_threshold', 'occupancy_high_threshold'], true) => $value.' %',
            $field === 'attachments_warning_gb' => $value.' GB',
            $field === 'max_attachment_mb' => $value.' MB',
            $field === 'personal_data_export_days' => $this->choice('audit.values.days', (int) $value),
            $field === 'timer_warning_hours' => $value.' h',
            $field === 'timer_rounding_minutes' => $value.' min',
            is_scalar($value) => Str::limit((string) $value, self::TEXT_LIMIT),
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function formatList(string $field, array $value): ?string
    {
        if ($value === []) {
            return null;
        }

        if ($field === 'default_work_minutes' || $field === 'week') {
            return implode(' · ', array_map(fn (mixed $minutes): string => Duration::format((int) $minutes), $value));
        }

        if ($field === 'hour_bank_alert_thresholds') {
            return implode(', ', array_map(fn (mixed $percent): string => ((int) $percent).' %', $value));
        }

        if ($field === 'dates') {
            $dates = array_map(fn (mixed $date): string => is_string($date) ? self::date($date) : '', array_values($value));
            $shown = array_slice($dates, 0, 10);
            $rest = count($dates) - count($shown);

            return implode(', ', $shown).($rest > 0 ? ' '.$this->text('audit.values.and_more', ['count' => $rest]) : '');
        }

        if (array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_scalar($item)) === []) {
            return Str::limit(implode(', ', array_map(fn (mixed $item): string => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item, $value)), self::TEXT_LIMIT);
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : Str::limit($json, self::TEXT_LIMIT);
    }

    /**
     * @param  class-string  $enum
     */
    private function enumLabel(string $enum, string|int $value): string
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            return (string) $value;
        }

        $case = $enum::tryFrom($value);

        return $case !== null && method_exists($case, 'label') ? (string) $case->label() : (string) $value;
    }

    /**
     * «1234.5» → «1.234,50 €», con bcmath (nunca float).
     */
    public static function money(string $value): string
    {
        $rounded = bcround(is_numeric($value) ? $value : '0', 2);
        $negative = str_starts_with($rounded, '-');
        [$integer, $decimals] = array_pad(explode('.', ltrim($rounded, '-')), 2, '');
        $integer = ltrim($integer, '0') === '' ? '0' : ltrim($integer, '0');
        $grouped = strrev(implode('.', str_split(strrev($integer), 3)));

        return ($negative && $rounded !== '0.00' ? '-' : '').$grouped.','.str_pad(substr($decimals, 0, 2), 2, '0').' €';
    }

    /** «2026-09-27» o un instante ISO de una fecha → «27/09/2026». */
    public static function date(string $value): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match) === 1
            ? "{$match[3]}/{$match[2]}/{$match[1]}"
            : $value;
    }

    /** Instante (UTC) → «27/09/2026 14:05» en Madrid. */
    public static function dateTime(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->setTimezone(LocalTime::timezone())->format('d/m/Y H:i');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function load(string $type, array $ids): array
    {
        return match ($type) {
            'users' => $this->pluck(User::query(), $ids, 'name'),
            'projects' => $this->projectNames($ids),
            'clients' => $this->pluck(Client::query()->withTrashed(), $ids, 'name'),
            'hour_banks' => $this->pluck(HourBank::query()->withTrashed(), $ids, 'name'),
            'tasks' => $this->pluck(Task::query()->withTrashed(), $ids, 'title'),
            'task_types' => $this->pluck(TaskType::query()->withTrashed(), $ids, 'name'),
            'task_statuses' => $this->pluck(TaskStatus::query(), $ids, 'name'),
            'departments' => $this->pluck(Department::query(), $ids, 'name'),
            'recurring_rules' => $this->pluck(RecurringTaskRule::query(), $ids, 'title'),
            'conversations' => self::conversationNames($ids),
            default => [],
        };
    }

    /**
     * Nombre de cada conversación del chat: el grupo por su nombre, la de un proyecto como «Chat
     * de CÓDIGO · Nombre» y las directas sin nombrar a nadie.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public static function conversationNames(array $ids): array
    {
        $names = [];
        $conversations = Conversation::query()->whereKey($ids)
            ->with(['project' => fn ($project) => $project->withTrashed()->select(['id', 'code', 'name'])])
            ->get(['id', 'type', 'name', 'project_id']);

        foreach ($conversations as $conversation) {
            $project = $conversation->project;

            $names[$conversation->id] = match ($conversation->type) {
                ConversationType::Group => (string) $conversation->name,
                ConversationType::Project => self::line('audit.values.project_conversation', ['project' => $project !== null ? "{$project->code} · {$project->name}" : '—']),
                ConversationType::Direct => self::line('audit.values.direct_conversation'),
            };
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function projectNames(array $ids): array
    {
        $names = [];

        foreach (Project::query()->withTrashed()->whereKey($ids)->get(['id', 'code', 'name']) as $project) {
            $names[$project->id] = "{$project->code} · {$project->name}";
        }

        return $names;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function pluck(Builder $query, array $ids, string $column): array
    {
        $names = [];

        foreach ($query->whereKey($ids)->pluck($column, $query->getModel()->getKeyName()) as $id => $name) {
            $names[(int) $id] = (string) $name;
        }

        return $names;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    private function choice(string $key, int $count): string
    {
        return trans_choice($key, $count, ['count' => $count]);
    }
}
