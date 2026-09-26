<?php

namespace App\Http\Controllers\Time;

use App\Domain\Time\LoggablePeople;
use App\Http\Resources\UserSummaryResource;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /horas/opciones?project_id= → opciones del diálogo de imputación (SPEC §7):
 * - `people`: para quién puede imputar (la propia persona primero; si solo está ella, el diálogo
 *   no muestra el selector),
 * - `settings`: fecha de hoy en Madrid, si se admiten fechas futuras y si la descripción es
 *   obligatoria.
 */
class EntryOptionsController extends TimeController
{
    public function __construct(
        private readonly LoggablePeople $people,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => ['nullable', 'integer'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $project = $request->filled('project_id')
            ? Project::query()->withTrashed()->find($request->integer('project_id'))
            : null;

        return response()->json([
            'people' => UserSummaryResource::collection($this->people->for($actor, $project))->resolve($request),
            'settings' => [
                'today' => LocalTime::todayString(),
                'allow_future' => (bool) Setting::get('allow_future_time_entries', false),
                'description_required' => (bool) Setting::get('time_entry_description_required', false),
            ],
        ]);
    }
}
