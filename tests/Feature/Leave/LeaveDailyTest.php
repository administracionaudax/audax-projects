<?php

use App\Models\Absence;
use App\Models\AbsenceReminder;
use App\Models\LeaveMovement;
use App\Notifications\Absences\AbsenceDocumentMissingNotification;
use App\Notifications\Absences\LeaveExpiringNotification;
use Illuminate\Support\Facades\Notification;

/*
| La tarea diaria de R3 (`people:leave-daily`, D-362 y D-369): asigna el año en curso y el siguiente,
| avisa una vez de lo que caduca en 30 días y una vez del justificante que falta.
*/

beforeEach(function () {
    enablePeople();
    Notification::fake();
    $this->employee = userWithRole('employee', ['name' => 'Elena']);
});

it('asigna el año en curso y el siguiente, sin repetir', function () {
    $this->travelTo(madridAt('2026-10-07 07:30'));
    $this->artisan('people:leave-daily')->assertSuccessful();
    $this->artisan('people:leave-daily')->assertSuccessful();

    expect(LeaveMovement::query()->where('user_id', $this->employee->id)->where('leave_type_id', leaveType('vacation')->id)->orderBy('year')->pluck('year')->all())->toBe([2026, 2027]);
});

it('avisa una vez de lo que caduca en 30 días sin gastar', function () {
    $this->travelTo(madridAt('2026-03-05 07:30'));
    leaveCredit($this->employee, 'vacation', 2025, 300, '2025-01-01', '2026-03-31');

    $this->artisan('people:leave-daily')->assertSuccessful();
    $this->artisan('people:leave-daily')->assertSuccessful();

    Notification::assertSentToTimes($this->employee, LeaveExpiringNotification::class, 1);
    Notification::assertSentTo($this->employee, LeaveExpiringNotification::class, fn ($n) => $n->title($this->employee) === 'Te caducan 3 días de «Vacaciones» el 31/03/2026');
});

it('avisa una vez del justificante que falta en una ausencia aprobada ya empezada', function () {
    $this->travelTo(madridAt('2026-10-07 07:30'));
    $missing = Absence::factory()->for($this->employee)->approved()->between('2026-10-05', '2026-10-05')->create(['leave_type_id' => leaveType('moving')->id, 'type' => 'leave']);
    Absence::factory()->for($this->employee)->approved()->between('2026-11-05', '2026-11-05')->create(['leave_type_id' => leaveType('moving')->id, 'type' => 'leave']);

    $this->artisan('people:leave-daily')->assertSuccessful();
    $this->artisan('people:leave-daily')->assertSuccessful();

    Notification::assertSentToTimes($this->employee, AbsenceDocumentMissingNotification::class, 1);
    expect(AbsenceReminder::query()->where('kind', 'document')->pluck('key')->all())->toBe(['absence:'.$missing->id]);
});
