<?php

use App\Domain\Time\TimeEntryImport;
use App\Domain\Time\TimeEntryWriter;
use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/*
| Modo de importación de TimeEntryWriter (D-136): sin las validaciones de quien imputa a mano,
| con las invariantes de la entrada, sin auditoría por fila y sin recalcular la bolsa en cada una.
*/

function importEntry(Task $task, int $userId, int $minutes, string $date = '2026-09-01', ?TimeEntry $existing = null, TimeEntryStatus $status = TimeEntryStatus::Draft): TimeEntry
{
    $task->load('project');

    return app(TimeEntryWriter::class)->import(new TimeEntryImport(
        userId: $userId,
        task: $task,
        date: CarbonImmutable::parse($date),
        minutes: $minutes,
        status: $status,
    ), $existing);
}

test('importa sin las validaciones manuales: fuera del proyecto, semana cerrada, fecha futura y bolsa block', function () {
    $user = userWithRole('employee');
    $bank = HourBank::factory()->hours(1)->blockOverage()->create();
    $task = Task::factory()->inBank($bank)->create();
    TimesheetPeriod::forUserOn($user, '2026-09-01')->fill(['status' => 'approved'])->save();
    $activity = Activity::query()->count();

    $entry = importEntry($task, $user->id, 120, '2026-09-01');
    $future = importEntry($task, $user->id, 30, now()->addMonth()->toDateString());

    expect($entry->exists)->toBeTrue()
        ->and($entry->project_id)->toBe($task->project_id)
        ->and($entry->hour_bank_id)->toBe($bank->id)
        ->and($future->exists)->toBeTrue();

    // Ni auditoría por fila ni recálculo por entrada: el consumo lo pone el importador al final.
    expect(Activity::query()->count())->toBe($activity)
        ->and($bank->refresh()->consumed_minutes)->toBe(0);
});

test('mantiene las invariantes: de 1 min a 24 h y nunca facturable en un proyecto interno', function () {
    $user = userWithRole('employee');
    $task = Task::factory()->create(['project_id' => Project::factory()->internal()->create()->id, 'is_billable' => true]);

    expect(importEntry($task, $user->id, 60)->is_billable)->toBeFalse();
    expect(fn () => importEntry($task, $user->id, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => importEntry($task, $user->id, 24 * 60 + 1))->toThrow(InvalidArgumentException::class);
});

test('nunca modifica una entrada bloqueada', function () {
    $user = userWithRole('employee');
    $task = Task::factory()->create();
    $entry = importEntry($task, $user->id, 60, status: TimeEntryStatus::Locked);

    $again = importEntry($task, $user->id, 90, '2026-09-02', $entry);

    expect($again->minutes)->toBe(60)
        ->and($entry->refresh())->minutes->toBe(60)->date->toDateString()->toBe('2026-09-01');
});
