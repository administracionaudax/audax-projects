<?php

namespace App\Http\Controllers\Recurring;

use App\Domain\Recurring\RecurringTaskGenerator;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recurring\RecurringRuleRequest;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Tareas recurrentes de un proyecto (SPEC §4.3, D-059), desde su pestaña Ajustes y para quien lo
 * gestiona: crear, editar, activar o desactivar y borrar reglas. Al crear, editar o reactivar una
 * regla activa se crea ya la tarea de hoy si toca (RecurringTaskGenerator::generateFor). Borrar una
 * regla no borra las tareas que ya creó (se quedan sin regla).
 */
class RecurringRuleController extends Controller
{
    public function __construct(private readonly RecurringTaskGenerator $generator) {}

    public function store(RecurringRuleRequest $request, Project $project): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rule = DB::transaction(fn (): RecurringTaskRule => RecurringTaskRule::query()->create([
            ...$request->ruleData(),
            'project_id' => $project->id,
            'created_by' => $user->id,
        ]));
        $rule->setRelation('project', $project);

        $created = $this->generator->generateFor($rule, LocalTime::today());

        $this->toast($created !== null ? 'rule_created_now' : 'rule_created', $rule);

        return to_route('projects.settings', $project);
    }

    public function update(RecurringRuleRequest $request, Project $project, RecurringTaskRule $rule): RedirectResponse
    {
        abort_unless($rule->project_id === $project->id, 404);

        $wasActive = $rule->is_active;
        $rule->fill($request->ruleData())->save();
        $rule->setRelation('project', $project);

        // Si se activa al editarla, es como reactivarla: empieza a contar desde hoy.
        $created = $this->generator->generateFor($rule, LocalTime::today(), startFromToday: ! $wasActive);

        $this->toast($created !== null ? 'rule_updated_now' : 'rule_updated', $rule);

        return to_route('projects.settings', $project);
    }

    /**
     * Activar o desactivar. Reactivar exige que el proyecto no esté archivado y que su bolsa siga
     * abierta; después crea la tarea de hoy si toca (no recupera el tiempo que estuvo parada).
     */
    public function status(Request $request, Project $project, RecurringTaskRule $rule): RedirectResponse
    {
        abort_unless($rule->project_id === $project->id, 404);
        Gate::authorize('update', $rule);

        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        if ($active) {
            $this->assertCanRun($project, $rule);
        }

        $rule->forceFill(['is_active' => $active])->save();
        $rule->setRelation('project', $project);

        if (! $active) {
            $this->toast('rule_deactivated', $rule);

            return to_route('projects.settings', $project);
        }

        $created = $this->generator->generateFor($rule, LocalTime::today());
        $this->toast($created !== null ? 'rule_activated_now' : 'rule_activated', $rule);

        return to_route('projects.settings', $project);
    }

    public function destroy(Project $project, RecurringTaskRule $rule): RedirectResponse
    {
        abort_unless($rule->project_id === $project->id, 404);
        Gate::authorize('delete', $rule);

        $rule->delete();

        $this->toast('rule_deleted', $rule);

        return to_route('projects.settings', $project);
    }

    /**
     * @throws ValidationException
     */
    private function assertCanRun(Project $project, RecurringTaskRule $rule): void
    {
        if ($project->status === ProjectStatus::Archived) {
            throw ValidationException::withMessages(['is_active' => __('templates.errors.rule_project_archived')]);
        }

        if ($rule->hour_bank_id !== null) {
            $bank = HourBank::query()->withTrashed()->find($rule->hour_bank_id);

            if ($bank === null || $bank->trashed() || ! $bank->acceptsTime()) {
                throw ValidationException::withMessages(['is_active' => __('templates.errors.rule_bank_closed', ['bank' => $bank->name ?? ''])]);
            }
        } elseif ($project->usesHourBanks()) {
            throw ValidationException::withMessages(['is_active' => __('templates.errors.rule_bank_required')]);
        }
    }

    private function toast(string $key, RecurringTaskRule $rule): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __("templates.flash.{$key}", ['title' => $rule->title])]);
    }
}
