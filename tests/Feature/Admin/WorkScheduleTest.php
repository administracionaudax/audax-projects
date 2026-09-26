<?php

use App\Domain\Time\Capacity;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/*
| Jornadas versionadas (SPEC §4.1, D-036): sin solapes; una versión nueva cierra la vigente el día
| anterior; solo se edita o borra la última si aún no ha empezado.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));
    $this->admin = userWithRole('admin');
    $this->user = userWithRole('employee');
    $this->current = WorkSchedule::factory()->create(['user_id' => $this->user->id, 'valid_from' => '2026-01-01']);
    $this->url = "/admin/usuarios/{$this->user->id}/jornadas";
    $this->intensive = [420, 420, 420, 420, 360, 0, 0];
});

test('una versión nueva cierra la vigente el día anterior y se usa para la capacidad', function () {
    $this->actingAs($this->admin)
        ->post($this->url, ['valid_from' => '2026-10-01', 'week' => $this->intensive])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect($this->current->fresh()?->valid_to?->toDateString())->toBe('2026-09-30');

    $new = WorkSchedule::query()->where('user_id', $this->user->id)->latest('valid_from')->firstOrFail();
    expect($new->valid_from->toDateString())->toBe('2026-10-01')
        ->and($new->valid_to)->toBeNull()
        ->and($new->weekMinutes())->toBe($this->intensive);

    $capacity = app(Capacity::class);
    expect($capacity->onDate($this->user, CarbonImmutable::parse('2026-09-30')))->toBe(480)
        ->and($capacity->onDate($this->user, CarbonImmutable::parse('2026-10-01')))->toBe(420) // jueves
        ->and($capacity->onDate($this->user, CarbonImmutable::parse('2026-10-02')))->toBe(360) // viernes
        ->and($capacity->onDate($this->user, CarbonImmutable::parse('2026-10-03')))->toBe(0); // sábado
});

test('una versión nueva empieza después de hoy y de la versión anterior: el histórico no se reescribe', function () {
    foreach (['2026-09-01', '2026-09-24'] as $startedOrPast) {
        $this->actingAs($this->admin)
            ->post($this->url, ['valid_from' => $startedOrPast, 'week' => $this->intensive])
            ->assertSessionHasErrors(['valid_from' => __('admin.schedules.new_must_be_future')]);
    }

    expect($this->current->fresh()?->valid_to)->toBeNull();

    $this->actingAs($this->admin)
        ->post($this->url, ['valid_from' => '2026-09-25', 'week' => $this->intensive])
        ->assertSessionHasNoErrors();

    expect($this->current->fresh()?->valid_to?->toDateString())->toBe('2026-09-24');

    foreach (['2026-09-25', '2026-09-20'] as $overlapping) {
        $this->actingAs($this->admin)
            ->post($this->url, ['valid_from' => $overlapping, 'week' => $this->intensive])
            ->assertSessionHasErrors('valid_from');
    }

    expect(WorkSchedule::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

test('la primera jornada de alguien sin jornada puede empezar hoy, pero no antes', function () {
    $newcomer = userWithRole('employee');
    $url = "/admin/usuarios/{$newcomer->id}/jornadas";

    $this->actingAs($this->admin)
        ->post($url, ['valid_from' => '2026-09-23', 'week' => $this->intensive])
        ->assertSessionHasErrors(['valid_from' => __('admin.schedules.first_not_past')]);

    $this->actingAs($this->admin)
        ->post($url, ['valid_from' => '2026-09-24', 'week' => $this->intensive])
        ->assertSessionHasNoErrors();

    expect(WorkSchedule::query()->where('user_id', $newcomer->id)->sole()->valid_from->toDateString())->toBe('2026-09-24');
});

test('valida la fecha y las horas de cada día (0 a 24 h, siete días)', function (array $payload, string $field) {
    $this->actingAs($this->admin)->post($this->url, $payload)->assertSessionHasErrors($field);

    expect(WorkSchedule::query()->count())->toBe(1);
})->with([
    'sin fecha' => [['week' => [480, 480, 480, 480, 480, 0, 0]], 'valid_from'],
    'fecha mal escrita' => [['valid_from' => '01/10/2026', 'week' => [480, 480, 480, 480, 480, 0, 0]], 'valid_from'],
    'seis días' => [['valid_from' => '2026-10-01', 'week' => [480, 480, 480, 480, 480, 0]], 'week'],
    'más de 24 h' => [['valid_from' => '2026-10-01', 'week' => [480, 480, 1500, 480, 480, 0, 0]], 'week.2'],
    'negativo' => [['valid_from' => '2026-10-01', 'week' => [-60, 480, 480, 480, 480, 0, 0]], 'week.0'],
]);

test('la última versión se edita mientras no haya empezado, y la anterior se ajusta', function () {
    $this->actingAs($this->admin)->post($this->url, ['valid_from' => '2026-10-01', 'week' => $this->intensive]);
    $next = WorkSchedule::query()->where('user_id', $this->user->id)->latest('valid_from')->firstOrFail();

    $this->actingAs($this->admin)
        ->put("{$this->url}/{$next->id}", ['valid_from' => '2026-10-15', 'week' => [480, 480, 480, 480, 240, 0, 0]])
        ->assertSessionHasNoErrors();

    expect($next->fresh()?->valid_from->toDateString())->toBe('2026-10-15')
        ->and($next->fresh()?->fri_minutes)->toBe(240)
        ->and($this->current->fresh()?->valid_to?->toDateString())->toBe('2026-10-14');

    // No se puede mover a hoy o antes.
    $this->actingAs($this->admin)
        ->put("{$this->url}/{$next->id}", ['valid_from' => '2026-09-24', 'week' => $this->intensive])
        ->assertSessionHasErrors('valid_from');
});

test('una versión que ya ha empezado, o que no es la última, no se edita ni se borra', function () {
    $this->actingAs($this->admin)
        ->put("{$this->url}/{$this->current->id}", ['valid_from' => '2026-12-01', 'week' => $this->intensive])
        ->assertSessionHasErrors('schedule');
    $this->actingAs($this->admin)->delete("{$this->url}/{$this->current->id}")->assertSessionHasErrors('schedule');

    $this->actingAs($this->admin)->post($this->url, ['valid_from' => '2026-10-01', 'week' => $this->intensive]);

    $this->actingAs($this->admin)
        ->put("{$this->url}/{$this->current->id}", ['valid_from' => '2026-12-01', 'week' => $this->intensive])
        ->assertSessionHasErrors('schedule');

    expect($this->current->fresh()?->valid_from->toDateString())->toBe('2026-01-01')
        ->and(WorkSchedule::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

test('borrar la próxima versión vuelve a dejar abierta la anterior', function () {
    $this->actingAs($this->admin)->post($this->url, ['valid_from' => '2026-10-01', 'week' => $this->intensive]);
    $next = WorkSchedule::query()->where('user_id', $this->user->id)->latest('valid_from')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete("{$this->url}/{$next->id}")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect(WorkSchedule::query()->whereKey($next->id)->exists())->toBeFalse()
        ->and($this->current->fresh()?->valid_to)->toBeNull();
});

test('la jornada de otra persona no se toca desde esta ficha', function () {
    $other = userWithRole('employee');
    $foreign = WorkSchedule::factory()->create(['user_id' => $other->id, 'valid_from' => '2026-12-01']);

    $this->actingAs($this->admin)->delete("{$this->url}/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->admin)
        ->put("{$this->url}/{$foreign->id}", ['valid_from' => '2026-12-15', 'week' => $this->intensive])
        ->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('la ficha marca como editable solo la última versión futura', function () {
    $this->actingAs($this->admin)->post($this->url, ['valid_from' => '2026-10-01', 'week' => $this->intensive]);

    $schedules = $this->actingAs($this->admin)->get("/admin/usuarios/{$this->user->id}")->inertiaProps('schedules');

    expect(collect($schedules)->map(fn (array $schedule) => [$schedule['valid_from'], $schedule['is_current'], $schedule['is_editable']])->all())
        ->toBe([
            ['2026-10-01', false, true],
            ['2026-01-01', true, false],
        ]);
});
