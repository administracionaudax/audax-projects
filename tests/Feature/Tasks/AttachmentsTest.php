<?php

use App\Domain\Tasks\AttachmentStorage;
use App\Domain\Tasks\Jobs\GenerateAttachmentThumbnail;
use App\Http\Resources\Tasks\AttachmentResource;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
| Adjuntos (SPEC §15, D-037): disco privado, tamaño y tipo real, URL firmada + política,
| SVG siempre descargado, miniatura de las imágenes rasterizadas y borrado de los ficheros.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Storage::fake('local');

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->project->addMember($this->user);
    $this->task = Task::factory()->create(['project_id' => $this->project->id]);
    $this->upload = fn (array $files) => $this->actingAs($this->user)
        ->from("/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}")
        ->post("/tareas/{$this->task->id}/adjuntos", ['files' => $files]);
    $this->signed = fn (string $route, Attachment $attachment, int $minutes = 60): string => URL::temporarySignedRoute(
        $route,
        now()->addMinutes($minutes),
        ['attachment' => $attachment->id],
        absolute: false,
    );
});

it('guarda el fichero en attachments/{proyecto}/{uuid}.{ext} con el nombre original solo en la base de datos', function () {
    ($this->upload)([UploadedFile::fake()->create('Presupuesto final.pdf', 120, 'application/pdf')])
        ->assertRedirect("/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}")
        ->assertSessionHasNoErrors();

    $attachment = Attachment::query()->firstOrFail();

    expect($attachment->original_name)->toBe('Presupuesto final.pdf')
        ->and($attachment->path)->toMatch("#^attachments/{$this->project->id}/[0-9a-f-]{36}\\.pdf$#")
        ->and($attachment->disk)->toBe('local')
        ->and($attachment->mime)->toBe('application/pdf')
        ->and($attachment->project_id)->toBe($this->project->id)
        ->and($attachment->user_id)->toBe($this->user->id)
        ->and($attachment->attachable_id)->toBe($this->task->id)
        ->and($attachment->thumbnail_path)->toBeNull();

    Storage::disk('local')->assertExists($attachment->path);
});

it('crea una miniatura de 400 px de las imágenes rasterizadas', function () {
    ($this->upload)([UploadedFile::fake()->image('foto.jpg', 1600, 800)])->assertSessionHasNoErrors();

    $attachment = Attachment::query()->firstOrFail();
    expect($attachment->thumbnail_path)->not->toBeNull();

    $thumbnail = Storage::disk('local')->get((string) $attachment->thumbnail_path);
    [$width, $height] = getimagesizefromstring((string) $thumbnail);

    expect($width)->toBe(400)->and($height)->toBe(200);
});

it('no amplía las imágenes pequeñas', function () {
    ($this->upload)([UploadedFile::fake()->image('icono.png', 120, 60)])->assertSessionHasNoErrors();

    $thumbnail = Storage::disk('local')->get((string) Attachment::query()->firstOrFail()->thumbnail_path);

    expect(getimagesizefromstring((string) $thumbnail)[0])->toBe(120);
});

it('no hace miniatura de los SVG ni de imágenes demasiado grandes', function () {
    ($this->upload)([
        UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>')->mimeType('image/svg+xml'),
    ])->assertSessionHasNoErrors();

    expect(Attachment::query()->firstOrFail()->thumbnail_path)->toBeNull()
        ->and(AttachmentStorage::MAX_THUMBNAIL_PIXELS)->toBe(40_000_000);
});

it('la subida no genera la miniatura en la petición: la encola y la hace el job', function () {
    Queue::fake();

    ($this->upload)([UploadedFile::fake()->image('foto.jpg', 1600, 800)])->assertSessionHasNoErrors();

    $attachment = Attachment::query()->firstOrFail();
    expect($attachment->thumbnail_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([$attachment->path]);

    Queue::assertPushed(GenerateAttachmentThumbnail::class, 1);
    $job = Queue::pushed(GenerateAttachmentThumbnail::class)->first();
    expect($job->attachment->is($attachment))->toBeTrue()
        ->and($job->tries)->toBe(1);

    app()->call([$job, 'handle']);

    $thumbnail = (string) $attachment->refresh()->thumbnail_path;
    expect($thumbnail)->toStartWith("attachments/{$this->project->id}/thumbs/");
    expect(getimagesizefromstring((string) Storage::disk('local')->get($thumbnail))[0])->toBe(400);
});

it('solo encola miniaturas de las imágenes rasterizadas', function () {
    Queue::fake();

    ($this->upload)([
        UploadedFile::fake()->create('acta.pdf', 5, 'application/pdf'),
        UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>')->mimeType('image/svg+xml'),
    ])->assertSessionHasNoErrors();

    expect(Attachment::query()->count())->toBe(2);
    Queue::assertNotPushed(GenerateAttachmentThumbnail::class);
});

it('gira la miniatura según el EXIF de la foto', function () {
    $image = imagecreatetruecolor(800, 400);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();
    // APP1 con un TIFF mínimo: una sola etiqueta Orientation = 6 (girar 90° a la derecha).
    $tiff = 'MM'."\x00\x2A".pack('N', 8).pack('n', 1).pack('nnNn', 0x0112, 3, 1, 6)."\x00\x00".pack('N', 0);
    $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff.substr($jpeg, 2);

    ($this->upload)([UploadedFile::fake()->createWithContent('movil.jpg', $jpeg)])->assertSessionHasNoErrors();

    $thumbnail = Storage::disk('local')->get((string) Attachment::query()->firstOrFail()->thumbnail_path);

    expect(array_slice((array) getimagesizefromstring((string) $thumbnail), 0, 2))->toBe([200, 400]);
})->skip(! function_exists('exif_read_data'), 'Sin la extensión exif.');

it('descarta las imágenes enormes por su cabecera, sin llegar a decodificarlas', function () {
    // Solo la firma y el IHDR de un PNG de 8.000 × 6.000 (48 megapíxeles).
    $ihdr = 'IHDR'.pack('NNCCCCC', 8000, 6000, 8, 2, 0, 0, 0);
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));

    ($this->upload)([UploadedFile::fake()->createWithContent('enorme.png', $png)])->assertSessionHasNoErrors();

    expect(Attachment::query()->firstOrFail()->thumbnail_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
        // El presupuesto de memoria no depende de memory_limit y cubre el máximo de píxeles.
        ->and(AttachmentStorage::thumbnailMemory(8000, 5000))->toBeLessThanOrEqual(AttachmentStorage::MAX_THUMBNAIL_MEMORY)
        ->and(AttachmentStorage::thumbnailMemory(8000, 6000))->toBeGreaterThan(AttachmentStorage::MAX_THUMBNAIL_MEMORY);
});

it('si el adjunto se borra antes de que corra el job, no deja la miniatura huérfana', function () {
    Queue::fake();
    ($this->upload)([UploadedFile::fake()->image('foto.png', 800, 600)])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();
    $job = Queue::pushed(GenerateAttachmentThumbnail::class)->first();

    // Solo la fila (a la papelera): el fichero original sigue en el disco mientras corre el job.
    $attachment->delete();
    app()->call([$job, 'handle']);

    expect(Storage::disk('local')->allFiles())->toBe([$attachment->path])
        ->and(Attachment::withTrashed()->findOrFail($attachment->id)->thumbnail_path)->toBeNull();
});

it('respeta el tamaño máximo del ajuste max_attachment_mb', function () {
    Setting::set('max_attachment_mb', 1);

    ($this->upload)([UploadedFile::fake()->create('grande.pdf', 1025, 'application/pdf')])
        ->assertSessionHasErrors(['files.0' => __('tasks.errors.attachment_too_big', ['max' => 1])]);
    ($this->upload)([UploadedFile::fake()->create('justo.pdf', 1024, 'application/pdf')])->assertSessionHasNoErrors();
});

it('rechaza tipos no permitidos por su contenido real', function () {
    ($this->upload)([UploadedFile::fake()->create('script.php', 1, 'application/x-php')])->assertSessionHasErrors('files.0');
    ($this->upload)([UploadedFile::fake()->create('factura.pdf', 10)->mimeType('application/x-msdownload')])
        ->assertSessionHasErrors(['files.0' => __('tasks.errors.attachment_type')]);

    expect(Attachment::query()->count())->toBe(0);
});

it('rechaza una extensión que no casa con el tipo real', function () {
    ($this->upload)([UploadedFile::fake()->create('foto.png', 10)->mimeType('application/pdf')])
        ->assertSessionHasErrors(['files.0' => __('tasks.errors.attachment_extension', ['name' => 'foto.png'])]);
    ($this->upload)([UploadedFile::fake()->create('sin-extension', 10)->mimeType('application/pdf')])
        ->assertSessionHasErrors(['files.0' => __('tasks.errors.attachment_name')]);

    expect(Attachment::query()->count())->toBe(0);
});

it('acepta ofimática detectada como ZIP y CSV detectado como texto', function () {
    ($this->upload)([
        UploadedFile::fake()->create('informe.docx', 10)->mimeType('application/zip'),
        UploadedFile::fake()->create('datos.csv', 1)->mimeType('text/plain'),
        UploadedFile::fake()->create('hoja.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    ])->assertSessionHasNoErrors();

    expect(Attachment::query()->count())->toBe(3);
});

it('limita el número de archivos por subida', function () {
    $files = array_map(fn (int $i) => UploadedFile::fake()->create("doc{$i}.txt", 1, 'text/plain'), range(1, AttachmentStorage::MAX_FILES + 1));

    ($this->upload)($files)->assertSessionHasErrors('files');
});

it('descarga con URL firmada: sin firma, caducada o alterada da 403', function () {
    ($this->upload)([
        UploadedFile::fake()->create('acta.pdf', 5, 'application/pdf'),
        UploadedFile::fake()->create('otra.pdf', 5, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    [$attachment, $other] = Attachment::query()->orderBy('id')->get()->all();

    $this->actingAs($this->user)->get("/adjuntos/{$attachment->id}")->assertForbidden();

    // Firma alterada o reutilizada para otro adjunto.
    $url = ($this->signed)('attachments.show', $attachment);
    $this->actingAs($this->user)->get($url.'x')->assertForbidden();
    $this->actingAs($this->user)->get(str_replace("/adjuntos/{$attachment->id}?", "/adjuntos/{$other->id}?", $url))->assertForbidden();

    $expired = ($this->signed)('attachments.show', $attachment, 60);
    $this->travel(61)->minutes();
    $this->actingAs($this->user)->get($expired)->assertForbidden();
});

it('sirve los SVG y los documentos siempre como descarga, con nosniff y sandbox', function (string $name, string $mime) {
    ($this->upload)([UploadedFile::fake()->createWithContent($name, $name === 'logo.svg' ? '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' : 'contenido')->mimeType($mime)])
        ->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();

    $response = $this->actingAs($this->user)->get(($this->signed)('attachments.show', $attachment))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->toContain($name)
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe('sandbox')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream');
})->with([
    'svg' => ['logo.svg', 'image/svg+xml'],
    'pdf' => ['acta.pdf', 'application/pdf'],
    'texto' => ['notas.txt', 'text/plain'],
]);

it('muestra las imágenes rasterizadas en el navegador, con CSP sandbox', function () {
    ($this->upload)([UploadedFile::fake()->image('foto.png', 50, 50)])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();

    $response = $this->actingAs($this->user)->get(($this->signed)('attachments.show', $attachment))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Security-Policy'))->toBe('sandbox')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('la miniatura tiene su propia ruta firmada', function () {
    ($this->upload)([UploadedFile::fake()->image('foto.png', 900, 900)])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();

    $this->actingAs($this->user)->get("/adjuntos/{$attachment->id}/miniatura")->assertForbidden();

    $response = $this->actingAs($this->user)->get(($this->signed)('attachments.thumbnail', $attachment))->assertOk();
    expect($response->headers->get('Content-Type'))->toBeIn(['image/webp', 'image/png'])
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');

    // La firma de la descarga no vale para la miniatura.
    $downloadUrl = ($this->signed)('attachments.show', $attachment);
    $this->actingAs($this->user)->get(str_replace('/adjuntos/'.$attachment->id.'?', '/adjuntos/'.$attachment->id.'/miniatura?', $downloadUrl))->assertForbidden();
});

it('las URLs del Resource son firmadas, relativas y caducan en torno a una hora', function () {
    ($this->upload)([UploadedFile::fake()->image('foto.png', 900, 900)])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();

    $url = AttachmentResource::downloadUrl($attachment);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith("/adjuntos/{$attachment->id}?")
        ->and((int) $query['expires'])->toBeGreaterThanOrEqual(now()->addHour()->getTimestamp())
        ->toBeLessThanOrEqual(now()->addMinutes(65)->getTimestamp())
        ->and(AttachmentResource::thumbnailUrl($attachment))->toStartWith("/adjuntos/{$attachment->id}/miniatura?");

    $this->actingAs($this->user)->get($url)->assertOk();
});

it('un cliente no descarga adjuntos aunque tenga la URL firmada', function () {
    ($this->upload)([UploadedFile::fake()->create('acta.pdf', 5, 'application/pdf')])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();

    $this->actingAs(userWithRole('client'))->get(($this->signed)('attachments.show', $attachment))
        ->assertRedirect(route('portal.home'));
});

it('borrar un adjunto elimina el fichero y la miniatura: quien lo subió, gestores y admins', function () {
    ($this->upload)([UploadedFile::fake()->image('foto.jpg', 800, 600)])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();
    $paths = [$attachment->path, (string) $attachment->thumbnail_path];
    Storage::disk('local')->assertExists($paths);

    $member = userWithRole('employee');
    $this->project->addMember($member);
    $this->actingAs($member)->delete("/adjuntos/{$attachment->id}")->assertForbidden();

    $this->actingAs($this->user)->delete("/adjuntos/{$attachment->id}")->assertRedirect();

    Storage::disk('local')->assertMissing($paths);
    expect(Attachment::query()->find($attachment->id))->toBeNull()
        ->and(Attachment::withTrashed()->find($attachment->id))->not->toBeNull();
});

it('el gestor del proyecto y el admin pueden borrar adjuntos ajenos', function (string $who) {
    ($this->upload)([UploadedFile::fake()->create('acta.pdf', 5, 'application/pdf')])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();
    $actor = $who === 'admin' ? userWithRole('admin') : userWithRole('employee');

    if ($who === 'manager') {
        $this->project->addMember($actor, isManager: true);
    }

    $this->actingAs($actor)->delete("/adjuntos/{$attachment->id}")->assertRedirect();

    expect(Attachment::query()->count())->toBe(0);
})->with(['admin', 'manager']);

it('limpia el nombre original (sin rutas ni caracteres de control)', function () {
    expect(AttachmentStorage::cleanName("../../etc/pass\x00wd.pdf"))->toBe('passwd.pdf')
        ->and(AttachmentStorage::cleanName('C:\\Users\\ana\\informe.pdf'))->toBe('informe.pdf')
        ->and(AttachmentStorage::cleanName(''))->toBe('archivo');
});
