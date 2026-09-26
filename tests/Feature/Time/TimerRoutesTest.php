<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
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
use Illuminate\Support\Facades\Event;

/*
| Rutas del temporizador (SPEC §7, D-035, D-036): POST /temporizador, POST /temporizador/parar y
| DELETE /temporizador. "Hoy" es el jueves 24/09/2026 en Madrid.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'Europe/Madrid'));

    $this->user = User::factory()->employee()->create();
    $project = Project::factory()->create();
    $project->addMember($this->user);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Maquetar la home']);
    $this->other = Task::factory()->create(['project_id' => $project->id, 'title' => 'Revisar textos']);

    $this->blockTask = function (int $hours = 1): Task {
        $bank = HourBank::factory()->hours($hours)->blockOverage()->create();
        $bank->project->addMember($this->user);

        return Task::factory()->inBank($bank)->create(['title' => 'Soporte']);
    };
});

it('inicia el temporizador y lo avisa', function () {
    $this->actingAs($this->user)
        ->from('/mis-tareas')
        ->post('/temporizador', ['task_id' => $this->task->id, 'description' => 'Cabecera'])
        ->assertRedirect('/mis-tareas')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Temporizador en marcha en «Maquetar la home».');

    $timer = ActiveTimer::query()->sole();
    expect($timer->user_id)->toBe($this->user->id)
        ->and($timer->task_id)->toBe($this->task->id)
        ->and($timer->description)->toBe('Cabecera');
});

it('iniciar otro imputa el anterior y lo cuenta en el aviso', function () {
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:45:00', 'Europe/Madrid'));

    $this->actingAs($this->user)
        ->post('/temporizador', ['task_id' => $this->other->id])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Temporizador en marcha en «Revisar textos». Se han imputado 0:45 en «Maquetar la home».');

    expect(TimeEntry::query()->sole()->minutes)->toBe(45)
        ->and(ActiveTimer::query()->sole()->task_id)->toBe($this->other->id);
});

it('para e imputa lo medido', function () {
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:30:00', 'Europe/Madrid'));

    $this->actingAs($this->user)
        ->post('/temporizador/parar')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Temporizador parado: 1:30 imputadas en «Maquetar la home».');

    $entry = TimeEntry::query()->sole();
    expect($entry->minutes)->toBe(90)
        ->and($entry->date->toDateString())->toBe('2026-09-24')
        ->and(ActiveTimer::query()->count())->toBe(0);
});

it('al parar con otra duración u otra tarea imputa lo indicado (acepta «1:15»)', function () {
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 11:00:00', 'Europe/Madrid'));

    $this->actingAs($this->user)
        ->post('/temporizador/parar', ['minutes' => '1:15', 'task_id' => $this->other->id])
        ->assertSessionHasNoErrors();

    $entry = TimeEntry::query()->sole();
    expect($entry->minutes)->toBe(75)
        ->and($entry->task_id)->toBe($this->other->id);
});

it('al parar solo con otra tarea (sin duración) reparte lo medido por días y lo redondea (D-036)', function () {
    Setting::set('timer_rounding_minutes', 15);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 23:10:00', 'Europe/Madrid'));
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 01:23:00', 'Europe/Madrid'));

    $this->actingAs($this->user)
        ->post('/temporizador/parar', ['minutes' => null, 'task_id' => $this->other->id])
        ->assertSessionHasNoErrors();

    $entries = TimeEntry::query()->orderBy('date')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('task_id')->unique()->all())->toBe([$this->other->id])
        ->and($entries->map(fn (TimeEntry $entry): array => [$entry->date->toDateString(), $entry->minutes])->all())
        ->toBe([['2026-09-23', 45], ['2026-09-24', 90]]);
});

it('con una bolsa block sin saldo, parar devuelve el error y el temporizador sigue en marcha', function () {
    $task = ($this->blockTask)(1);
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $task->id])->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:30:00', 'Europe/Madrid'));

    $this->actingAs($this->user)
        ->post('/temporizador/parar', [], ['X-Inertia-Error-Bag' => 'timer'])
        ->assertSessionHasErrors(['minutes' => 'La bolsa «'.$task->hourBank->name.'» no admite exceso. Saldo disponible: 1:00.']);

    expect(ActiveTimer::query()->count())->toBe(1)
        ->and(TimeEntry::query()->count())->toBe(0);

    // Ajustando la duración al saldo, sí se imputa.
    $this->actingAs($this->user)
        ->post('/temporizador/parar', ['minutes' => 60])
        ->assertSessionHasNoErrors();

    expect(ActiveTimer::query()->count())->toBe(0)
        ->and(TimeEntry::query()->sole()->minutes)->toBe(60);
});

it('no inicia en una bolsa block agotada ni sin ser miembro: error de validación, no 403', function () {
    $task = ($this->blockTask)(1);
    TimeEntry::factory()->forTask($task)->minutes(60)->on('2026-09-01')->create();

    $this->actingAs($this->user)
        ->post('/temporizador', ['task_id' => $task->id])
        ->assertSessionHasErrors('task_id');

    $stranger = Task::factory()->create();
    $this->actingAs($this->user)
        ->post('/temporizador', ['task_id' => $stranger->id])
        ->assertSessionHasErrors(['task_id' => 'Solo los miembros del proyecto pueden imputar horas.']);

    expect(ActiveTimer::query()->count())->toBe(0);
});

it('parar en una semana ya enviada da un error explicado', function () {
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);
    TimesheetPeriod::factory()->for($this->user)->week('2026-09-24')->status(TimesheetStatus::Submitted)->create();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));

    $this->actingAs($this->user)
        ->post('/temporizador/parar')
        ->assertSessionHasErrors(['date' => 'La semana del 21/09/2026 está enviada. Hay que reabrirla para cambiar sus horas.']);

    expect(ActiveTimer::query()->count())->toBe(1);
});

it('avisa sin bloquear cuando la imputación va como exceso de la bolsa', function () {
    $bank = HourBank::factory()->hours(1)->allowOverage()->create();
    $bank->project->addMember($this->user);
    $task = Task::factory()->inBank($bank)->create();

    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $task->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:30:00', 'Europe/Madrid'));

    $this->actingAs($this->user)
        ->post('/temporizador/parar')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('time_warnings.0.code', 'overage')
        ->assertInertiaFlash('time_warnings.0.message', '0:30 de esta entrada se registrarán como exceso: la bolsa se agota.');

    expect(TimeEntry::query()->sole()->overage_minutes)->toBe(30);
});

it('si el redondeo deja 0 minutos, no imputa y lo avisa', function () {
    Setting::set('timer_rounding_minutes', 15);
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:05:00', 'Europe/Madrid'));

    $this->actingAs($this->user)
        ->post('/temporizador/parar')
        ->assertInertiaFlash('toast.type', 'warning');

    expect(TimeEntry::query()->count())->toBe(0)
        ->and(ActiveTimer::query()->count())->toBe(0);
});

it('descarta sin imputar; parar sin temporizador da un error comprensible', function () {
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);

    $this->actingAs($this->user)
        ->delete('/temporizador')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Temporizador descartado. No se ha imputado nada.');

    expect(ActiveTimer::query()->count())->toBe(0)
        ->and(TimeEntry::query()->count())->toBe(0);

    $this->actingAs($this->user)
        ->post('/temporizador/parar')
        ->assertSessionHasErrors(['timer' => 'No tienes ningún temporizador en marcha.']);
});

it('valida la petición: tarea obligatoria y duración válida', function () {
    $this->actingAs($this->user)->post('/temporizador', [])->assertSessionHasErrors('task_id');
    $this->actingAs($this->user)->post('/temporizador', ['task_id' => 999999])->assertSessionHasErrors('task_id');

    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);
    $this->actingAs($this->user)->post('/temporizador/parar', ['minutes' => 'mucho'])->assertSessionHasErrors('minutes');
    $this->actingAs($this->user)->post('/temporizador/parar', ['minutes' => 24 * 60 + 1])->assertSessionHasErrors('minutes');
});

it('el temporizador de cada uno es suyo: descartar no toca el de otra persona', function () {
    $colleague = User::factory()->employee()->create();
    $this->task->project->addMember($colleague);

    $this->actingAs($this->user)->post('/temporizador', ['task_id' => $this->task->id]);
    $this->actingAs($colleague)->delete('/temporizador');

    expect(ActiveTimer::query()->sole()->user_id)->toBe($this->user->id);
});

it('invitados al login y clientes a su portal', function (string $method, string $url) {
    $this->{$method}($url, ['task_id' => $this->task->id])->assertRedirect(route('login'));

    $this->actingAs(userWithRole('client'))->{$method}($url, ['task_id' => $this->task->id])->assertRedirect(route('portal.home'));

    expect(ActiveTimer::query()->count())->toBe(0);
})->with([
    'iniciar' => ['post', '/temporizador'],
    'parar' => ['post', '/temporizador/parar'],
    'descartar' => ['delete', '/temporizador'],
]);
