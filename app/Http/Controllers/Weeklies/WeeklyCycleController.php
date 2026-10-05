<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Http\Resources\Weeklies\WeeklyCycleDetailResource;
use App\Http\Resources\Weeklies\WeeklyCycleResource;
use App\Models\WeeklyCycle;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Semanas de la weekly (F-064 a F-070 y F-089): histórico, informe de una semana, abrir, ampliar el
 * plazo, cerrar y borrar. Esqueleto del contrato 10.1; lo completan 10.2 (histórico, ciclo y plazo)
 * y 10.3 (informe y cierre).
 */
class WeeklyCycleController extends Controller
{
    use PendingDelivery;

    /** /weeklies: histórico con la semana activa destacada (F-065 y F-066). */
    public function index(): Response
    {
        Gate::authorize('viewAny', WeeklyCycle::class);

        $cycles = WeeklyCycle::query()->withCount(['submissions' => fn ($query) => $query->whereNotNull('submitted_at')])
            ->orderByDesc('start_date')
            ->limit(60)
            ->get();

        return Inertia::render('weeklies/index', [
            'cycles' => WeeklyCycleResource::collection($cycles),
            'can' => ['manage' => Gate::allows('manage-weeklies'), 'create' => Gate::allows('create', WeeklyCycle::class)],
        ]);
    }

    /** /weeklies/{cycle}: el informe de una semana (F-072 a F-091). */
    public function show(WeeklyCycle $cycle): Response
    {
        Gate::authorize('view', $cycle);

        return Inertia::render('weeklies/show', [
            'cycle' => WeeklyCycleDetailResource::make($cycle->load('audioSections')),
            'can' => [
                'generate' => Gate::allows('generate', $cycle),
                'extendDeadline' => Gate::allows('extendDeadline', $cycle),
                'close' => Gate::allows('close', $cycle),
                'delete' => Gate::allows('delete', $cycle),
            ],
        ]);
    }

    /** Abrir la semana en curso si no hay ninguna activa (F-040). */
    public function store(): never
    {
        Gate::authorize('create', WeeklyCycle::class);

        $this->pending('10.2');
    }

    /** Ampliar el plazo (F-068). */
    public function deadline(WeeklyCycle $cycle): never
    {
        Gate::authorize('extendDeadline', $cycle);

        $this->pending('10.2');
    }

    /** Cerrar con texto y audio: congela exentos, satisfacción y abre la siguiente (F-089 a F-094). */
    public function close(WeeklyCycle $cycle): never
    {
        Gate::authorize('close', $cycle);

        $this->pending('10.3');
    }

    /** Borrar (F-069): borra sus envíos; si era la última, abre la siguiente. */
    public function destroy(WeeklyCycle $cycle): never
    {
        Gate::authorize('delete', $cycle);

        $this->pending('10.2');
    }
}
