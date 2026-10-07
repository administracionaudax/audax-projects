<?php

use App\Domain\Absences\ValenciaHolidays;
use App\Domain\Time\Capacity;
use App\Enums\HolidayLevel;
use App\Models\Holiday;
use Carbon\CarbonImmutable;

/*
| Calendario laboral de València (Fase 11, R3; L-23; D-367), comprobado en el BOE, el DOGV y
| valencia.es: 14 fiestas cada año, con su nivel y su fuente; el convenio de publicidad, aparte y
| pendiente de asesor.
*/

it('2026: las 12 fiestas de la Comunitat (Decreto 100/2025) y las 2 locales de València', function () {
    $days = collect(app(ValenciaHolidays::class)->forYear(2026))->keyBy('date');

    expect($days->keys()->all())->toBe([
        '2026-01-01', '2026-01-06', '2026-01-22', '2026-03-19', '2026-04-03', '2026-04-06', '2026-04-13',
        '2026-05-01', '2026-06-24', '2026-08-15', '2026-10-09', '2026-10-12', '2026-12-08', '2026-12-25',
    ])
        ->and($days['2026-01-22']['name'])->toBe('San Vicente Mártir')
        ->and($days['2026-01-22']['level'])->toBe('local')
        ->and($days['2026-04-13']['name'])->toBe('San Vicente Ferrer')
        ->and($days['2026-04-06']['level'])->toBe('regional')
        ->and($days['2026-03-19']['source'])->toContain('BOE-A-2025-21667')
        ->and($days['2026-10-09']['source'])->toContain('Decreto 100/2025')
        // En 2026 el 1 de noviembre y el 6 de diciembre caen en domingo y la Comunitat no los traslada.
        ->and($days->has('2026-11-02'))->toBeFalse()
        ->and($days->has('2026-12-07'))->toBeFalse();
});

it('2027: Decreto 42/2026 (sin San Juan) y los locales del Pleno, pendientes del DOGV', function () {
    $days = collect(app(ValenciaHolidays::class)->forYear(2027))->keyBy('date');

    expect($days)->toHaveCount(14)
        ->and($days->has('2027-06-24'))->toBeFalse()
        ->and($days->has('2027-08-15'))->toBeFalse()
        ->and($days['2027-03-29']['name'])->toBe('Lunes de Pascua')
        ->and($days['2027-04-05']['name'])->toBe('San Vicente Ferrer')
        ->and($days['2027-04-05']['pending'])->toBeTrue()
        ->and($days['2027-01-22']['pending'])->toBeTrue()
        ->and($days['2027-11-01']['pending'])->toBeFalse();
});

it('los días del convenio de publicidad: la fiesta profesional del primer viernes y el 24 y el 31 de diciembre', function () {
    expect(array_column(app(ValenciaHolidays::class)->agreement(2026), 'date'))->toBe(['2026-01-30', '2026-12-24', '2026-12-31'])
        ->and(array_column(app(ValenciaHolidays::class)->agreement(2027), 'date'))->toBe(['2027-01-29', '2027-12-24', '2027-12-31'])
        ->and(collect(app(ValenciaHolidays::class)->agreement(2026))->every(fn ($day) => $day['pending'] && $day['level'] === 'company'))->toBeTrue();
});

it('el admin añade el calendario de València sin duplicar y con su nivel y fuente; el convenio, solo si lo marca', function () {
    $admin = userWithRole('admin');
    Holiday::factory()->create(['date' => '2026-01-01', 'name' => 'Año Nuevo']);

    $this->actingAs($admin)->post('/admin/festivos/valencia', ['year' => 2026])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Se han añadido 13 festivos de València de 2026.');

    $sanVicente = Holiday::query()->whereDate('date', '2026-01-22')->sole();
    expect($sanVicente->level)->toBe(HolidayLevel::Local)
        ->and($sanVicente->source)->toContain('DOGV núm. 10238')
        ->and(Holiday::query()->whereDate('date', '2026-12-24')->exists())->toBeFalse()
        ->and(app(Capacity::class)->onDate($admin, CarbonImmutable::parse('2026-03-19')))->toBe(0);

    $this->actingAs($admin)->post('/admin/festivos/valencia', ['year' => 2026, 'agreement' => true])
        ->assertInertiaFlash('toast.message', 'Se han añadido 3 festivos de València de 2026.');
    expect(Holiday::query()->whereDate('date', '2026-12-24')->value('level'))->toBe(HolidayLevel::Company);
});

it('solo hay calendario comprobado de 2026 y 2027, y solo lo añade quien gestiona los ajustes', function () {
    $this->actingAs(userWithRole('admin'))->post('/admin/festivos/valencia', ['year' => 2028])->assertSessionHasErrors('year');
    $this->actingAs(userWithRole('employee'))->post('/admin/festivos/valencia', ['year' => 2026])->assertForbidden();
});
