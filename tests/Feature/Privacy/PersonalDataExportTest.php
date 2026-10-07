<?php

use App\Domain\Privacy\Export\PersonalDataSection;
use App\Domain\Privacy\Export\Sections\ProfileSection;
use App\Domain\Privacy\PersonalDataExporter;
use App\Enums\PersonalDataExportStatus;
use App\Jobs\BuildPersonalDataExport;
use App\Models\Absence;
use App\Models\Department;
use App\Models\LoginEvent;
use App\Models\PersonalDataExport;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Exportación de los datos personales (SPEC §15, D-075): la pide la propia persona o el admin, una
| sola en curso por persona, se genera en cola (ZIP con JSON y CSV por sección y un LEEME), se
| descarga con URL firmada Y la política (la persona o el admin) y caduca.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    $this->employee = User::factory()->employee()->create(['name' => 'Elena Empleada', 'email' => 'elena@example.com']);

    // Exportación lista con su fichero en el disco privado.
    $this->readyExport = function (?User $subject = null, array $attributes = []): PersonalDataExport {
        $export = PersonalDataExport::factory()->ready()->create([
            'subject_user_id' => ($subject ?? $this->employee)->id,
            'requested_by' => ($subject ?? $this->employee)->id,
            ...$attributes,
        ]);
        Storage::disk('local')->put($export->path, 'PK-zip-de-prueba');

        return $export;
    };

    $this->signedUrl = fn (PersonalDataExport $export, ?DateTimeInterface $expires = null): string => URL::temporarySignedRoute(
        'privacy.exports.download',
        $expires ?? $export->expires_at,
        ['export' => $export->id],
        absolute: false,
    );

    // Ejecuta el job como lo haría la cola (con sus dependencias).
    $this->build = fn (PersonalDataExport $export) => app()->call([new BuildPersonalDataExport($export->id), 'handle']);

    // Contenido del ZIP de una exportación: nombre del fichero → contenido.
    $this->zip = function (PersonalDataExport $export): array {
        $archive = new ZipArchive;
        expect($archive->open(Storage::disk('local')->path((string) $export->path)))->toBeTrue();

        $files = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $name = (string) $archive->getNameIndex($i);
            $files[$name] = (string) $archive->getFromName($name);
        }
        $archive->close();

        return $files;
    };
});

test('matriz de permisos de la exportación de datos personales', function (string $actor, array $statuses) {
    Queue::fake();
    $target = $this->employee;

    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $requests = [
        ['get', '/ajustes/mis-datos'],
        ['post', '/ajustes/mis-datos'],
        ['post', "/admin/usuarios/{$target->id}/datos-personales"],
    ];

    foreach ($requests as $index => [$method, $path]) {
        $response = $this->{$method}($path);
        $response->assertStatus($statuses[$index]);

        if ($actor === 'guest') {
            $response->assertRedirect(route('login'));
        }

        if ($actor === 'client') {
            $response->assertRedirect(route('portal.home'));
        }
    }
})->with([
    //                     mis datos  pedir  pedir (admin)
    'invitado' => ['guest', [302, 302, 302]],
    'admin' => ['admin', [200, 302, 302]],
    'responsable' => ['department_manager', [200, 302, 403]],
    'empleado' => ['employee', [200, 302, 403]],
    'cliente' => ['client', [302, 302, 302]],
]);

test('la persona pide sus datos: queda en cola, en la auditoría y solo una en curso', function () {
    Queue::fake();

    $this->actingAs($this->employee)
        ->from('/ajustes/mis-datos')
        ->post('/ajustes/mis-datos')
        ->assertRedirect('/ajustes/mis-datos')
        ->assertSessionHasNoErrors();

    $export = PersonalDataExport::query()->sole();

    expect($export->status)->toBe(PersonalDataExportStatus::Pending)
        ->and($export->subject_user_id)->toBe($this->employee->id)
        ->and($export->requested_by)->toBe($this->employee->id);

    Queue::assertPushedOn('default', BuildPersonalDataExport::class, fn (BuildPersonalDataExport $job): bool => $job->exportId === $export->id);

    $log = Activity::query()->where('log_name', 'privacy')->where('event', 'export_requested')->sole();
    expect($log->causer_id)->toBe($this->employee->id)
        ->and($log->subject_id)->toBe($export->id);

    // Otra mientras la primera sigue en curso: no.
    $this->actingAs($this->employee)
        ->from('/ajustes/mis-datos')
        ->post('/ajustes/mis-datos')
        ->assertSessionHasErrors(['export' => 'Ya hay una exportación en curso. Espera a que termine para pedir otra.']);

    expect(PersonalDataExport::query()->count())->toBe(1);

    $this->actingAs($this->employee)
        ->get('/ajustes/mis-datos')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/my-data')
            ->where('canRequest', false)
            ->where('exportDays', 7)
            ->has('exports', 1)
            ->where('exports.0.status', 'pending')
            ->where('exports.0.status_label', 'En cola')
            ->where('exports.0.requested_by_subject', true)
            ->where('exports.0.download_url', null));

    // Terminada (o fallida), ya se puede pedir otra.
    $export->forceFill(['status' => PersonalDataExportStatus::Failed])->save();

    $this->actingAs($this->employee)->post('/ajustes/mis-datos')->assertSessionHasNoErrors();

    expect(PersonalDataExport::query()->count())->toBe(2);
});

test('el admin pide los datos de una persona desde su ficha y los ve allí; nadie más', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->from("/admin/usuarios/{$this->employee->id}")
        ->post("/admin/usuarios/{$this->employee->id}/datos-personales")
        ->assertRedirect("/admin/usuarios/{$this->employee->id}")
        ->assertSessionHasNoErrors();

    $export = PersonalDataExport::query()->sole();

    expect($export->subject_user_id)->toBe($this->employee->id)
        ->and($export->requested_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)
        ->get("/admin/usuarios/{$this->employee->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('personalDataExports', 1)
            ->where('personalDataExports.0.requester', 'Ana Admin')
            ->where('personalDataExports.0.requested_by_subject', false));

    // Un responsable con el permiso de gestionar usuarios ve la ficha, pero no las exportaciones.
    $manager = userWithRole('department_manager');
    $manager->givePermissionTo('manage-users');

    $this->actingAs($manager)
        ->get("/admin/usuarios/{$this->employee->id}")
        ->assertInertia(fn (Assert $page) => $page->where('personalDataExports', null));

    // La persona también la ve en /ajustes/mis-datos, pedida por el admin.
    $this->actingAs($this->employee)
        ->get('/ajustes/mis-datos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('exports.0.requester', 'Ana Admin')
            ->where('exports.0.requested_by_subject', false));

    // Los datos de un cliente del portal no se exportan desde la ficha (no tiene ficha).
    $client = userWithRole('client');
    $this->actingAs($this->admin)->post("/admin/usuarios/{$client->id}/datos-personales")->assertNotFound();
});

test('se descarga con la URL firmada, solo la persona o un admin, y queda registrado', function () {
    $this->travelTo('2026-10-05 10:00:00');
    $export = ($this->readyExport)();
    $url = ($this->signedUrl)($export);

    $response = $this->actingAs($this->employee)->get($url)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/zip')
        ->and($response->headers->get('Content-Disposition'))->toContain('datos-personales-elena-empleada-2026-10-05.zip')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toBe('PK-zip-de-prueba')
        ->and($export->refresh()->downloaded_at?->toIso8601ZuluString())->toBe('2026-10-05T10:00:00Z');

    $log = Activity::query()->where('event', 'export_downloaded')->sole();
    expect($log->causer_id)->toBe($this->employee->id)
        ->and($log->subject_id)->toBe($export->id);

    // El admin también; otra persona interna, no; un cliente vuelve a su portal; sin sesión, al login.
    $this->actingAs($this->admin)->get($url)->assertOk();
    $this->actingAs(userWithRole('employee'))->get($url)->assertForbidden();
    $this->actingAs(userWithRole('department_manager'))->get($url)->assertForbidden();
    $this->actingAs(userWithRole('client'))->get($url)->assertRedirect(route('portal.home'));
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

test('una URL sin firma, manipulada, de otra exportación o caducada no sirve', function () {
    $export = ($this->readyExport)();
    $other = ($this->readyExport)(null, ['status' => PersonalDataExportStatus::Ready]);
    $url = ($this->signedUrl)($export);

    $this->actingAs($this->employee)->get("/datos-personales/{$export->id}/descargar")->assertForbidden();
    $this->actingAs($this->employee)->get($url.'x')->assertForbidden();
    $this->actingAs($this->employee)->get(str_replace("/datos-personales/{$export->id}/", "/datos-personales/{$other->id}/", $url))->assertForbidden();

    $short = ($this->signedUrl)($export, now()->addMinutes(5));
    $this->travel(6)->minutes();
    $this->actingAs($this->employee)->get($short)->assertForbidden();
});

test('solo se descarga si está lista y sin caducar', function () {
    $pending = PersonalDataExport::factory()->create(['subject_user_id' => $this->employee->id]);
    $expired = ($this->readyExport)(null, ['status' => PersonalDataExportStatus::Expired]);
    $missing = PersonalDataExport::factory()->ready()->create(['subject_user_id' => $this->employee->id]);

    $this->actingAs($this->employee)->get(($this->signedUrl)($pending, now()->addDay()))->assertNotFound();
    $this->actingAs($this->employee)->get(($this->signedUrl)($expired, now()->addDay()))->assertNotFound();
    // Lista, pero sin fichero en el disco.
    $this->actingAs($this->employee)->get(($this->signedUrl)($missing))->assertNotFound();

    // Pasado expires_at, la propia firma ha caducado.
    $ready = ($this->readyExport)();
    $url = ($this->signedUrl)($ready);
    $this->travelTo($ready->expires_at->addMinute());
    $this->actingAs($this->employee)->get($url)->assertForbidden();

    expect(Activity::query()->where('event', 'export_downloaded')->count())->toBe(0);
});

test('la lista solo ofrece la descarga de las listas y sin caducar, con su URL firmada', function () {
    $ready = ($this->readyExport)();
    PersonalDataExport::factory()->create(['subject_user_id' => $this->employee->id, 'status' => PersonalDataExportStatus::Failed, 'error' => 'RuntimeException: disco lleno']);
    PersonalDataExport::factory()->create(['subject_user_id' => $this->employee->id, 'status' => PersonalDataExportStatus::Expired]);

    $props = $this->actingAs($this->employee)->get('/ajustes/mis-datos')->viewData('page')['props'];
    $rows = collect($props['exports'])->keyBy('status');

    expect($props['canRequest'])->toBeTrue()
        ->and($rows['ready']['download_url'])->toStartWith("/datos-personales/{$ready->id}/descargar?expires=")
        ->and($rows['ready']['download_url'])->toContain('signature=')
        ->and($rows['failed']['download_url'])->toBeNull()
        ->and($rows['failed']['status_label'])->toBe('Ha fallado')
        // Sin traza técnica para la persona.
        ->and($rows['failed'])->not->toHaveKey('error')
        ->and($rows['expired']['download_url'])->toBeNull();

    $this->actingAs($this->employee)->get($rows['ready']['download_url'])->assertOk();
});

test('el job genera un ZIP con JSON y CSV por sección y un LEEME, solo con los datos de la persona', function () {
    $this->travelTo('2026-10-05 10:00:00');
    $employee = $this->employee;
    $other = User::factory()->employee()->create(['name' => 'Otra Persona']);
    $department = Department::factory()->create(['name' => 'Diseño']);
    $employee->forceFill([
        'department_id' => $department->id,
        'two_factor_secret' => encrypt('SECRETO-2FA'),
        'two_factor_recovery_codes' => encrypt('["codigo-de-recuperacion"]'),
        'hourly_cost' => '31.50',
        'default_hourly_rate' => '65.00',
    ])->save();

    $project = Project::factory()->create(['name' => 'Web corporativa', 'code' => 'WEB']);
    $task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Maquetar la portada']);
    WorkSchedule::factory()->create(['user_id' => $employee->id, 'valid_from' => '2026-01-01']);
    TimeEntry::factory()->forTask($task)->create(['user_id' => $employee->id, 'date' => '2026-09-30', 'minutes' => 90, 'description' => '=CMD() revisión', 'hourly_rate_snapshot' => '65.00', 'hourly_cost_snapshot' => '31.50']);
    TimeEntry::factory()->forTask($task)->create(['user_id' => $other->id, 'description' => 'De otra persona']);
    Absence::factory()->create(['user_id' => $employee->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-16', 'notes' => 'Vacaciones de otoño']);
    TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $employee->id, 'body' => '<p>Listo para <strong>revisar</strong></p>']);
    TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $other->id, 'body' => '<p>Comentario ajeno</p>']);
    $employee->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['kind' => 'task.assigned', 'title' => 'Te asignan «Maquetar la portada»', 'body' => null, 'url' => "/tareas/{$task->id}"]]);
    LoginEvent::query()->create(['user_id' => $employee->id, 'email' => 'elena@example.com', 'ip_address' => '10.0.0.7', 'user_agent' => 'Firefox', 'succeeded' => true]);
    LoginEvent::query()->create(['user_id' => $other->id, 'email' => 'otra@example.com', 'ip_address' => '10.0.0.99', 'user_agent' => 'Chrome', 'succeeded' => false]);

    Queue::fake();
    $export = app(PersonalDataExporter::class)->request($employee, $employee);
    ($this->build)($export);

    $export->refresh();

    expect($export->status)->toBe(PersonalDataExportStatus::Ready)
        ->and($export->path)->toMatch('#^exports/personal-data/[0-9a-f-]{36}\.zip$#')
        ->and($export->size_bytes)->toBe(Storage::disk('local')->size((string) $export->path))
        ->and($export->started_at)->not->toBeNull()
        ->and($export->finished_at?->toIso8601ZuluString())->toBe('2026-10-05T10:00:00Z')
        ->and($export->expires_at?->toIso8601ZuluString())->toBe('2026-10-12T10:00:00Z');

    $files = ($this->zip)($export);

    expect(array_keys($files))->toEqualCanonicalizing([
        'LEEME.txt',
        'perfil.json', 'perfil.csv',
        'horarios.json', 'horarios.csv',
        'horas.json', 'horas.csv',
        'ausencias.json', 'ausencias.csv',
        // Fase 11, R3 (D-372).
        'saldos-ausencias.json', 'saldos-ausencias.csv',
        'justificantes.json', 'justificantes.csv',
        'comentarios.json', 'comentarios.csv',
        'mensajes-chat.json', 'mensajes-chat.csv',
        // Plan del día (D-256).
        'plan-del-dia.json', 'plan-del-dia.csv',
        'comentarios-plan-del-dia.json', 'comentarios-plan-del-dia.csv',
        'weeklies-envios.json', 'weeklies-envios.csv',
        'weeklies-apuntes.json', 'weeklies-apuntes.csv',
        'dictados.json', 'dictados.csv',
        'weeklies-exenciones.json', 'weeklies-exenciones.csv',
        'resumenes-ia.json', 'resumenes-ia.csv',
        'uso-ia.json', 'uso-ia.csv',
        'weeklies-avisos.json', 'weeklies-avisos.csv',
        'mi-espacio-tareas.json', 'mi-espacio-tareas.csv',
        'sugerencias.json', 'sugerencias.csv',
        'sugerencias-comentarios.json', 'sugerencias-comentarios.csv',
        'sugerencias-votos.json', 'sugerencias-votos.csv',
        'ayuda-me-gusta.json', 'ayuda-me-gusta.csv',
        // Registro de jornada (Fase 11, R2; D-357).
        'registro-jornada.json', 'registro-jornada.csv',
        'correcciones-registro.json', 'correcciones-registro.csv',
        'cierres-mensuales.json', 'cierres-mensuales.csv',
        'horas-extra.json', 'horas-extra.csv',
        'saldo-horas.json', 'saldo-horas.csv',
        'datos-laborales.json', 'datos-laborales.csv',
        'notificaciones.json', 'notificaciones.csv',
        'integraciones.json', 'integraciones.csv',
        'accesos.json', 'accesos.csv',
    ]);

    $profile = json_decode($files['perfil.json'], true);

    expect($profile)->toHaveCount(1)
        ->and($profile[0])->toMatchArray(['id' => $employee->id, 'name' => 'Elena Empleada', 'email' => 'elena@example.com', 'department' => 'Diseño', 'roles' => 'Empleado', 'two_factor_enabled' => false])
        ->and(array_keys($profile[0]))->not->toContain('password')
        ->and($files['perfil.json'])->not->toContain('SECRETO-2FA')
        ->and($files['perfil.json'])->not->toContain('codigo-de-recuperacion')
        ->and($files['perfil.json'])->not->toContain('31.50')
        ->and($files['perfil.json'])->not->toContain('65.00');

    $hours = json_decode($files['horas.json'], true);

    expect($hours)->toHaveCount(1)
        ->and($hours[0])->toMatchArray([
            'date' => '2026-09-30',
            'project' => 'WEB · Web corporativa',
            'task' => 'Maquetar la portada',
            'minutes' => 90,
            'duration' => '1:30',
            'is_billable' => true,
            'status' => 'Borrador',
            'description' => '=CMD() revisión',
        ])
        ->and($hours[0])->not->toHaveKey('hourly_rate_snapshot')
        ->and($files['horas.json'])->not->toContain('De otra persona');

    // CSV para Excel: BOM, «;», cabeceras legibles y los textos nunca como fórmulas.
    expect($files['horas.csv'])->toStartWith("\u{FEFF}Id;Fecha;Cliente;Proyecto;Bolsa;Tarea;Minutos;Duración")
        ->and($files['horas.csv'])->toContain("'=CMD() revisión")
        ->and($files['horas.csv'])->not->toContain('De otra persona');

    expect(json_decode($files['comentarios.json'], true))->toHaveCount(1)
        ->and(json_decode($files['comentarios.json'], true)[0]['body'])->toBe('Listo para revisar')
        ->and(json_decode($files['ausencias.json'], true)[0])->toMatchArray(['type' => 'Vacaciones', 'start_date' => '2026-10-12', 'notes' => 'Vacaciones de otoño'])
        ->and(json_decode($files['horarios.json'], true)[0])->toMatchArray(['valid_from' => '2026-01-01', 'mon_minutes' => 480, 'weekly_total' => '40:00'])
        ->and(json_decode($files['notificaciones.json'], true)[0])->toMatchArray(['kind' => 'task.assigned', 'url' => "/tareas/{$task->id}"])
        ->and(json_decode($files['accesos.json'], true))->toBe([
            ['created_at' => '2026-10-05T12:00:00+02:00', 'succeeded' => true, 'method' => 'password', 'ip_address' => '10.0.0.7', 'user_agent' => 'Firefox'],
        ]);

    expect($files['LEEME.txt'])->toContain('Tus datos personales en Audax Proyectos')
        ->and($files['LEEME.txt'])->toContain('Persona: Elena Empleada (elena@example.com)')
        ->and($files['LEEME.txt'])->toContain('- horas.json y horas.csv: Tus horas imputadas')
        ->and($files['LEEME.txt'])->toContain('Filas: 1.')
        ->and($files['LEEME.txt'])->toContain('caduca a los 7 días');
});

test('la foto de perfil va en el ZIP y el perfil dice que la tiene (D-234)', function () {
    $this->actingAs($this->employee)->post('/ajustes/perfil/foto', ['avatar' => UploadedFile::fake()->image('yo.jpg', 400, 400)]);

    Queue::fake();
    $export = app(PersonalDataExporter::class)->request($this->employee->refresh(), $this->employee);
    ($this->build)($export);

    $files = ($this->zip)($export->refresh());
    $photo = collect(array_keys($files))->first(fn (string $name): bool => str_starts_with($name, 'foto-perfil.'));

    expect($photo)->not->toBeNull()
        ->and(getimagesizefromstring($files[$photo])[0])->toBe(256)
        ->and(json_decode($files['perfil.json'], true)[0]['has_avatar'])->toBeTrue();
});

test('los días para descargar salen del ajuste personal_data_export_days', function () {
    $this->travelTo('2026-10-05 10:00:00');
    Setting::set('personal_data_export_days', 3);
    Queue::fake();

    $export = app(PersonalDataExporter::class)->request($this->employee, $this->employee);
    ($this->build)($export);

    expect($export->refresh()->expires_at?->toIso8601ZuluString())->toBe('2026-10-08T10:00:00Z');
});

test('si falla, queda como fallida sin fichero, con el detalle solo para el admin, y se puede pedir otra', function () {
    Queue::fake();
    $this->app->bind(ProfileSection::class, fn () => new class implements PersonalDataSection
    {
        public function key(): string
        {
            return 'perfil';
        }

        public function description(): string
        {
            return 'Falla';
        }

        public function columns(): array
        {
            return ['id' => 'Id'];
        }

        public function rows(User $user): iterable
        {
            throw new RuntimeException('Disco lleno al escribir');
        }
    });

    $export = app(PersonalDataExporter::class)->request($this->employee, $this->employee);
    ($this->build)($export);

    $export->refresh();

    expect($export->status)->toBe(PersonalDataExportStatus::Failed)
        ->and($export->path)->toBeNull()
        ->and($export->error)->toContain('Disco lleno al escribir')
        ->and($export->finished_at)->not->toBeNull()
        ->and(Storage::disk('local')->allFiles('exports/personal-data'))->toBe([]);

    $this->actingAs($this->employee)
        ->get('/ajustes/mis-datos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('canRequest', true)
            ->where('exports.0.status_label', 'Ha fallado')
            ->missing('exports.0.error'));
});

test('el job solo procesa las pendientes y la cola la da por fallida si se agota', function () {
    Queue::fake();
    $export = app(PersonalDataExporter::class)->request($this->employee, $this->employee);

    ($this->build)($export);
    $path = $export->refresh()->path;
    ($this->build)($export);

    expect($export->refresh()->path)->toBe($path)
        ->and(Storage::disk('local')->allFiles('exports/personal-data'))->toHaveCount(1);

    $stuck = PersonalDataExport::factory()->create(['subject_user_id' => User::factory()->employee()->create()->id, 'status' => PersonalDataExportStatus::Processing]);
    (new BuildPersonalDataExport($stuck->id))->failed(new RuntimeException('Tiempo agotado'));

    expect($stuck->refresh()->status)->toBe(PersonalDataExportStatus::Failed)
        ->and($stuck->error)->toContain('Tiempo agotado');

    // Una ya lista no se toca.
    (new BuildPersonalDataExport($export->id))->failed(new RuntimeException('Tarde'));
    expect($export->refresh()->status)->toBe(PersonalDataExportStatus::Ready);
});
