<?php

namespace App\Domain\Recurring;

use App\Enums\ProjectStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Collection;

/**
 * Filas de las reglas recurrentes para la interfaz (Ajustes del proyecto y vista global del admin,
 * D-059): la regla, su frase legible, la próxima fecha y los avisos que impiden crear sus tareas
 * (bolsa cerrada, responsable de baja, proyecto archivado). Carga responsables, bolsas y proyectos
 * con una consulta por tipo, nunca por fila. Tipo en resources/js/types/templates.ts
 * (RecurringRuleItem).
 */
final class RecurringRuleItems
{
    public function __construct(private readonly RecurrenceDescriber $describer) {}

    /**
     * @param  iterable<RecurringTaskRule>  $rules
     * @param  Project|null  $project  el proyecto de todas las reglas (Ajustes), para no cargarlo
     * @return list<array<string, mixed>>
     */
    public function build(iterable $rules, ?Project $project = null): array
    {
        $rules = collect($rules)->values();
        $today = LocalTime::today();

        $userIds = $this->ids($rules, 'assignee_user_id');
        $bankIds = $this->ids($rules, 'hour_bank_id');
        $projectIds = $this->ids($rules, 'project_id');

        /** @var Collection<int, User> $users */
        $users = $userIds === [] ? collect() : User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'is_active'])
            ->keyBy('id');

        /** @var Collection<int, HourBank> $banks */
        $banks = $bankIds === [] ? collect() : HourBank::query()
            ->withTrashed()
            ->whereIn('id', $bankIds)
            ->get(['id', 'name', 'status', 'deleted_at'])
            ->keyBy('id');

        /** @var Collection<int, Project> $projects */
        $projects = match (true) {
            $project !== null => collect([$project->id => $project]),
            $projectIds === [] => collect(),
            default => Project::query()
                ->whereIn('id', $projectIds)
                ->get(['id', 'name', 'code', 'status', 'billing_type'])
                ->keyBy('id'),
        };

        $items = [];
        foreach ($rules as $rule) {
            /** @var RecurringTaskRule $rule */
            $assignee = $rule->assignee_user_id !== null ? $users->get($rule->assignee_user_id) : null;
            $bank = $rule->hour_bank_id !== null ? $banks->get($rule->hour_bank_id) : null;
            $ruleProject = $projects->get($rule->project_id);

            $items[] = [
                'id' => $rule->id,
                'project_id' => $rule->project_id,
                'project' => $ruleProject !== null ? [
                    'id' => $ruleProject->id,
                    'name' => $ruleProject->name,
                    'code' => $ruleProject->code,
                    'archived' => $ruleProject->status === ProjectStatus::Archived,
                ] : null,
                'title' => $rule->title,
                'description' => $rule->description,
                'task_type_id' => $rule->task_type_id,
                'assignee_user_id' => $rule->assignee_user_id,
                'assignee' => $assignee !== null ? ['id' => $assignee->id, 'name' => $assignee->name, 'is_active' => $assignee->is_active] : null,
                'hour_bank_id' => $rule->hour_bank_id,
                'hour_bank' => $bank !== null ? ['id' => $bank->id, 'name' => $bank->name, 'open' => $bank->acceptsTime() && ! $bank->trashed()] : null,
                'estimated_minutes' => $rule->estimated_minutes,
                'priority' => $rule->priority,
                'frequency' => $rule->frequency,
                'interval' => $rule->interval,
                'weekday' => $rule->weekday,
                'month_day' => $rule->month_day,
                'due_offset_days' => $rule->due_offset_days,
                'starts_on' => $rule->starts_on->toDateString(),
                'ends_on' => $rule->ends_on?->toDateString(),
                'is_active' => $rule->is_active,
                'last_generated_on' => $rule->last_generated_on?->toDateString(),
                'summary' => $this->describer->describe($rule),
                'next_date' => $ruleProject?->status === ProjectStatus::Archived ? null : $this->describer->nextDate($rule, $today),
                'warnings' => $this->warnings($rule, $assignee, $bank, $ruleProject, $today->toDateString()),
            ];
        }

        return $items;
    }

    /**
     * Ids distintos (no nulos) de una columna de las reglas.
     *
     * @param  Collection<int, RecurringTaskRule>  $rules
     * @return list<int>
     */
    private function ids(Collection $rules, string $column): array
    {
        return array_values(array_unique(array_filter(
            $rules->map(fn (RecurringTaskRule $rule): ?int => $rule->getAttribute($column) !== null ? (int) $rule->getAttribute($column) : null)->all(),
            fn (?int $id): bool => $id !== null,
        )));
    }

    /**
     * Por qué una regla activa no creará sus tareas como se espera (textos con icono en la UI).
     *
     * @return list<string>
     */
    private function warnings(RecurringTaskRule $rule, ?User $assignee, ?HourBank $bank, ?Project $project, string $today): array
    {
        if (! $rule->is_active) {
            return [];
        }

        $warnings = [];

        if ($project?->status === ProjectStatus::Archived) {
            $warnings[] = $this->text('templates.warnings.project_archived');
        }

        if ($bank !== null && (! $bank->acceptsTime() || $bank->trashed())) {
            $warnings[] = $this->text('templates.warnings.bank_closed', ['bank' => $bank->name]);
        }

        if ($assignee !== null && ! $assignee->is_active) {
            $warnings[] = $this->text('templates.warnings.assignee_inactive', ['name' => $assignee->name]);
        }

        if ($rule->ends_on !== null && $rule->ends_on->toDateString() < $today) {
            $warnings[] = $this->text('templates.warnings.ended', ['date' => $rule->ends_on->format('d/m/Y')]);
        }

        return $warnings;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
