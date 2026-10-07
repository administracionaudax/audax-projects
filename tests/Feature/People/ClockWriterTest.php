<?php

use App\Domain\People\ClockState;
use App\Domain\People\ClockWriter;
use App\Domain\People\RegisterHasher;
use App\Enums\ClockEventKind;
use App\Enums\ClockSource;
use App\Enums\ClockStatus;
use App\Enums\PauseType;
use App\Enums\Role;
use App\Enums\WorkMode;
use App\Models\ClockEvent;
use App\Models\EmploymentProfile;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/*
| ClockWriter (D-332 y D-333): el único que escribe fichajes, con la hora del servidor, la secuencia
| válida y la cadena de huellas.
*/

beforeEach(function () {
    $this->user = userWithRole('employee');
});

it('ficha con la hora del servidor, sin segundos fraccionarios, y guarda quién y desde dónde', function () {
    $this->travelTo(madridAt('2026-10-05 09:02:17.734'));

    $event = app(ClockWriter::class)->punch($this->user, ClockEventKind::ClockIn, WorkMode::Remote, ClockSource::Pwa, '10.1.2.3', 'Mozilla/5.0 (iPhone)');

    expect($event->occurred_at->toIso8601ZuluString())->toBe('2026-10-05T07:02:17Z')
        ->and($event->recorded_at->equalTo($event->occurred_at))->toBeTrue()
        ->and($event->work_mode)->toBe(WorkMode::Remote)
        ->and($event->source)->toBe(ClockSource::Pwa)
        ->and($event->created_by)->toBe($this->user->id)
        ->and($event->ip_hash)->toBe(RegisterHasher::ipHash('10.1.2.3'))
        ->and($event->ip_hash)->not->toContain('10.1.2.3')
        ->and($event->user_agent)->toBe('Mozilla/5.0 (iPhone)');
});

it('no acepta la hora del navegador: la ruta ignora cualquier instante que se le mande', function () {
    enablePeople();
    $this->travelTo(madridAt('2026-10-05 09:00'));

    $this->actingAs($this->user)
        ->post('/fichar', ['kind' => 'clock_in', 'occurred_at' => '2026-10-05T05:00:00Z', 'work_mode' => 'on_site'])
        ->assertRedirect();

    expect(ClockEvent::query()->sole()->occurred_at->toIso8601ZuluString())->toBe('2026-10-05T07:00:00Z');
});

it('sigue la secuencia: entrada, pausa de comida, vuelta y salida', function () {
    $in = punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn, WorkMode::OnSite);
    expect(ClockState::of($this->user)->status)->toBe(ClockStatus::Working);

    $pause = punchAt($this->user, '2026-10-05 14:00', ClockEventKind::PauseStart);
    expect($pause->pause_type)->toBe(PauseType::Meal)
        ->and($pause->work_mode)->toBeNull()
        ->and(ClockState::of($this->user)->status)->toBe(ClockStatus::Paused);

    $back = punchAt($this->user, '2026-10-05 15:00', ClockEventKind::PauseEnd, WorkMode::Remote);
    expect($back->work_mode)->toBe(WorkMode::Remote)
        ->and(ClockState::of($this->user)->status)->toBe(ClockStatus::Working);

    punchAt($this->user, '2026-10-05 18:00', ClockEventKind::ClockOut);
    $state = ClockState::of($this->user);

    expect($state->status)->toBe(ClockStatus::Closed)
        ->and($state->workedTodaySeconds())->toBe(8 * 3600)
        ->and($in->seq)->toBe(1);
});

it('rechaza los fichajes fuera de orden', function (array $before, ClockEventKind $kind) {
    $minute = 0;
    foreach ($before as $previous) {
        punchAt($this->user, '2026-10-05 09:'.str_pad((string) $minute++, 2, '0', STR_PAD_LEFT), $previous);
    }

    expect(fn () => punchAt($this->user, '2026-10-05 10:00', $kind))->toThrow(ValidationException::class);
})->with([
    'dos entradas seguidas' => [[ClockEventKind::ClockIn], ClockEventKind::ClockIn],
    'pausa sin entrada' => [[], ClockEventKind::PauseStart],
    'vuelta sin pausa' => [[ClockEventKind::ClockIn], ClockEventKind::PauseEnd],
    'salida sin entrada' => [[], ClockEventKind::ClockOut],
    'dos pausas seguidas' => [[ClockEventKind::ClockIn, ClockEventKind::PauseStart], ClockEventKind::PauseStart],
    'entrada en la pausa' => [[ClockEventKind::ClockIn, ClockEventKind::PauseStart], ClockEventKind::ClockIn],
    'pausa tras la salida' => [[ClockEventKind::ClockIn, ClockEventKind::ClockOut], ClockEventKind::PauseStart],
]);

it('deja salir desde la pausa (queda la incidencia) y volver a entrar el mismo día', function () {
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);
    punchAt($this->user, '2026-10-05 13:00', ClockEventKind::PauseStart);
    punchAt($this->user, '2026-10-05 13:30', ClockEventKind::ClockOut);
    punchAt($this->user, '2026-10-05 16:00', ClockEventKind::ClockIn);
    punchAt($this->user, '2026-10-05 18:00', ClockEventKind::ClockOut);

    expect(ClockState::of($this->user)->workedTodaySeconds())->toBe(6 * 3600)
        ->and(ClockEvent::query()->count())->toBe(5);
});

it('una jornada sin salida deja de estar en curso a las 16 horas: se puede entrar al día siguiente y nunca se cierra sola', function () {
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);

    $this->travelTo(madridAt('2026-10-06 00:30'));
    expect(ClockState::of($this->user)->status)->toBe(ClockStatus::Working);

    $this->travelTo(madridAt('2026-10-06 08:55'));
    $state = ClockState::of($this->user);
    expect($state->status)->toBe(ClockStatus::Off)
        ->and($state->unclosedDate)->toBe('2026-10-05');

    punchAt($this->user, '2026-10-06 09:00', ClockEventKind::ClockIn);

    expect(ClockEvent::query()->where('kind', ClockEventKind::ClockOut->value)->count())->toBe(0)
        ->and(ClockState::of($this->user)->status)->toBe(ClockStatus::Working);
});

it('propone el último modo usado al fichar sin modo', function () {
    workday($this->user, '2026-10-05', '09:00', '17:00', mode: WorkMode::Remote);

    $event = punchAt($this->user, '2026-10-06 09:00', ClockEventKind::ClockIn);

    expect($event->work_mode)->toBe(WorkMode::Remote);
});

it('encadena las huellas de cada persona por separado', function () {
    $other = userWithRole('employee');

    $first = punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);
    $alien = punchAt($other, '2026-10-05 09:01', ClockEventKind::ClockIn);
    $second = punchAt($this->user, '2026-10-05 17:00', ClockEventKind::ClockOut);

    expect($first->prev_hash)->toBe(RegisterHasher::GENESIS)
        ->and($first->seq)->toBe(1)
        ->and($alien->seq)->toBe(1)
        ->and($alien->prev_hash)->toBe(RegisterHasher::GENESIS)
        ->and($second->seq)->toBe(2)
        ->and($second->prev_hash)->toBe($first->hash)
        ->and($second->hash)->toBe(app(RegisterHasher::class)->event(ClockEvent::query()->findOrFail($second->id)))
        ->and(strlen($second->hash))->toBe(64);
});

it('no deja fichar a quien no está sujeto al registro, a un colaborador externo ni a un cliente', function (Closure $make) {
    $user = $make();

    expect(fn () => app(ClockWriter::class)->punch($user, ClockEventKind::ClockIn))->toThrow(ValidationException::class);
})->with([
    'exento' => [function (): User {
        $user = userWithRole('employee');
        EmploymentProfile::query()->create(['user_id' => $user->id, 'subject_to_register' => false, 'register_exemption_reason' => 'Socio no asalariado']);

        return $user;
    }],
    'colaborador' => [fn (): User => User::factory()->withRole(Role::Collaborator)->create()],
    'cliente' => [fn (): User => userWithRole('client')],
]);

it('no admite escribir anulaciones ni correcciones por la vía de fichar', function () {
    expect(fn () => app(ClockWriter::class)->punch($this->user, ClockEventKind::Void))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ClockWriter::class)->punch($this->user, ClockEventKind::ClockIn, null, ClockSource::Correction))->toThrow(InvalidArgumentException::class);
});

it('nunca deja un fichaje antes del último efectivo (reloj que retrocede)', function () {
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);

    expect(fn () => punchAt($this->user, '2026-10-05 08:00', ClockEventKind::ClockOut))->toThrow(ValidationException::class);
});
