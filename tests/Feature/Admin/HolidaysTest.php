<?php

use App\Domain\Absences\HolidayImporter;
use App\Domain\Absences\SpanishNationalHolidays;
use App\Domain\Time\Capacity;
use App\Models\Department;
use App\Models\Holiday;
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
        ->and(Activity::query()->where('log_name', 'holidays')->pluck('event')->all())
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
    'empleado' => [fn () => userWithRole('employee'), 403],
]);

test('un cliente va al portal y un invitado al login', function () {
    $this->actingAs(userWithRole('client'))->get('/admin/festivos')->assertRedirect(route('portal.home'));
    auth()->logout();
    $this->get('/admin/festivos')->assertRedirect(route('login'));
    $this->post('/admin/festivos', ['date' => '2026-12-26', 'name' => 'X'])->assertRedirect(route('login'));
});
