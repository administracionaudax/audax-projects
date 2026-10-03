<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Entradas de la auditoría listas para la página y el CSV (D-074): quién, qué elemento, qué acción,
 * cuándo y el antes y el después campo a campo con nombres legibles.
 *
 * - Los modelos (LogsDomainActivity) guardan los cambios en attribute_changes: attributes (lo
 *   nuevo) y old (lo anterior). Al crear solo hay attributes; al borrar, solo old.
 * - Los registros propios (festivos, semanas, bloqueos, privacidad, ajustes…) guardan sus datos en
 *   properties: si traen old/attributes son cambios; el resto de claves son el detalle del evento.
 * - Personas, elementos y referencias se cargan con una consulta por tipo para toda la página.
 *
 * Contrato de cada entrada: resources/js/types/audit.ts (AuditEntry).
 */
final class AuditEntries
{
    /** Campos que no se muestran (ruido técnico). */
    private const array HIDDEN = ['id', 'created_at', 'updated_at', 'user_name', 'subject_name'];

    public function __construct(
        private readonly AuditSubjects $subjects,
        private readonly AuditValues $values,
    ) {}

    /**
     * @param  Collection<int, Activity>  $activities
     * @return list<array{id: int, created_at: string|null, causer: array{id: int, name: string}|null, entity: array{key: string|null, label: string}, subject: array{label: string, url: string|null, deleted: bool}|null, event: string, event_label: string, changes: list<array{field: string, label: string, from: string|null, to: string|null}>}>
     */
    public function present(Collection $activities): array
    {
        $subjects = $this->subjects->load($activities);
        $userType = (new User)->getMorphClass();

        $causers = [];
        $changeSets = [];

        foreach ($activities as $activity) {
            if ($activity->causer_type === $userType && $activity->causer_id !== null) {
                $causers[] = (int) $activity->causer_id;
            }

            [$new, $old, $details] = self::split($activity);
            $changeSets[] = $new;
            $changeSets[] = $old;
            $changeSets[] = $details;
        }

        $this->values->prepare($changeSets, [...$causers, ...AuditSubjects::userIds($subjects)]);

        $entries = [];

        foreach ($activities as $activity) {
            $entries[] = $this->entry($activity, $subjects, $userType);
        }

        return $entries;
    }

    /**
     * Texto de los cambios en una línea («Estado: Por hacer → Hecha; Título: … »), para el CSV.
     *
     * @param  list<array{field: string, label: string, from: string|null, to: string|null}>  $changes
     */
    public static function summary(array $changes): string
    {
        return implode('; ', array_map(function (array $change): string {
            if ($change['from'] === null) {
                return "{$change['label']}: ".($change['to'] ?? '—');
            }

            return "{$change['label']}: {$change['from']} → ".($change['to'] ?? '—');
        }, $changes));
    }

    /**
     * @param  array<string, array<int, AuditSubject>>  $subjects
     * @return array{id: int, created_at: string|null, causer: array{id: int, name: string}|null, entity: array{key: string|null, label: string}, subject: array{label: string, url: string|null, deleted: bool}|null, event: string, event_label: string, changes: list<array{field: string, label: string, from: string|null, to: string|null}>}
     */
    private function entry(Activity $activity, array $subjects, string $userType): array
    {
        $entity = AuditCatalog::entityOf($activity->log_name);
        $event = (string) ($activity->event ?? $activity->description);
        $causerId = $activity->causer_type === $userType && $activity->causer_id !== null ? (int) $activity->causer_id : null;

        return [
            'id' => (int) $activity->id,
            'created_at' => $activity->created_at?->toIso8601ZuluString(),
            'causer' => $causerId === null ? null : [
                'id' => $causerId,
                'name' => $this->values->userName($causerId) ?? $this->text('audit.values.missing_person', ['id' => $causerId]),
            ],
            'entity' => ['key' => $entity, 'label' => AuditCatalog::entityLabel($entity)],
            'subject' => $this->subject($activity, $subjects, $entity),
            'event' => $event,
            'event_label' => AuditCatalog::eventLabel($event),
            'changes' => $this->changes($activity, $event),
        ];
    }

    /**
     * @param  array<string, array<int, AuditSubject>>  $subjects
     * @return array{label: string, url: string|null, deleted: bool}|null
     */
    private function subject(Activity $activity, array $subjects, ?string $entity): ?array
    {
        if ($activity->subject_type !== null && $activity->subject_id !== null) {
            $subject = $subjects[$activity->subject_type][(int) $activity->subject_id] ?? null;

            if ($subject !== null) {
                return ['label' => $subject->label($this->values), 'url' => $subject->url, 'deleted' => $subject->deleted];
            }

            // Ya no existe: se nombra con lo que guardó la propia entrada.
            return ['label' => $this->storedName($activity) ?? $this->text('audit.subjects.unknown', [
                'entity' => AuditCatalog::entityLabel($entity),
                'id' => (int) $activity->subject_id,
            ]), 'url' => null, 'deleted' => true];
        }

        return $this->loose($activity);
    }

    /**
     * Entradas sin elemento: el texto informativo, los plazos de conservación, los ajustes, las
     * importaciones de festivos y los informes exportados.
     *
     * @return array{label: string, url: string|null, deleted: bool}|null
     */
    private function loose(Activity $activity): ?array
    {
        $year = $activity->getProperty('year');

        return match (true) {
            $activity->log_name === 'privacy' && $activity->description === 'retention.updated' => ['label' => $this->text('audit.subjects.retention'), 'url' => route('admin.privacy.edit', [], false), 'deleted' => false],
            $activity->log_name === 'privacy' => ['label' => $this->text('audit.subjects.privacy_notice'), 'url' => route('admin.privacy.edit', [], false), 'deleted' => false],
            $activity->log_name === 'settings' => ['label' => $this->text('audit.subjects.settings'), 'url' => route('admin.settings.edit', [], false), 'deleted' => false],
            $activity->log_name === 'holidays' => [
                'label' => is_numeric($year) ? $this->text('audit.subjects.holidays_year', ['year' => (int) $year]) : $this->text('audit.subjects.holidays'),
                'url' => route('admin.holidays.index', is_numeric($year) ? ['anio' => (int) $year] : [], false),
                'deleted' => false,
            ],
            // Informes exportados (D-139): el título legible con el periodo y los filtros.
            $activity->log_name === 'report-delivery' && is_string($activity->getProperty('title')) => ['label' => (string) $activity->getProperty('title'), 'url' => null, 'deleted' => false],
            default => null,
        };
    }

    private function storedName(Activity $activity): ?string
    {
        [$new, $old, $details] = self::split($activity);

        foreach (['name', 'title'] as $field) {
            foreach ([$new, $old, $details] as $values) {
                if (isset($values[$field]) && is_string($values[$field]) && $values[$field] !== '') {
                    return $values[$field];
                }
            }
        }

        $date = $new['date'] ?? $old['date'] ?? $new['start_date'] ?? $old['start_date'] ?? null;

        return is_string($date) ? $this->text('audit.subjects.dated', [
            'entity' => AuditCatalog::entityLabel(AuditCatalog::entityOf($activity->log_name)),
            'date' => AuditValues::date($date),
        ]) : null;
    }

    /**
     * @return list<array{field: string, label: string, from: string|null, to: string|null}>
     */
    private function changes(Activity $activity, string $event): array
    {
        if ($event === 'restored') {
            return [];
        }

        [$new, $old, $details] = self::split($activity);
        $log = $activity->log_name;
        $changes = [];

        $fields = array_keys($new + $old);

        foreach ($fields as $field) {
            if (in_array($field, self::HIDDEN, true)) {
                continue;
            }

            $from = array_key_exists($field, $old) ? $this->values->format($field, $old[$field], $log) : null;
            $to = array_key_exists($field, $new) ? $this->values->format($field, $new[$field], $log) : null;

            // Al crear o borrar solo interesan los campos con valor.
            if ($from === null && $to === null) {
                continue;
            }

            if (! array_key_exists($field, $old) || ! array_key_exists($field, $new)) {
                $changes[] = ['field' => $field, 'label' => $this->values->label($field, $log), 'from' => $from, 'to' => $to];

                continue;
            }

            if ($from !== $to) {
                $changes[] = ['field' => $field, 'label' => $this->values->label($field, $log), 'from' => $from, 'to' => $to];
            }
        }

        foreach ($details as $field => $value) {
            if (in_array($field, self::HIDDEN, true)) {
                continue;
            }

            $formatted = $this->values->format($field, $value, $log);

            if ($formatted !== null) {
                $changes[] = ['field' => $field, 'label' => $this->values->label($field, $log), 'from' => null, 'to' => $formatted];
            }
        }

        return $changes;
    }

    /**
     * Cambios (lo nuevo y lo anterior) y detalle de una entrada, sea de un modelo o de un registro propio.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private static function split(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $properties = $activity->properties?->toArray() ?? [];

        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];

        if ($new === [] && $old === []) {
            $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];
            $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        }

        unset($properties['attributes'], $properties['old']);

        /** @var array<string, mixed> $new */
        /** @var array<string, mixed> $old */
        /** @var array<string, mixed> $properties */
        return [$new, $old, $properties];
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
