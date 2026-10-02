<?php

use App\Domain\Absences\HolidayImporter;
use App\Domain\Absences\SpanishNationalHolidays;
use App\Domain\Reports\ReportCache;
use App\Domain\Time\Capacity;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Festivos (/admin/festivos, D-050): lista por año, crear, editar y borrar; festivos nacionales de
| España calculados en local (Viernes Santo por la Pascua) y la importación de un .ics o un CSV
| con vista previa y errores por línea. Solo con manage-settings. "Hoy" es el 24/09/2026.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));
    $this->admin = userWithRole('admin');
});

test('calcula los festivos nacionales con el Viernes Santo de cada año', function (int $year, string $goodFriday) {
    $holidays = app(SpanishNationalHolidays::class)->forYear($year);
    $dates = array_column($holidays, 'date');

    expect($holidays)->toHaveCount(10)
        ->and($dates)->toBe([
            "{$year}-01-01", "{$year}-01-06", $goodFriday, "{$year}-05-01", "{$year}-08-15",
            "{$year}-10-12", "{$year}-11-01", "{$year}-12-06", "{$year}-12-08", "{$year}-12-25",
        ])
        ->and($holidays[2]['name'])->toBe('Viernes Santo')
        ->and($holidays[0]['name'])->toBe('Año Nuevo')
        ->and($holidays[5]['name'])->toBe('Fiesta Nacional de España');
})->with([
    '2026' => [2026, '2026-04-03'],
    '2027' => [2027, '2027-03-26'],
    '2025' => [2025, '2025-04-18'],
    '2024' => [2024, '2024-03-29'],
]);

test('el domingo de Pascua sale del cálculo gregoriano', function (int $year, string $easter) {
    expect(SpanishNationalHolidays::easterSunday($year)->toDateString())->toBe($easter);
})->with([
    [2000, '2000-04-23'],
    [2019, '2019-04-21'],
    [2038, '2038-04-25'],
    [2100, '2100-03-28'],
]);

test('lista los festivos del año pedido con los nacionales que faltan', function () {
    Holiday::factory()->create(['date' => '2026-01-01', 'name' => 'Año Nuevo']);
    Holiday::factory()->create(['date' => '2026-09-08', 'name' => 'Día de Asturias']);
    Holiday::factory()->create(['date' => '2027-01-01', 'name' => 'Año Nuevo']);

    $this->actingAs($this->admin)
        ->get('/admin/festivos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/holidays/index')
            ->where('year', 2026)
            ->where('current_year', 2026)
            ->has('holidays', 2)
            ->where('holidays.0.date', '2026-01-01')
            ->where('holidays.1.name', 'Día de Asturias')
            ->has('national', 10)
            ->where('national.0.exists', true)
            ->where('national.2.date', '2026-04-03')
            ->where('national.2.exists', false)
            ->where('limits.max_rows', HolidayImporter::MAX_ROWS));

    $this->actingAs($this->admin)
        ->get('/admin/festivos?anio=2027')
        ->assertInertia(fn (Assert $page) => $page->where('year', 2027)->has('holidays', 1)->where('national.2.date', '2027-03-26'));

    // Un año fuera de rango vuelve al actual.
    $this->actingAs($this->admin)
        ->get('/admin/festivos?anio=1800')
        ->assertInertia(fn (Assert $page) => $page->where('year', 2026));
});

test('crea, edita y borra un festivo, y queda en la auditoría', function () {
    $this->actingAs($this->admin)
        ->post('/admin/festivos', ['date' => '2026-09-08', 'name' => '  Día   de Asturias '])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Festivo añadido: Día de Asturias (08/09/2026).');

    $holiday = Holiday::query()->sole();
    expect($holiday->name)->toBe('Día de Asturias')
        ->and($holiday->scope)->toBe('company');

    $this->actingAs($this->admin)
        ->put("/admin/festivos/{$holiday->id}", ['date' => '2026-09-09', 'name' => 'Fiesta local'])
        ->assertSessionHasNoErrors();

    expect($holiday->fresh()->date->toDateString())->toBe('2026-09-09')
        ->and($holiday->fresh()->name)->toBe('Fiesta local');

    $this->actingAs($this->admin)
        ->delete("/admin/festivos/{$holiday->id}")
        ->assertSessionHasNoErrors();

    expect(Holiday::query()->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'holidays')->orderBy('id')->pluck('event')->all())
        ->toBe(['holiday_created', 'holiday_updated', 'holiday_deleted']);
});

test('valida la fecha (única y en rango) y el nombre', function (array $data, string $field) {
    Holiday::factory()->create(['date' => '2026-12-25', 'name' => 'Navidad']);

    $this->actingAs($this->admin)
        ->post('/admin/festivos', $data)
        ->assertSessionHasErrors($field);

    expect(Holiday::query()->count())->toBe(1);
})->with([
    'fecha repetida' => [['date' => '2026-12-25', 'name' => 'Otra'], 'date'],
    'fecha mal escrita' => [['date' => '25/12/2026', 'name' => 'Otra'], 'date'],
    'fuera de rango' => [['date' => '1999-12-31', 'name' => 'Otra'], 'date'],
    'sin nombre' => [['date' => '2026-12-26', 'name' => '   '], 'name'],
    'nombre largo' => [['date' => '2026-12-26', 'name' => str_repeat('a', 101)], 'name'],
]);

test('al editar se puede conservar la misma fecha', function () {
    $holiday = Holiday::factory()->create(['date' => '2026-12-25', 'name' => 'Navidad']);

    $this->actingAs($this->admin)
        ->put("/admin/festivos/{$holiday->id}", ['date' => '2026-12-25', 'name' => 'Natividad del Señor'])
        ->assertSessionHasNoErrors();

    expect($holiday->fresh()->name)->toBe('Natividad del Señor');
});

test('añade los festivos nacionales del año sin duplicar los que ya estaban', function () {
    Holiday::factory()->create(['date' => '2026-01-01', 'name' => 'Año Nuevo (a mano)']);

    $this->actingAs($this->admin)
        ->post('/admin/festivos/nacionales', ['year' => 2026])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Se han añadido 9 festivos nacionales de 2026.');

    expect(Holiday::query()->count())->toBe(10)
        ->and(Holiday::query()->whereDate('date', '2026-01-01')->value('name'))->toBe('Año Nuevo (a mano)')
        ->and(Holiday::query()->whereDate('date', '2026-04-03')->value('name'))->toBe('Viernes Santo');

    $this->actingAs($this->admin)
        ->post('/admin/festivos/nacionales', ['year' => 2026])
        ->assertInertiaFlash('toast.message', 'Los festivos nacionales de 2026 ya estaban todos.');

    $this->actingAs($this->admin)->post('/admin/festivos/nacionales', ['year' => 2027]);

    expect(Holiday::query()->count())->toBe(20)
        ->and(Holiday::query()->whereDate('date', '2027-03-26')->value('name'))->toBe('Viernes Santo');

    $activity = Activity::query()->where('log_name', 'holidays')->where('event', 'holidays_national')->first();
    expect($activity?->properties['year'])->toBe(2026)
        ->and($activity?->properties['created'])->toBe(9);
});

test('el año de los festivos nacionales tiene que estar en rango', function () {
    $this->actingAs($this->admin)
        ->post('/admin/festivos/nacionales', ['year' => 1990])
        ->assertSessionHasErrors('year');

    expect(Holiday::query()->count())->toBe(0);
});

test('los festivos dejan la capacidad de ese día a 0 para todas las personas', function () {
    $user = User::factory()->employee()->create();
    $this->actingAs($this->admin)->post('/admin/festivos/nacionales', ['year' => 2026]);

    $capacity = app(Capacity::class)->forRange($user, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-13'));

    expect($capacity)->toBe(['2026-10-12' => 0, '2026-10-13' => 480]);
});

test('vista previa de un .ics: festivos nuevos, ya existentes, repetidos y con errores', function () {
    Holiday::factory()->create(['date' => '2026-09-08', 'name' => 'Día de Asturias']);

    $ics = implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'BEGIN:VEVENT',
        'DTSTART;VALUE=DATE:20260908',
        'SUMMARY:Día de Asturias',
        'END:VEVENT',
        'BEGIN:VEVENT',
        'DTSTART;VALUE=DATE:20260921',
        'SUMMARY:San Mateo\\, fiesta',
        '  local',
        'END:VEVENT',
        'BEGIN:VEVENT',
        'DTSTART:20261231T230000Z',
        'SUMMARY:Año nuevo en UTC',
        'END:VEVENT',
        'BEGIN:VEVENT',
        'DTSTART;VALUE=DATE:20260921',
        'SUMMARY:Repetido',
        'END:VEVENT',
        'BEGIN:VEVENT',
        'SUMMARY:Sin fecha',
        'END:VEVENT',
        'BEGIN:VEVENT',
        'DTSTART;VALUE=DATE:20260231',
        'SUMMARY:Fecha imposible',
        'END:VEVENT',
        'BEGIN:VEVENT',
        'DTSTART;VALUE=DATE:20261009',
        'END:VEVENT',
        'END:VCALENDAR',
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/festivos/importar/vista-previa', ['file' => UploadedFile::fake()->createWithContent('asturias.ics', $ics)])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('holiday_import.format', 'ics')
        ->assertInertiaFlash('holiday_import.file_name', 'asturias.ics')
        ->assertInertiaFlash('holiday_import.counts', ['new' => 2, 'existing' => 1, 'duplicate' => 1, 'error' => 3])
        ->assertInertiaFlash('holiday_import.rows.0.status', 'existing')
        ->assertInertiaFlash('holiday_import.rows.0.line', 3)
        ->assertInertiaFlash('holiday_import.rows.1.status', 'new')
        ->assertInertiaFlash('holiday_import.rows.1.name', 'San Mateo, fiesta local')
        ->assertInertiaFlash('holiday_import.rows.2.date', '2027-01-01')
        ->assertInertiaFlash('holiday_import.rows.3.status', 'duplicate')
        ->assertInertiaFlash('holiday_import.rows.4.status', 'error')
        ->assertInertiaFlash('holiday_import.rows.4.message', 'El evento no tiene fecha de inicio (DTSTART).')
        ->assertInertiaFlash('holiday_import.rows.5.message', 'La fecha «20260231» no es válida: usa AAAA-MM-DD o DD/MM/AAAA.')
        ->assertInertiaFlash('holiday_import.rows.6.message', 'El evento no tiene nombre (SUMMARY).');

    // La vista previa no guarda nada.
    expect(Holiday::query()->count())->toBe(1);
});

test('vista previa de un CSV con cabecera, DD/MM/AAAA, Windows-1252 y errores por línea', function () {
    $csv = mb_convert_encoding(implode("\n", [
        'fecha;nombre',
        '2026-03-19;San José',
        '',
        '# comentario',
        '12/10/2026;"Pilar; Zaragoza"',
        '2026-13-01;Mes imposible',
        ';Sin fecha',
        '2026-05-15',
        '1999-01-01;Fuera de rango',
    ]), 'Windows-1252', 'UTF-8');

    $this->actingAs($this->admin)
        ->post('/admin/festivos/importar/vista-previa', ['file' => UploadedFile::fake()->createWithContent('festivos.csv', $csv)])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('holiday_import.format', 'csv')
        ->assertInertiaFlash('holiday_import.counts', ['new' => 2, 'existing' => 0, 'duplicate' => 0, 'error' => 4])
        ->assertInertiaFlash('holiday_import.rows.0.line', 2)
        ->assertInertiaFlash('holiday_import.rows.0.name', 'San José')
        ->assertInertiaFlash('holiday_import.rows.1.date', '2026-10-12')
        ->assertInertiaFlash('holiday_import.rows.1.name', 'Pilar; Zaragoza')
        ->assertInertiaFlash('holiday_import.rows.2.line', 6)
        ->assertInertiaFlash('holiday_import.rows.2.message', 'La fecha «2026-13-01» no es válida: usa AAAA-MM-DD o DD/MM/AAAA.')
        ->assertInertiaFlash('holiday_import.rows.3.message', 'Falta la fecha.')
        ->assertInertiaFlash('holiday_import.rows.4.message', 'Falta el nombre del festivo.')
        ->assertInertiaFlash('holiday_import.rows.5.message', 'La fecha 01/01/1999 está fuera del rango admitido (2000 a 2100).');
});

test('rechaza ficheros vacíos, grandes, de otro tipo o con demasiadas líneas', function (Closure $file, string $message) {
    $this->actingAs($this->admin)
        ->post('/admin/festivos/importar/vista-previa', ['file' => $file()])
        ->assertSessionHasErrors(['file' => $message]);
})->with([
    'vacío' => [fn () => UploadedFile::fake()->createWithContent('vacio.csv', "  \n "), 'El fichero está vacío.'],
    'ics sin calendario' => [fn () => UploadedFile::fake()->createWithContent('malo.ics', 'hola'), 'No se reconoce el fichero: usa un calendario .ics o un CSV con líneas «AAAA-MM-DD;Nombre».'],
    'calendario sin eventos' => [fn () => UploadedFile::fake()->createWithContent('vacio.ics', "BEGIN:VCALENDAR\nEND:VCALENDAR"), 'El calendario no tiene ningún evento (VEVENT).'],
    'demasiadas líneas' => [fn () => UploadedFile::fake()->createWithContent('largo.csv', implode("\n", array_fill(0, HolidayImporter::MAX_ROWS + 1, '2026-01-01;X'))), 'El fichero tiene más de 500 líneas. Divídelo en varios.'],
    'otro tipo' => [fn () => UploadedFile::fake()->create('foto.png', 10, 'image/png'), 'El archivo fichero debe tener una de estas extensiones: ics, csv, txt.'],
    'demasiado grande' => [fn () => UploadedFile::fake()->create('grande.csv', HolidayImporter::MAX_KILOBYTES + 1, 'text/csv'), 'El archivo fichero no puede pesar más de 256 kilobytes.'],
]);

test('confirma la importación sin duplicar fechas y lo deja en la auditoría', function () {
    Holiday::factory()->create(['date' => '2026-09-08', 'name' => 'Día de Asturias']);

    $this->actingAs($this->admin)
        ->post('/admin/festivos/importar', ['rows' => [
            ['date' => '2026-09-08', 'name' => 'Otro nombre'],
            ['date' => '2026-09-21', 'name' => 'San Mateo'],
            ['date' => '2026-09-21', 'name' => 'Repetido'],
            ['date' => '2026-03-19', 'name' => ' San  José '],
        ]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Se han añadido 2 festivos. 1 fecha ya tenía festivo y se ha dejado como estaba.');

    expect(Holiday::query()->orderBy('date')->pluck('name')->all())->toBe(['San José', 'Día de Asturias', 'San Mateo']);

    $activity = Activity::query()->where('event', 'holidays_imported')->sole();
    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['dates'])->toBe(['2026-03-19', '2026-09-21']);
});

test('crear, editar, borrar, importar y añadir los nacionales invalida la caché de los informes (D-046)', function () {
    // La capacidad de los informes depende de los festivos; insertOrIgnore no dispara eventos.
    $bumped = function (Closure $action): bool {
        $before = ReportCache::version();
        $action();

        return ReportCache::version() > $before;
    };
    $this->actingAs($this->admin);

    expect($bumped(fn () => $this->post('/admin/festivos', ['date' => '2026-09-08', 'name' => 'Día de Asturias'])->assertSessionHasNoErrors()))->toBeTrue();
    $holiday = Holiday::query()->sole();

    expect($bumped(fn () => $this->put("/admin/festivos/{$holiday->id}", ['date' => '2026-09-09', 'name' => 'Fiesta local'])->assertSessionHasNoErrors()))->toBeTrue()
        ->and($bumped(fn () => $this->delete("/admin/festivos/{$holiday->id}")->assertSessionHasNoErrors()))->toBeTrue()
        ->and($bumped(fn () => $this->post('/admin/festivos/importar', ['rows' => [['date' => '2026-09-21', 'name' => 'San Mateo']]])->assertSessionHasNoErrors()))->toBeTrue()
        ->and($bumped(fn () => $this->post('/admin/festivos/nacionales', ['year' => 2027])->assertSessionHasNoErrors()))->toBeTrue();

    // Sin festivos nuevos no hay nada que invalidar.
    expect($bumped(fn () => $this->post('/admin/festivos/importar', ['rows' => [['date' => '2026-09-21', 'name' => 'San Mateo']]])->assertSessionHasNoErrors()))->toBeFalse()
        ->and($bumped(fn () => $this->post('/admin/festivos/nacionales', ['year' => 2027])->assertSessionHasNoErrors()))->toBeFalse();
});

test('la confirmación vuelve a validar cada fila', function () {
    $this->actingAs($this->admin)
        ->post('/admin/festivos/importar', ['rows' => [
            ['date' => '2026-02-30', 'name' => 'Imposible'],
            ['date' => '2026-03-01', 'name' => ''],
        ]])
        ->assertSessionHasErrors(['rows.0.date', 'rows.1.name']);

    $this->actingAs($this->admin)->post('/admin/festivos/importar', ['rows' => []])->assertSessionHasErrors('rows');

    expect(Holiday::query()->count())->toBe(0);
});

test('un .ics ignora las propiedades de sus alarmas (VALARM)', function () {
    $ics = implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'BEGIN:VEVENT',
        'DTSTART;VALUE=DATE:20260908',
        'SUMMARY:Día de Asturias',
        'BEGIN:VALARM',
        'ACTION:EMAIL',
        'SUMMARY:Recordatorio del festivo',
        'DESCRIPTION:Mañana es festivo',
        'TRIGGER:-P1D',
        'END:VALARM',
        'END:VEVENT',
        'BEGIN:VEVENT',
        'BEGIN:VALARM',
        'ACTION:DISPLAY',
        'SUMMARY:Solo en la alarma',
        'DTSTART:20300101T000000',
        'END:VALARM',
        'DTSTART;VALUE=DATE:20261009',
        'END:VEVENT',
        'END:VCALENDAR',
    ]);

    $preview = app(HolidayImporter::class)->preview($ics, 'ics', 2026);

    expect($preview['rows'])->toBe([
        ['line' => 3, 'date' => '2026-09-08', 'name' => 'Día de Asturias', 'status' => 'new', 'message' => null],
        ['line' => 13, 'date' => '2026-10-09', 'name' => null, 'status' => 'error', 'message' => 'El evento no tiene nombre (SUMMARY).'],
    ]);
});

test('un evento que se repite cada año (RRULE) se toma en el año elegido o explica por qué no', function () {
    $event = fn (string $name, string ...$lines): array => ['BEGIN:VEVENT', ...$lines, "SUMMARY:{$name}", 'END:VEVENT'];
    $ics = implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        ...$event('Año Nuevo', 'DTSTART;VALUE=DATE:20100101', 'RRULE:FREQ=YEARLY'),
        ...$event('Cada dos años', 'DTSTART;VALUE=DATE:20250315', 'RRULE:FREQ=YEARLY;INTERVAL=2;BYMONTH=3;BYMONTHDAY=15'),
        ...$event('Tres veces', 'DTSTART;VALUE=DATE:20100501', 'RRULE:FREQ=YEARLY;COUNT=3'),
        ...$event('Hasta 2020', 'DTSTART;VALUE=DATE:20100601', 'RRULE:FREQ=YEARLY;UNTIL=20201231T235959Z'),
        ...$event('Excluido', 'DTSTART;VALUE=DATE:20100724', 'RRULE:FREQ=YEARLY', 'EXDATE;VALUE=DATE:20260724,20270724'),
        ...$event('Primer lunes', 'DTSTART;VALUE=DATE:20100405', 'RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=1MO'),
        ...$event('Mensual', 'DTSTART;VALUE=DATE:20100110', 'RRULE:FREQ=MONTHLY'),
        ...$event('Con RDATE', 'DTSTART;VALUE=DATE:20260816', 'RDATE;VALUE=DATE:20270816'),
        ...$event('Bisiesto', 'DTSTART;VALUE=DATE:20240229', 'RRULE:FREQ=YEARLY'),
        ...$event('Futuro', 'DTSTART;VALUE=DATE:20300101', 'RRULE:FREQ=YEARLY'),
        ...$event('Nochebuena y Navidad', 'DTSTART;VALUE=DATE:20101224', 'DTEND;VALUE=DATE:20101226', 'RRULE:FREQ=YEARLY'),
        ...$event('Sin repetición', 'DTSTART;VALUE=DATE:20261225'),
        'END:VCALENDAR',
    ]);

    $preview = app(HolidayImporter::class)->preview($ics, 'ics', 2027);
    $rows = array_map(fn (array $row): array => [$row['date'], $row['status'], $row['message']], $preview['rows']);

    expect($rows)->toBe([
        ['2027-01-01', 'new', 'Se repite cada año desde el 01/01/2010: se toma su fecha de 2027.'],
        ['2027-03-15', 'new', 'Se repite cada año desde el 15/03/2025: se toma su fecha de 2027.'],
        ['2010-05-01', 'error', 'Se repite cada año desde el 01/05/2010, pero no cae en 2027.'],
        ['2010-06-01', 'error', 'Se repite cada año desde el 01/06/2010, pero no cae en 2027.'],
        ['2010-07-24', 'error', 'Se repite cada año desde el 24/07/2010, pero no cae en 2027.'],
        ['2010-04-05', 'error', 'Se repite de una forma que no se puede importar (FREQ=YEARLY;BYMONTH=4;BYDAY=1MO). Añádelo a mano.'],
        ['2010-01-10', 'error', 'Se repite de una forma que no se puede importar (FREQ=MONTHLY). Añádelo a mano.'],
        ['2026-08-16', 'error', 'Se repite de una forma que no se puede importar (RDATE). Añádelo a mano.'],
        ['2024-02-29', 'error', 'Se repite cada año desde el 29/02/2024, pero no cae en 2027.'],
        ['2030-01-01', 'error', 'Se repite cada año desde el 01/01/2030, pero no cae en 2027.'],
        ['2027-12-24', 'new', 'Se repite cada año desde el 24/12/2010: se toma su fecha de 2027. Evento de 2 días (del 24/12/2027 al 25/12/2027): se añade un festivo por día.'],
        ['2027-12-25', 'new', 'Se repite cada año desde el 24/12/2010: se toma su fecha de 2027. Evento de 2 días (del 24/12/2027 al 25/12/2027): se añade un festivo por día.'],
        ['2026-12-25', 'new', null],
    ])->and($preview['counts'])->toBe(['new' => 5, 'existing' => 0, 'duplicate' => 0, 'error' => 8]);
});

test('la vista previa toma los festivos que se repiten en el año de la página o en el actual', function () {
    $ics = implode("\r\n", ['BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'DTSTART;VALUE=DATE:20100101', 'RRULE:FREQ=YEARLY', 'SUMMARY:Año Nuevo', 'END:VEVENT', 'END:VCALENDAR']);
    $file = fn () => UploadedFile::fake()->createWithContent('fijos.ics', $ics);

    $this->actingAs($this->admin)
        ->post('/admin/festivos/importar/vista-previa', ['file' => $file(), 'year' => 2027])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('holiday_import.rows.0.date', '2027-01-01');

    $this->actingAs($this->admin)
        ->post('/admin/festivos/importar/vista-previa', ['file' => $file()])
        ->assertInertiaFlash('holiday_import.rows.0.date', '2026-01-01');

    $this->actingAs($this->admin)
        ->post('/admin/festivos/importar/vista-previa', ['file' => $file(), 'year' => 1999])
        ->assertSessionHasErrors(['year' => 'El campo año debe estar entre 2000 y 2100.']);
});

test('un evento de varios días (DTEND o DURATION) da un festivo por día, con su aviso', function () {
    Holiday::factory()->create(['date' => '2026-12-25', 'name' => 'Natividad']);

    $event = fn (string $name, string ...$lines): array => ['BEGIN:VEVENT', ...$lines, "SUMMARY:{$name}", 'END:VEVENT'];
    $ics = implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        ...$event('Navidades', 'DTSTART;VALUE=DATE:20261224', 'DTEND;VALUE=DATE:20261227'),
        ...$event('Fiestas', 'DTSTART;VALUE=DATE:20260908', 'DURATION:P2D'),
        ...$event('Un día', 'DTSTART;VALUE=DATE:20261009', 'DTEND;VALUE=DATE:20261010'),
        ...$event('Con horas', 'DTSTART:20261012T100000', 'DTEND:20261012T120000'),
        ...$event('En UTC', 'DTSTART:20261231T230000Z', 'DTEND:20270101T230000Z'),
        ...$event('Fin roto', 'DTSTART;VALUE=DATE:20261101', 'DTEND:mañana'),
        ...$event('Duración rota', 'DTSTART;VALUE=DATE:20261102', 'DURATION:P1X'),
        ...$event('Todo el verano', 'DTSTART;VALUE=DATE:20260701', 'DTEND;VALUE=DATE:20260901'),
        'END:VCALENDAR',
    ]);

    $preview = app(HolidayImporter::class)->preview($ics, 'ics', 2026);
    $rows = array_map(fn (array $row): array => [$row['line'], $row['date'], $row['status'], $row['message']], $preview['rows']);
    $christmas = 'Evento de 3 días (del 24/12/2026 al 26/12/2026): se añade un festivo por día.';
    $fiestas = 'Evento de 2 días (del 08/09/2026 al 09/09/2026): se añade un festivo por día.';

    expect($rows)->toBe([
        [3, '2026-12-24', 'new', $christmas],
        [3, '2026-12-25', 'existing', 'Ya hay un festivo ese día: «Natividad». Se deja como está. '.$christmas],
        [3, '2026-12-26', 'new', $christmas],
        [8, '2026-09-08', 'new', $fiestas],
        [8, '2026-09-09', 'new', $fiestas],
        [13, '2026-10-09', 'new', null],
        [18, '2026-10-12', 'new', null],
        [23, '2027-01-01', 'new', null],
        [28, '2026-11-01', 'error', 'La fecha de fin «mañana» no es válida.'],
        [33, '2026-11-02', 'error', 'La duración «P1X» no es válida.'],
        [38, '2026-07-01', 'error', 'El evento dura 62 días: un festivo importado dura 31 días como mucho.'],
    ])->and($preview['counts'])->toBe(['new' => 7, 'existing' => 1, 'duplicate' => 0, 'error' => 3]);
});

test('los días de los eventos largos cuentan para el límite de festivos por fichero', function () {
    $lines = ['BEGIN:VCALENDAR'];
    foreach (range(1, 17) as $month) {
        $start = CarbonImmutable::create(2026, 1, 1)->addMonths($month - 1);
        $lines = [...$lines, 'BEGIN:VEVENT', 'DTSTART;VALUE=DATE:'.$start->format('Ymd'), 'DURATION:P31D', "SUMMARY:Mes {$month}", 'END:VEVENT'];
    }
    $ics = implode("\r\n", [...$lines, 'END:VCALENDAR']);

    expect(fn () => app(HolidayImporter::class)->preview($ics, 'ics', 2026))
        ->toThrow(ValidationException::class, 'El fichero tiene más de 500 líneas. Divídelo en varios.');
});

test('el importador lee también un CSV separado por comas y líneas CRLF', function () {
    [$format, $rows] = app(HolidayImporter::class)->parse("2026-06-24,San Juan\r\n2026-08-16,San Roque\r\n", 'csv');

    expect($format)->toBe('csv')
        ->and(array_column($rows, 'date'))->toBe(['2026-06-24', '2026-08-16'])
        ->and(array_column($rows, 'error'))->toBe([null, null]);
});

test('un fichero sin nada legible es un error del fichero, no de una línea', function () {
    expect(fn () => app(HolidayImporter::class)->parse("# solo comentarios\n", 'csv'))->toThrow(ValidationException::class);
});

test('solo quien gestiona los ajustes entra en los festivos', function (Closure $actor, int $status) {
    $user = $actor();
    $holiday = Holiday::factory()->create(['date' => '2026-12-25']);

    $this->actingAs($user)->get('/admin/festivos')->assertStatus($status);
    $this->actingAs($user)->post('/admin/festivos', ['date' => '2026-12-26', 'name' => 'X'])->assertStatus($status === 200 ? 302 : $status);
    $this->actingAs($user)->put("/admin/festivos/{$holiday->id}", ['date' => '2026-12-25', 'name' => 'X'])->assertStatus($status === 200 ? 302 : $status);
    $this->actingAs($user)->delete("/admin/festivos/{$holiday->id}")->assertStatus($status === 200 ? 302 : $status);
    $this->actingAs($user)->post('/admin/festivos/nacionales', ['year' => 2026])->assertStatus($status === 200 ? 302 : $status);
    $this->actingAs($user)->post('/admin/festivos/importar/vista-previa', ['file' => UploadedFile::fake()->createWithContent('a.csv', '2026-01-02;X')])->assertStatus($status === 200 ? 302 : $status);
    $this->actingAs($user)->post('/admin/festivos/importar', ['rows' => [['date' => '2026-01-03', 'name' => 'X']]])->assertStatus($status === 200 ? 302 : $status);
})->with([
    'admin' => [fn () => userWithRole('admin'), 200],
    'responsable' => [fn () => tap(User::factory()->departmentManager()->inDepartment(Department::factory()->create())->create(), fn (User $user) => $user->department?->managers()->attach($user)), 403],
    'gestor de proyecto' => [fn () => tap(userWithRole('employee'), fn (User $user) => Project::factory()->create()->addMember($user, true)), 403],
    'empleado' => [fn () => userWithRole('employee'), 403],
]);

test('un cliente va al portal y un invitado al login', function () {
    $this->actingAs(userWithRole('client'))->get('/admin/festivos')->assertRedirect(route('portal.home'));
    auth()->logout();
    $this->get('/admin/festivos')->assertRedirect(route('login'));
    $this->post('/admin/festivos', ['date' => '2026-12-26', 'name' => 'X'])->assertRedirect(route('login'));
});
