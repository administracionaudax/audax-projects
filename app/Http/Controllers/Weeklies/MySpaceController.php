<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Resources\Weeklies\WeeklyCycleResource;
use App\Http\Resources\Weeklies\WeeklySubmissionResource;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi espacio» (F-041 a F-063): pestañas Reportes (mi weekly de la semana y mis envíos) y Tareas.
 * Esqueleto del contrato 10.1: la semana activa y mi weekly de esa semana; lo completan 10.2
 * (reportes) y 10.6 (tareas).
 */
class MySpaceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('use-weeklies');

        $cycle = WeeklyCycle::query()->active()->first();
        $submission = $cycle === null ? null : WeeklySubmission::query()
            ->with('entries.client')
            ->where('weekly_cycle_id', $cycle->id)
            ->where('user_id', $request->user()?->id)
            ->first();

        return Inertia::render('my-space/index', [
            'cycle' => $cycle === null ? null : WeeklyCycleResource::make($cycle),
            'submission' => $submission === null ? null : WeeklySubmissionResource::make($submission),
        ]);
    }
}
