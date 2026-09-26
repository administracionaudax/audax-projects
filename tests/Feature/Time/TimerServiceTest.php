<?php

use App\Domain\Time\TimerService;
use App\Enums\TimesheetStatus;
use App\Models\ActiveTimer;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
| Temporizador (SPEC §7, D-035, D-036). Instantes en Europe/Madrid; se guardan en UTC.
*/

beforeEach(function () {
    $this->timers = app(TimerService::class);
    $this->user = User::factory()->employee()->create();
    $project = Project::factory()->create();
    $project->addMember($this->user);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Maquetar']);
    $this->other = Task::factory()->create(['project_id' => $project->id]);
});

function madrid(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Madrid');
}

it('imputa al parar, redondeando al minuto por defecto', function () {
    $this->travelTo(madrid('2026-09-24 09:00:00'));
    $this->timers->start($this->user, $this->task, 'Cabecera');

    $this->travelTo(madrid('2026-09-24 10:30:40'));
    $results = $this->timers->stop($this->user);

    expect($results)->toHaveCount(1)
        ->and($results[0]->entry->minutes)->toBe(91)
        ->and($results[0]->entry->date->toDateString())->toBe('2026-09-24')
        ->and($results[0]->entry->description)->toBe('Cabecera')
        ->and($results[0]->entry->started_at->toIso8601ZuluString())->toBe('2026-09-24T07:00:00Z')
        ->and(ActiveTimer::query()->count())->toBe(0);
});

it('redondea al múltiplo configurado y descarta si queda en 0', function () {
    Setting::set('timer_rounding_minutes', 15);

    $this->travelTo(madrid('2026-09-24 09:00:00'));
    $this->timers->start($this->user, $this->task);
    $this->travelTo(madrid('2026-09-24 09:53:00'));
    expect($this->timers->stop($this->user)[0]->entry->minutes)->toBe(60);

    $this->timers->start($this->user, $this->task);
    $this->travelTo(madrid('2026-09-24 09:59:00'));
    expect($this->timers->stop($this->user))->toBe([])
        ->and(TimeEntry::query()->count())->toBe(1)
        ->and(ActiveTimer::query()->count())->toBe(0);
});

it('parte en dos entradas si cruza la medianoche de Madrid', function () {
    Setting::set('allow_future_time_entries', false);
    $this->travelTo(madrid('2026-09-23 23:15:00'));
    $this->timers->start($this->user, $this->task);

    $this->travelTo(madrid('2026-09-24 01:05:00'));
    $results = $this->timers->stop($this->user);

    expect(collect($results)->map(fn ($r) => [$r->entry->date->toDateString(), $r->entry->minutes])->all())
        ->toBe([['2026-09-23', 45], ['2026-09-24', 65]]);
});

it('parte bien en la noche del cambio de hora (octubre)', function () {
    // 25/10/2026: a las 03:00 (verano) vuelven a ser las 02:00. El día 25 tiene 25 horas.
    $this->travelTo(madrid('2026-10-24 23:00:00'));
    $this->timers->start($this->user, $this->task);

    $this->travelTo(madrid('2026-10-25 04:00:00'));
    $results = $this->timers->stop($this->user);

    expect(collect($results)->map(fn ($r) => [$r->entry->date->toDateString(), $r->entry->minutes])->all())
        ->toBe([['2026-10-24', 60], ['2026-10-25', 300]]);
});

it('iniciar otro temporizador para el anterior y lo imputa', function () {
    $this->travelTo(madrid('2026-09-24 09:00:00'));
    $this->timers->start($this->user, $this->task);

    $this->travelTo(madrid('2026-09-24 09:40:00'));
    $previous = $this->timers->start($this->user, $this->other);

    expect($previous)->toHaveCount(1)
        ->and($previous[0]->entry->task_id)->toBe($this->task->id)
        ->and($previous[0]->entry->minutes)->toBe(40)
        ->and(ActiveTimer::query()->sole()->task_id)->toBe($this->other->id);
});

it('iniciar en la misma tarea no hace nada', function () {
    $this->travelTo(madrid('2026-09-24 09:00:00'));
    $this->timers->start($this->user, $this->task);
    $this->travelTo(madrid('2026-09-24 09:30:00'));

    expect($this->timers->start($this->user, $this->task))->toBe([])
        ->and(ActiveTimer::query()->sole()->started_at->toIso8601ZuluString())->toBe('2026-09-24T07:00:00Z');
});

it('no se inicia en una bolsa block sin saldo, ni en una semana enviada, ni sin ser miembro', function () {
    $this->travelTo(madrid('2026-09-24 09:00:00'));

    $bank = HourBank::factory()->hours(1)->blockOverage()->create();
    $bank->project->addMember($this->user);
    $bankTask = Task::factory()->inBank($bank)->create();
    TimeEntry::factory()->forTask($bankTask)->minutes(60)->on('2026-09-01')->create();

    expect(fn () => $this->timers->start($this->user, $bankTask))->toThrow(ValidationException::class, 'no tiene saldo');

    $stranger = Task::factory()->create();
    expect(fn () => $this->timers->start($this->user, $stranger))->toThrow(ValidationException::class);

    TimesheetPeriod::factory()->for($this->user)->week('2026-09-24')->status(TimesheetStatus::Submitted)->create();
    expect(fn () => $this->timers->start($this->user, $this->task))->toThrow(ValidationException::class);

    expect(ActiveTimer::query()->count())->toBe(0);
});

it('si la imputación falla al parar, el temporizador sigue en marcha y se puede parar con otra duración', function () {
    $this->travelTo(madrid('2026-09-24 09:00:00'));
    $bank = HourBank::factory()->hours(1)->blockOverage()->create();
    $bank->project->addMember($this->user);
    $bankTask = Task::factory()->inBank($bank)->create();
    $this->timers->start($this->user, $bankTask);

    $this->travelTo(madrid('2026-09-24 10:30:00'));
    expect(fn () => $this->timers->stop($this->user))->toThrow(ValidationException::class, 'Saldo disponible: 1:00');
    expect(ActiveTimer::query()->count())->toBe(1);

    $results = $this->timers->stop($this->user, minutes: 60);
    expect($results[0]->entry->minutes)->toBe(60)
        ->and(ActiveTimer::query()->count())->toBe(0);
});

it('parar sin temporizador da un error comprensible y descartar lo borra sin imputar', function () {
    expect(fn () => $this->timers->stop($this->user))->toThrow(ValidationException::class, 'ningún temporizador');

    $this->timers->start($this->user, $this->task);
    $this->timers->discard($this->user);

    expect(ActiveTimer::query()->count())->toBe(0)
        ->and(TimeEntry::query()->count())->toBe(0);
});
