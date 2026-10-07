<?php

use App\Domain\Absences\AbsenceCost;
use App\Domain\Absences\LeaveBalances;
use App\Domain\Absences\LeaveCalendar;
use App\Domain\Absences\LeaveFormat;
use App\Domain\Absences\LeaveLedger;
use App\Domain\People\RegisterImmutable;
use App\Domain\Time\Capacity;
use App\Enums\AbsenceStatus;
use App\Enums\LeaveCalendarDayKind;
use App\Enums\LeaveMovementKind;
use App\Enums\LeaveUnit;
use App\Models\Absence;
use App\Models\EmploymentProfile;
use App\Models\Holiday;
use App\Models\LeaveCalendarDay;
use App\Models\LeaveMovement;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Saldos de vacaciones y permisos (Fase 11, R3; PLAN-FASE-11 §7.6; W-060 a W-064; D-362 y D-363):
| asignación anual proporcional al alta, a la baja y al tiempo parcial, arrastre con caducidad,
| orden de consumo, ajustes con motivo, saldo inicial de Woffu y el libro de solo alta sellado.
*/

beforeEach(function () {
    enablePeople();
    $this->travelTo(madridAt('2026-10-07 10:00'));
    $this->ledger = app(LeaveLedger::class);
    $this->balances = app(LeaveBalances::class);
    $this->vacation = leaveType('vacation');
    $this->employee = userWithRole('employee', ['name' => 'Elena']);
    $this->hr = hrUser(['name' => 'Toni']);
});

function vacationSummary(User $user, int $year): array
{
    return collect(app(LeaveBalances::class)->forUser($user, $year))->firstWhere('type.key', 'vacation');
}

describe('asignación anual', function () {
    it('da 22 días laborables a quien está todo el año a jornada completa, con el arrastre hasta el 31/03', function () {
        $movement = $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);

        expect($movement->kind)->toBe(LeaveMovementKind::Accrual)
            ->and($movement->amount)->toBe(2200)
            ->and($movement->valid_from->toDateString())->toBe('2026-01-01')
            ->and($movement->expires_on?->toDateString())->toBe('2027-03-31')
            ->and($movement->reason)->toBe('Asignación anual de 2026: 22 días.');

        // Otra vez, nada: ya está.
        expect($this->ledger->syncAccrual($this->employee, $this->vacation, 2026))->toBeNull();
    });

    it('es proporcional al alta a mitad de año: cada fracción de mes cuenta como un mes', function () {
        EmploymentProfile::query()->create(['user_id' => $this->employee->id, 'hire_date' => '2026-07-15']);

        // De julio a diciembre: 6 meses → 22 × 6 / 12 = 11 días.
        expect($this->ledger->syncAccrual($this->employee, $this->vacation, 2026)->amount)->toBe(1100);
    });

    it('recalcula con un movimiento nuevo al poner la baja, sin tocar el anterior', function () {
        $first = $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);
        EmploymentProfile::query()->create(['user_id' => $this->employee->id, 'termination_date' => '2026-03-10']);

        // Enero, febrero y marzo: 22 × 3 / 12 = 5,5 días; la diferencia, −16,5.
        $delta = $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);

        expect($delta->amount)->toBe(-1650)
            ->and($delta->reason)->toContain('Recálculo')
            ->and($first->fresh()->amount)->toBe(2200)
            ->and(LeaveMovement::query()->where('user_id', $this->employee->id)->sum('amount'))->toBe(550);
    });

    it('redondea hacia arriba al medio día', function () {
        EmploymentProfile::query()->create(['user_id' => $this->employee->id, 'hire_date' => '2026-02-20']);

        // 11 meses: 22 × 11 / 12 = 20,17 → 20,5.
        expect($this->ledger->syncAccrual($this->employee, $this->vacation, 2026)->amount)->toBe(2050);
    });

    it('a tiempo parcial con menos días a la semana es proporcional a los días; con menos horas al día, igual', function () {
        WorkSchedule::factory()->for($this->employee)->create(['wed_minutes' => 0, 'fri_minutes' => 0]);
        $horizontal = userWithRole('employee');
        WorkSchedule::factory()->for($horizontal)->create(['mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);

        // 3 días a la semana: 22 × 3/5 = 13,2 → 13,5. Cinco mañanas: 22.
        expect($this->ledger->syncAccrual($this->employee, $this->vacation, 2026)->amount)->toBe(1350)
            ->and($this->ledger->syncAccrual($horizontal, $this->vacation, 2026)->amount)->toBe(2200);
    });

    it('la fuerza mayor da las horas de 4 días de la jornada de cada persona', function () {
        $part = userWithRole('employee');
        WorkSchedule::factory()->for($part)->create(['mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);

        expect($this->ledger->syncAccrual($this->employee, leaveType('force_majeure'), 2026)->amount)->toBe(4 * 480)
            ->and($this->ledger->syncAccrual($part, leaveType('force_majeure'), 2026)->amount)->toBe(4 * 240);
    });

    it('no asigna a colaboradores externos ni a clientes, ni los años anteriores al inicio de los saldos', function () {
        expect($this->ledger->syncAccrual(userWithRole('collaborator'), $this->vacation, 2026))->toBeNull()
            ->and($this->ledger->syncAccrual(userWithRole('client'), $this->vacation, 2026))->toBeNull();

        // El corte con Woffu el 01/11/2026: 2026 viene en el saldo inicial; 2027 ya se asigna.
        Setting::set('people_leave_starts_on', '2026-11-01');
        expect($this->ledger->syncAccrual($this->employee, $this->vacation, 2026))->toBeNull()
            ->and($this->ledger->syncAccrual($this->employee, $this->vacation, 2027)?->amount)->toBe(2200);
    });

    it('syncYear asigna a toda la plantilla (también a quien se fue con una baja dentro del año)', function () {
        $gone = userWithRole('employee', ['is_active' => false]);
        EmploymentProfile::query()->create(['user_id' => $gone->id, 'termination_date' => '2026-05-31']);
        $longGone = userWithRole('employee', ['is_active' => false]);

        $this->ledger->syncYear(2026);

        expect(LeaveMovement::query()->where('user_id', $gone->id)->where('leave_type_id', $this->vacation->id)->value('amount'))->toBe(950) // 5 meses: 9,17 → 9,5
            ->and(LeaveMovement::query()->where('user_id', $longGone->id)->exists())->toBeFalse()
            ->and(LeaveMovement::query()->where('user_id', $this->employee->id)->where('leave_type_id', $this->vacation->id)->value('amount'))->toBe(2200);
    });
});

describe('saldo y orden de consumo', function () {
    it('gasta primero lo arrastrado del año anterior, que caduca el 31/03, y después lo del año', function () {
        $this->ledger->syncAccrual($this->employee, $this->vacation, 2025);
        $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);
        // En 2025 disfrutó 20 de 22: le quedan 2 hasta el 31/03/2026.
        Absence::factory()->for($this->employee)->approved()->between('2025-08-04', '2025-08-29')->create();

        // Del lunes 30/03 al viernes 03/04/2026 (Viernes Santo, festivo).
        Holiday::factory()->create(['date' => '2026-04-03', 'name' => 'Viernes Santo']);
        Absence::factory()->for($this->employee)->approved()->between('2026-03-30', '2026-04-03')->create();

        $summary = vacationSummary($this->employee, 2026);
        $lots = collect($summary['lots'])->keyBy('year');

        expect($lots[2025]['used'])->toBe(2200)->and($lots[2025]['remaining'])->toBe(0)
            ->and($lots[2026]['used'])->toBe(200)
            ->and($summary['used'])->toBe(400)
            ->and($summary['available'])->toBe(2000)
            ->and($summary['carried'])->toBe([]);
    });

    it('lo arrastrado que no se gasta caduca y deja de estar disponible', function () {
        $this->ledger->syncAccrual($this->employee, $this->vacation, 2025);
        $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);
        Absence::factory()->for($this->employee)->approved()->between('2025-08-04', '2025-08-29')->create();

        $this->travelTo(madridAt('2026-02-02 10:00'));
        $before = vacationSummary($this->employee, 2026);
        expect($before['carried'])->toBe([['year' => 2025, 'remaining' => 200, 'expires_on' => '2026-03-31', 'expired' => false]])
            ->and($before['available'])->toBe(2400)
            ->and($before['expiring'])->toBe([]);

        $this->travelTo(madridAt('2026-04-01 10:00'));
        $after = vacationSummary($this->employee, 2026);
        expect($after['carried'][0]['expired'])->toBeTrue()
            ->and($after['available'])->toBe(2200);
    });

    it('lo pendiente de aprobar reserva saldo y lo que no cabe queda al descubierto', function () {
        $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);
        Absence::factory()->for($this->employee)->approved()->between('2026-07-06', '2026-07-24')->create(); // 15 días
        Absence::factory()->for($this->employee)->between('2026-08-03', '2026-08-14')->create(); // 10 pendientes

        $summary = vacationSummary($this->employee, 2026);

        expect($summary['used'])->toBe(1500)
            ->and($summary['pending'])->toBe(1000)
            ->and($summary['available'])->toBe(-300)
            ->and(array_sum(array_column($summary['uncovered'], 'amount')))->toBe(300);
    });

    it('un día de media jornada cuesta medio día de vacaciones y la mitad de la jornada', function () {
        LeaveCalendarDay::query()->create(['kind' => LeaveCalendarDayKind::HalfDay, 'name' => 'Nochebuena (media jornada)', 'start_date' => '2026-12-24', 'end_date' => '2026-12-24']);
        LeaveCalendar::forget();

        $days = app(AbsenceCost::class)->days($this->employee->id, $this->vacation, '2026-12-22', '2026-12-24', null);

        expect($days)->toBe(['2026-12-22' => 100, '2026-12-23' => 100, '2026-12-24' => 50])
            ->and(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-12-24')))->toBe(240);
    });

    it('cuenta los días laborables, los naturales o las horas según el tipo', function () {
        $cost = app(AbsenceCost::class);
        // Del viernes 09/10 al martes 13/10/2026; el 12, festivo.
        Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional']);

        expect(array_sum($cost->days($this->employee->id, $this->vacation, '2026-10-09', '2026-10-13', null)))->toBe(200)
            ->and(array_sum($cost->days($this->employee->id, leaveType('marriage'), '2026-10-09', '2026-10-13', null)))->toBe(500)
            ->and(array_sum($cost->days($this->employee->id, leaveType('force_majeure'), '2026-10-09', '2026-10-13', null)))->toBe(960)
            ->and($cost->days($this->employee->id, leaveType('force_majeure'), '2026-10-14', '2026-10-14', 90))->toBe(['2026-10-14' => 90])
            ->and($cost->days($this->employee->id, $this->vacation, '2026-10-14', '2026-10-14', 240))->toBe(['2026-10-14' => 50]);
    });

    it('las ausencias anteriores al inicio de los saldos no gastan: ya están en el saldo inicial', function () {
        Setting::set('people_leave_starts_on', '2026-11-01');
        leaveCredit($this->employee, 'vacation', 2026, 600, '2026-11-01', '2027-03-31');
        Absence::factory()->for($this->employee)->approved()->between('2026-08-03', '2026-08-14')->create();
        Absence::factory()->for($this->employee)->approved()->between('2026-11-02', '2026-11-03')->create();

        $summary = vacationSummary($this->employee, 2026);
        expect($summary['used'])->toBe(200)->and($summary['available'])->toBe(400);
    });
});

describe('movimientos a mano', function () {
    it('RR. HH. ajusta con motivo y queda en la auditoría; nadie más puede', function () {
        $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);
        $movement = $this->ledger->adjust($this->hr, $this->employee, $this->vacation, LeaveMovementKind::Adjustment, 150, 2026, 'Día de la empresa por el aniversario');

        expect($movement->created_by)->toBe($this->hr->id)
            ->and(vacationSummary($this->employee, 2026)['adjusted'])->toBe(150)
            ->and(Activity::query()->where('log_name', 'leave-balances')->where('event', 'leave_adjusted')->where('causer_id', $this->hr->id)->exists())->toBeTrue();

        $manager = userWithRole('department_manager');
        expect(fn () => $this->ledger->adjust($manager, $this->employee, $this->vacation, LeaveMovementKind::Adjustment, 100, 2026, 'Sin permiso'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->ledger->adjust($this->employee, $this->employee, $this->vacation, LeaveMovementKind::Adjustment, 100, 2026, 'A mí mismo'))->toThrow(AuthorizationException::class);
    });

    it('pide motivo, una cantidad distinta de cero y una caducidad posterior', function () {
        expect(fn () => $this->ledger->adjust($this->hr, $this->employee, $this->vacation, LeaveMovementKind::Adjustment, 0, 2026, 'no'))
            ->toThrow(fn (ValidationException $e) => expect(array_keys($e->errors()))->toEqualCanonicalizing(['reason', 'amount']));
        expect(fn () => $this->ledger->adjust($this->hr, $this->employee, $this->vacation, LeaveMovementKind::Adjustment, 100, 2026, 'Motivo válido', '2026-06-01', '2026-05-01'))
            ->toThrow(ValidationException::class);
    });

    it('arrastra a otra caducidad por una IT con un cargo y un abono, como mucho 18 meses tras el año', function () {
        $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);
        [$out, $in] = $this->ledger->carryOver($this->hr, $this->employee, $this->vacation, 2026, 500, '2027-09-30', 'Baja por IT de octubre a diciembre');

        expect($out->amount)->toBe(-500)->and($in->amount)->toBe(500)
            ->and($in->expires_on?->toDateString())->toBe('2027-09-30');

        $this->travelTo(madridAt('2027-05-03 10:00'));
        $this->ledger->syncAccrual($this->employee, $this->vacation, 2027);
        $summary = vacationSummary($this->employee, 2027);
        expect(collect($summary['carried'])->firstWhere('expires_on', '2027-09-30')['remaining'])->toBe(500);

        expect(fn () => $this->ledger->carryOver($this->hr, $this->employee, $this->vacation, 2026, 100, '2028-07-01', 'Demasiado tarde'))
            ->toThrow(ValidationException::class);
    });

    it('carga el saldo inicial de Woffu desde un CSV (en días y en horas) y avisa de las filas que no entiende', function () {
        $rows = [
            ['email' => $this->employee->email, 'type' => 'vacation', 'amount' => '12,5'],
            ['email' => $this->employee->email, 'type' => 'force_majeure', 'amount' => '16:00', 'expires_on' => '2026-12-31'],
            ['email' => 'nadie@example.com', 'type' => 'vacation', 'amount' => '3'],
            ['email' => $this->employee->email, 'type' => 'inventado', 'amount' => '3'],
            ['email' => $this->employee->email, 'type' => 'vacation', 'amount' => 'doce'],
        ];

        $dry = $this->ledger->importOpening($this->hr, $rows, '2026-11-01', dryRun: true);
        expect(array_column($dry, 'status'))->toBe(['ok', 'ok', 'error', 'error', 'error'])
            ->and(LeaveMovement::query()->count())->toBe(0);

        $this->ledger->importOpening($this->hr, $rows, '2026-11-01');
        $movements = LeaveMovement::query()->where('user_id', $this->employee->id)->orderBy('id')->get();

        expect($movements->pluck('amount')->all())->toBe([1250, 960])
            ->and($movements->pluck('kind')->map->value->unique()->all())->toBe(['opening_balance'])
            ->and($movements[0]->reason)->toBe('Saldo inicial desde Woffu a 01/11/2026.')
            ->and($movements[0]->valid_from->toDateString())->toBe('2026-11-01')
            ->and($movements[0]->expires_on?->toDateString())->toBe('2027-03-31');
    });
});

describe('libro de solo alta', function () {
    it('no deja cambiar ni borrar un movimiento, ni desde el modelo ni desde la base de datos', function () {
        $movement = $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);

        expect(fn () => $movement->forceFill(['amount' => 9900])->save())->toThrow(RegisterImmutable::class)
            ->and(fn () => $movement->delete())->toThrow(RegisterImmutable::class)
            ->and(fn () => DB::table('leave_movements')->where('id', $movement->id)->update(['amount' => 9900]))->toThrow(Exception::class)
            ->and(fn () => DB::table('leave_movements')->where('id', $movement->id)->delete())->toThrow(Exception::class)
            ->and($movement->fresh()->amount)->toBe(2200);
    });

    it('cada movimiento lleva su huella y verify() delata una fila cambiada quitando el trigger', function () {
        $movement = $this->ledger->syncAccrual($this->employee, $this->vacation, 2026);
        expect($this->ledger->verify())->toBe([]);

        DB::unprepared('DROP TRIGGER leave_movements_no_update');
        DB::table('leave_movements')->where('id', $movement->id)->update(['amount' => 9900]);

        expect($this->ledger->verify())->toBe([$movement->id]);
    })->skip(fn () => DB::getDriverName() !== 'sqlite', 'El trigger de PostgreSQL tiene otro nombre.');
});

it('escribe las cantidades como en Woffu y entiende lo que se escribe a mano', function () {
    expect(LeaveFormat::amount(2200, LeaveUnit::WorkingDays))->toBe('22 días')
        ->and(LeaveFormat::amount(100, LeaveUnit::WorkingDays))->toBe('1 día')
        ->and(LeaveFormat::amount(50, LeaveUnit::WorkingDays))->toBe('0,5 días')
        ->and(LeaveFormat::amount(1283, LeaveUnit::CalendarDays))->toBe('12,83 días')
        ->and(LeaveFormat::amount(960, LeaveUnit::Hours))->toBe('16:00 h')
        ->and(LeaveFormat::parse('12,5', LeaveUnit::WorkingDays))->toBe(1250)
        ->and(LeaveFormat::parse('-1', LeaveUnit::WorkingDays))->toBe(-100)
        ->and(LeaveFormat::parse('16:30', LeaveUnit::Hours))->toBe(990)
        ->and(LeaveFormat::parse('doce', LeaveUnit::WorkingDays))->toBeNull();
});

it('una ausencia rechazada o cancelada no gasta saldo', function () {
    app(LeaveLedger::class)->syncAccrual($this->employee, $this->vacation, 2026);
    Absence::factory()->for($this->employee)->between('2026-07-06', '2026-07-10')->create(['status' => AbsenceStatus::Rejected]);
    Absence::factory()->for($this->employee)->between('2026-07-13', '2026-07-17')->create(['status' => AbsenceStatus::Cancelled]);

    expect(vacationSummary($this->employee, 2026)['available'])->toBe(2200);
});
