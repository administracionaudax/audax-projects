<?php

use App\Domain\Weeklies\WeeklyEligibility;
use App\Enums\AbsenceType;
use App\Enums\WeeklyExemptionReason;
use App\Models\Absence;
use App\Models\Client;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Illuminate\Support\Facades\DB;

/*
| Quién debe enviar la weekly (D-147, D-150; F-071, F-092, F-097 y F-098). Semana del 05/10/2026,
| plazo el viernes 09/10.
*/

beforeEach(function () {
    $this->eligibility = new WeeklyEligibility;
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->created = ['created_at' => '2026-09-01 08:00:00'];
});

it('participan los internos activos de plantilla; ni colaboradores, ni clientes, ni desactivados', function () {
    $admin = userWithRole('admin', $this->created);
    $manager = userWithRole('department_manager', $this->created);
    $employee = userWithRole('employee', $this->created);
    User::factory()->collaborator()->create($this->created);
    User::factory()->portalOf(Client::factory()->create())->create($this->created);
    userWithRole('employee', [...$this->created, 'is_active' => false]);

    $roster = $this->eligibility->rosterFor($this->cycle);

    expect($roster->participants())->toBe([$admin->id, $manager->id, $employee->id])
        ->and($roster->expected())->toBe([$admin->id, $manager->id, $employee->id])
        ->and($roster->exemptions())->toBe([]);
});

it('quien se da de alta después del final del viernes (hora de Madrid) no cuenta', function () {
    $inTime = userWithRole('employee', ['created_at' => '2026-10-09 21:59:00']);
    userWithRole('employee', ['created_at' => '2026-10-09 22:00:01']);

    expect($this->eligibility->rosterFor($this->cycle)->participants())->toBe([$inTime->id]);
});

it('exime una ausencia aprobada de día completo que cubre el día del plazo', function () {
    $covered = userWithRole('employee', $this->created);
    $endsThursday = userWithRole('employee', $this->created);
    $requested = userWithRole('employee', $this->created);
    $partial = userWithRole('employee', $this->created);
    $startsFriday = userWithRole('employee', $this->created);

    $absence = Absence::factory()->approved()->between('2026-10-05', '2026-10-16')->create(['user_id' => $covered->id, 'type' => AbsenceType::Sick]);
    Absence::factory()->approved()->between('2026-10-05', '2026-10-08')->create(['user_id' => $endsThursday->id]);
    Absence::factory()->between('2026-10-05', '2026-10-09')->create(['user_id' => $requested->id]);
    Absence::factory()->approved()->between('2026-10-09', '2026-10-09')->create(['user_id' => $partial->id, 'partial_minutes' => 120]);
    Absence::factory()->approved()->between('2026-10-09', '2026-10-09')->create(['user_id' => $startsFriday->id]);

    $roster = $this->eligibility->rosterFor($this->cycle);

    expect($roster->exemptions())->toBe([
        $covered->id => WeeklyExemptionReason::Absence,
        $startsFriday->id => WeeklyExemptionReason::Absence,
    ])
        ->and($roster->absenceFor($covered->id))->toBe($absence->id)
        ->and($roster->expected())->toBe([$endsThursday->id, $requested->id, $partial->id])
        ->and($roster->participates($covered->id))->toBeTrue()
        ->and($roster->mustSubmit($covered->id))->toBeFalse();
});

it('con el plazo ampliado, la ausencia tiene que cubrir el nuevo día', function () {
    $user = userWithRole('employee', $this->created);
    Absence::factory()->approved()->between('2026-10-05', '2026-10-09')->create(['user_id' => $user->id]);

    $this->cycle->update(['deadline_date' => '2026-10-12']);

    expect($this->eligibility->rosterFor($this->cycle->fresh())->isExempt($user->id))->toBeFalse();
});

it('una exención manual exime y una renuncia anula la exención por ausencia', function () {
    $manual = userWithRole('employee', $this->created);
    $waived = userWithRole('employee', $this->created);
    Absence::factory()->approved()->between('2026-10-01', '2026-10-20')->create(['user_id' => $waived->id]);

    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $manual->id]);
    WeeklyExemption::factory()->waived()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $waived->id]);

    $roster = $this->eligibility->rosterFor($this->cycle);

    expect($roster->reasonFor($manual->id))->toBe(WeeklyExemptionReason::Manual)
        ->and($roster->isExempt($waived->id))->toBeFalse()
        ->and($roster->expected())->toBe([$waived->id]);
});

it('al cerrar congela los exentos por ausencia y quién debía enviar (F-092)', function () {
    $sick = userWithRole('employee', $this->created);
    $manual = userWithRole('employee', $this->created);
    $writer = userWithRole('employee', $this->created);
    $absence = Absence::factory()->approved()->between('2026-10-08', '2026-10-12')->create(['user_id' => $sick->id]);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $manual->id]);

    $this->eligibility->freeze($this->cycle);
    $this->cycle->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    // Después del cierre la ausencia se cancela, la persona se desactiva y llega alguien nuevo: la
    // foto no cambia.
    $absence->update(['status' => 'cancelled']);
    $writer->update(['is_active' => false]);
    userWithRole('employee', $this->created);

    $roster = $this->eligibility->rosterFor($this->cycle->fresh());

    expect($this->cycle->fresh()->expected_user_ids)->toBe([$writer->id])
        ->and($roster->exemptions())->toBe([$sick->id => WeeklyExemptionReason::Absence, $manual->id => WeeklyExemptionReason::Manual])
        ->and($roster->absenceFor($sick->id))->toBe($absence->id)
        ->and($roster->expected())->toBe([$writer->id])
        ->and($roster->participants())->toBe([$sick->id, $manual->id, $writer->id]);

    // Congelar dos veces no duplica filas.
    expect(WeeklyExemption::query()->where('weekly_cycle_id', $this->cycle->id)->count())->toBe(2);
});

it('una semana cerrada sin foto (importada) se reconstruye con quien envió y los exentos guardados', function () {
    $closed = WeeklyCycle::factory()->forWeekOf('2026-09-07')->create(['expected_user_ids' => null]);
    $sender = userWithRole('employee', [...$this->created, 'is_active' => false]);
    $exempt = userWithRole('employee', $this->created);
    $active = userWithRole('employee', $this->created);
    WeeklySubmission::factory()->submitted('2026-09-11 10:00:00')->create(['weekly_cycle_id' => $closed->id, 'user_id' => $sender->id]);
    WeeklyExemption::factory()->absence()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $exempt->id]);
    WeeklyExemption::factory()->waived()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $active->id]);

    $roster = $this->eligibility->rosterFor($closed);

    expect($roster->expected())->toBe([$sender->id, $active->id])
        ->and($roster->exemptions())->toBe([$exempt->id => WeeklyExemptionReason::Absence]);
});

it('la regla pura resuelve sin base de datos', function () {
    $roster = WeeklyEligibility::resolve(
        candidates: [1, 2, 3, 4],
        coveringAbsences: [2 => 20, 3 => 30, 9 => 90],
        rows: [
            3 => ['reason' => WeeklyExemptionReason::Waived, 'absence_id' => null],
            4 => ['reason' => WeeklyExemptionReason::Manual, 'absence_id' => null],
            8 => ['reason' => WeeklyExemptionReason::Manual, 'absence_id' => null],
        ],
    );

    expect($roster->participants())->toBe([1, 2, 3, 4])
        ->and($roster->exemptions())->toBe([2 => WeeklyExemptionReason::Absence, 4 => WeeklyExemptionReason::Manual])
        ->and($roster->expected())->toBe([1, 3]);
});

it('calcula el roster con tres consultas', function () {
    foreach (range(1, 5) as $i) {
        $user = userWithRole('employee', $this->created);
        Absence::factory()->approved()->between('2026-10-09', '2026-10-09')->create(['user_id' => $user->id]);
    }

    DB::enableQueryLog();
    $this->eligibility->rosterFor($this->cycle);

    expect(DB::getQueryLog())->toHaveCount(3);
});
