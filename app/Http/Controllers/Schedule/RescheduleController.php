<?php

namespace App\Http\Controllers\Schedule;

use App\Domain\Schedule\ScheduleConflicts;
use App\Domain\Schedule\ScheduleShifter;
use App\Domain\Tasks\TaskWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\RescheduleTaskRequest;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Mover o redimensionar una tarea con sucesoras (SPEC §6.1, D-057) en dos pasos:
 * 1. preview (JSON): qué sucesoras entrarían en conflicto y a qué fechas se propone llevarlas;
 * 2. store: guarda las fechas nuevas y, SOLO si se confirma (shift_successors), desplaza las
 *    sucesoras. La propuesta se recalcula en el servidor: nunca se aceptan fechas del cliente
 *    para las sucesoras.
 */
class RescheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleConflicts $conflicts,
        private readonly ScheduleShifter $shifter,
        private readonly TaskWriter $writer,
    ) {}

    public function preview(RescheduleTaskRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('update', $task);

        return response()->json([
            'proposals' => $this->proposals($request, $task),
        ]);
    }

    public function store(RescheduleTaskRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        /** @var User $user */
        $user = $request->user();

        $shifted = DB::transaction(function () use ($request, $task, $user): int {
            $proposals = $request->boolean('shift_successors') ? $this->proposals($request, $task) : [];

            $this->writer->update($user, $task, [
                'start_date' => $request->startDate(),
                'due_date' => $request->dueDate(),
            ]);

            return $proposals === [] ? 0 : $this->shifter->apply($user, $task->project_id, $proposals);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $shifted === 0
            ? __('schedule.flash.rescheduled')
            : trans_choice('schedule.flash.rescheduled_with_successors', $shifted, ['count' => $shifted])]);

        return back();
    }

    /**
     * @return list<array{task_id: int, title: string, start_date: string|null, due_date: string|null,
     *     new_start_date: string|null, new_due_date: string|null, shift_days: int, predecessor_id: int}>
     */
    private function proposals(RescheduleTaskRequest $request, Task $task): array
    {
        $start = $request->startDate();
        $due = $request->dueDate();

        return $this->conflicts->proposeShift(
            $task,
            $start === null ? null : CarbonImmutable::parse($start),
            $due === null ? null : CarbonImmutable::parse($due),
        );
    }
}
