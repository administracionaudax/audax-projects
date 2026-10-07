<?php

use App\Domain\People\ClockReminders;
use App\Enums\ClockEventKind;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\ClockEvent;
use App\Models\EmploymentProfile;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\People\ClockInReminder;
use App\Notifications\People\ClockOutReminder;
use App\Notifications\People\WorkdayUnclosedReminder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/*
| Avisos del registro (D-339; W-110 a W-112): solo recuerdan, una vez por persona, día y tipo, y
| nunca fichan ni cierran nada. Con el módulo apagado de verdad no sale ninguno.
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-09-01');
    Notification::fake();

    $this->user = userWithRole('employee');
    WorkSchedule::factory()->for($this->user)->create(['start_time_from' => '08:00', 'start_time_to' => '09:30', 'expected_pause_minutes' => 60]);
});

function remindAt(string $local): int
{
    test()->travelTo(madrid($local));

    return app(ClockReminders::class)->sendDue();
}

it('recuerda la entrada pasado el margen + 15 minutos, una sola vez', function () {
    expect(remindAt('2026-10-05 09:40'))->toBe(0);
    Notification::assertNothingSent();

    expect(remindAt('2026-10-05 09:45'))->toBe(1)
        ->and(remindAt('2026-10-05 10:30'))->toBe(0);

    Notification::assertSentToTimes($this->user, ClockInReminder::class, 1);
    expect(ClockEvent::query()->count())->toBe(0);
});

it('sin margen en la jornada, a las 10:15; nunca si ya ha fichado, en fin de semana, festivo o ausencia', function () {
    $other = userWithRole('employee');
    $off = userWithRole('employee');
    Absence::factory()->approved()->for($off)->between('2026-10-05', '2026-10-05')->create();
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);

    expect(remindAt('2026-10-05 10:14'))->toBe(0)
        ->and(remindAt('2026-10-05 10:15'))->toBe(1);

    // El sábado no hay aviso de entrada (sí el de la jornada del viernes sin fichajes).
    remindAt('2026-10-10 11:00');

    Notification::assertSentToTimes($other, ClockInReminder::class, 1);
    Notification::assertNotSentTo([$this->user, $off], ClockInReminder::class);
});

it('recuerda la salida 30 minutos después de la prevista (entrada + jornada + pausa)', function () {
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);
    punchAt($this->user, '2026-10-05 14:00', ClockEventKind::PauseStart);
    punchAt($this->user, '2026-10-05 15:30', ClockEventKind::PauseEnd);

    // 9:00 + 8 h + 1:30 de pausa (la hecha, mayor que la prevista) + 30 min = 19:00.
    expect(remindAt('2026-10-05 18:59'))->toBe(0)
        ->and(remindAt('2026-10-05 19:00'))->toBe(1)
        ->and(remindAt('2026-10-05 20:00'))->toBe(0);

    Notification::assertSentToTimes($this->user, ClockOutReminder::class, 1);
    expect(ClockEvent::query()->where('kind', 'clock_out')->count())->toBe(0);
});

it('a la mañana siguiente, la jornada sin cerrar o sin fichajes', function () {
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);

    expect(remindAt('2026-10-06 07:59'))->toBe(0)
        ->and(remindAt('2026-10-06 08:00'))->toBeGreaterThanOrEqual(1);

    Notification::assertSentTo($this->user, WorkdayUnclosedReminder::class, fn (WorkdayUnclosedReminder $notification): bool => $notification->date === '2026-10-05');
});

it('no avisa a quien no ficha: exentos, colaboradores, desactivados ni clientes', function () {
    $exempt = userWithRole('employee');
    EmploymentProfile::query()->create(['user_id' => $exempt->id, 'subject_to_register' => false, 'register_exemption_reason' => 'Socio']);
    $collaborator = User::factory()->withRole(Role::Collaborator)->create();
    $inactive = userWithRole('employee', ['is_active' => false]);
    $client = userWithRole('client');

    remindAt('2026-10-05 10:30');

    Notification::assertNotSentTo([$exempt, $collaborator, $inactive, $client], ClockInReminder::class);
});

it('con el módulo apagado, aunque esté el modo de prueba, no sale nada', function () {
    Setting::set('modules', [...(array) Setting::get('modules'), 'people' => false]);
    Setting::set('modules_preview', true);

    expect(remindAt('2026-10-05 10:30'))->toBe(0);
    Notification::assertNothingSent();
});

it('no avisa por un canal que la persona ha desactivado', function () {
    $this->user->forceFill(['notification_preferences' => ['events' => ['people.clock_in_missing' => ['app' => false, 'push' => false]]]])->save();

    expect(remindAt('2026-10-05 10:00'))->toBe(0);
});

it('people:remind y people:expire-corrections están programados', function () {
    expect(Artisan::call('people:remind'))->toBe(0)
        ->and(Artisan::call('people:expire-corrections'))->toBe(0);

    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');

    expect($events)->toContain('people:remind')->toContain('people:expire-corrections');
});
