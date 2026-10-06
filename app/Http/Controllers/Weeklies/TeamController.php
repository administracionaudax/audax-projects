<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\Ai\AiDailyLimitReached;
use App\Domain\Weeklies\Insights\AiSummaries;
use App\Domain\Weeklies\Insights\PersonInsights;
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyStreaks;
use App\Enums\AiSummaryKind;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Department;
use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipo (F-134 a F-145, D-194): la lista de la plantilla con el estado de su weekly y la ficha de
 * cada persona (racha, hábitos, clientes, último reporte por cliente e historial por semanas). La ven
 * quienes usan la Weekly (D-021). Los resúmenes con IA de una persona (F-144 y F-145), solo el admin
 * y sus responsables (view-person-ai-summary, D-147), nunca la propia persona ni un compañero.
 *
 * El alta, la edición y la baja de personas siguen en /admin/usuarios (F-138 a F-141): la ficha
 * enlaza allí a quien puede gestionarlas.
 */
class TeamController extends Controller
{
    public function index(Request $request, PersonInsights $people): Response
    {
        Gate::authorize('use-weeklies');

        /** @var User $viewer */
        $viewer = $request->user();
        $directory = $people->directory($viewer);

        return Inertia::render('team/index', [
            ...$directory,
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->values()->all(),
            'clients' => Client::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'icon'])->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name, 'icon' => $client->icon])->values()->all(),
            'roles' => User::WEEKLY_ROLES,
            'can' => [
                'manageUsers' => $viewer->can('manage-users'),
                // «Recordar» a quien tiene pendiente la semana activa (10.5, F-110).
                'remind' => $directory['cycle'] !== null && $viewer->can('manage-weeklies'),
            ],
        ]);
    }

    public function show(Request $request, User $user, PersonInsights $people, WeeklyStreaks $streaks, MyWeeklyStatus $status, AiSummaries $summaries): Response
    {
        Gate::authorize('use-weeklies');
        abort_unless($user->writesWeeklies(), 404);

        /** @var User $viewer */
        $viewer = $request->user();
        $user->loadMissing(['department:id,name', 'roles:id,name']);
        $cycle = WeeklyCycle::query()->active()->first();
        $canAi = $viewer->can('view-person-ai-summary', $user);

        return Inertia::render('team/show', [
            ...$people->profile($user, $viewer),
            'cycle' => $cycle === null ? null : ['id' => $cycle->id, 'label' => $cycle->label, 'number' => $cycle->number],
            'status' => $cycle === null || ! $user->is_active ? null : $status->for($user, $cycle)['status'],
            'streak' => $streaks->summary($user),
            // Resúmenes con IA (F-144 y F-145): null para quien no los puede ver.
            'ai' => $canAi ? [
                'performance' => AiSummaries::present($summaries->find(AiSummaryKind::PersonPerformance, $user)),
                'client_activity' => AiSummaries::present($summaries->find(AiSummaryKind::PersonClientActivity, $user)),
            ] : null,
            'can' => [
                'viewAi' => $canAi,
                'manageUser' => $viewer->can('manage-users'),
                'remind' => $cycle !== null && $viewer->can('remind', $cycle),
                // «Estoy fuera» (D-228): la propia persona o quien gestiona la Weekly.
                'markAway' => $user->is_active && ($viewer->is($user) || $viewer->can('manage-weeklies')),
            ],
        ]);
    }

    /** Resumen de desempeño (tipo=desempeno) o actividad por cliente (tipo=clientes) con IA. */
    public function aiSummary(Request $request, User $user, AiSummaries $summaries): JsonResponse|RedirectResponse
    {
        Gate::authorize('view-person-ai-summary', $user);
        abort_unless($user->writesWeeklies(), 404);

        $kind = match ($request->validate(['tipo' => ['nullable', 'string', Rule::in(['desempeno', 'clientes'])]])['tipo'] ?? 'desempeno') {
            'clientes' => AiSummaryKind::PersonClientActivity,
            default => AiSummaryKind::PersonPerformance,
        };

        /** @var User $viewer */
        $viewer = $request->user();
        $busy = ($existing = $summaries->find($kind, $user)) !== null && AiSummaries::isBusy($existing);

        try {
            $summary = $summaries->request($kind, $user, $viewer);
        } catch (AiDailyLimitReached $e) {
            return ClientInsightsController::limitReached($request, $e);
        }

        return ClientInsightsController::respond($request, $summary, $busy);
    }
}
