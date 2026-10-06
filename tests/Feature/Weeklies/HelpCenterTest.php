<?php

use App\Domain\Weeklies\Help\HelpCenter;
use App\Domain\Weeklies\Help\TutorialVideoUploads;
use App\Events\Weeklies\HelpCenterChanged;
use App\Models\Attachment;
use App\Models\HelpFaq;
use App\Models\HelpFaqSection;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpTutorial;
use App\Models\HelpUpdateLike;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Centro de ayuda (10.7, F-148 a F-158, D-207 y D-208): la página /ayuda por pestañas, las novedades
| automáticas y a mano con sus estados y «me gusta», el manual y el soporte, los tutoriales en vídeo
| (subida por trozos y reproducción por Range con URL firmada) y las preguntas frecuentes. Gestiona
| `manage-help` (admin y responsables); lo ve la plantilla; nunca un colaborador externo.
| Hoy, sábado 03/10/2026: la versión de esta semana es la V.1.10.1, que se publica el lunes 05/10.
*/

const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    $this->admin = userWithRole('admin', ['name' => 'Alba']);
    $this->manager = userWithRole('department_manager', ['name' => 'Marta']);
    $this->employee = userWithRole('employee', ['name' => 'Elena']);

    /** Sube un vídeo por trozos y devuelve el id de la subida. */
    $this->upload = function (User $user, string $content, int $chunk = 0): string {
        $start = $this->actingAs($user)->postJson('/ayuda/tutoriales/subidas', ['name' => 'Cómo fichar.mp4', 'size' => strlen($content)])
            ->assertCreated()
            ->assertJsonPath('chunk_size', TutorialVideoUploads::CHUNK_BYTES);
        $id = (string) $start->json('upload');
        $size = $chunk > 0 ? $chunk : strlen($content);

        for ($offset = 0; $offset < strlen($content); $offset += $size) {
            $this->actingAs($user)->post("/ayuda/tutoriales/subidas/{$id}", [
                'offset' => $offset,
                'chunk' => UploadedFile::fake()->createWithContent('blob', substr($content, $offset, $size)),
            ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('received', min(strlen($content), $offset + $size));
        }

        return $id;
    };
    $this->video = MP4.str_repeat("\x00", 4000);
});

it('la ve la plantilla, no un colaborador externo; gestionar es de admin y responsables', function (string $role, int $view, bool $manage) {
    $user = $role === 'collaborator' ? User::factory()->collaborator()->create() : userWithRole($role);

    $response = $this->actingAs($user)->get('/ayuda')->assertStatus($view);

    if ($view === 200) {
        $response->assertInertia(fn (Assert $page) => $page->component('help/index')->where('can.manage', $manage));
    }

    $this->actingAs($user)->postJson('/ayuda/secciones', ['name' => 'General'])->assertStatus($manage ? 302 : 403);
})->with([
    'admin' => ['admin', 200, true],
    'responsable' => ['department_manager', 200, true],
    'empleado' => ['employee', 200, false],
    'colaborador externo' => ['collaborator', 403, false],
]);

it('cada pestaña trae solo lo suyo (F-148) y la de sugerencias necesita su módulo', function () {
    $this->actingAs($this->employee)->get('/ayuda?pestana=preguntas')->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'preguntas')
        ->where('updates', null)
        ->where('tutorials', null)
        ->where('suggestions', null)
        ->has('faq_sections', 0));

    $this->actingAs($this->employee)->get('/ayuda?pestana=tutoriales')->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'tutoriales')
        ->has('tutorials', 0)
        ->has('releases', 1)
        ->where('faq_sections', null));

    Setting::set('modules', ['suggestions' => false]);
    $this->actingAs($this->employee)->get('/ayuda?pestana=sugerencias')->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'general')
        ->where('can.suggestions', false));
});

it('crea sola la versión de esta semana, en curso, y la última cerrada es la nueva (F-150 y F-152)', function () {
    HelpRelease::query()->create(['major_version' => 1, 'month_number' => 9, 'week_of_month' => 4, 'summary' => 'Fichajes más rápidos']);
    HelpManualUpdate::query()->create(['published_on' => '2026-09-10', 'title' => 'Nuevo panel', 'subtitle' => 'Resumen', 'body' => '<p>Hola</p>']);

    $this->actingAs($this->employee)->get('/ayuda')->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'general')
        ->has('updates', 3)
        ->where('updates.0.version', 'V.1.10.1')
        ->where('updates.0.status', 'in_progress')
        ->where('updates.0.title', 'Notas de lanzamiento V.1.10.1')
        ->where('updates.0.subtitle', HelpCenter::defaultSummary())
        ->where('updates.1.version', 'V.1.9.4')
        ->where('updates.1.status', 'new')
        ->where('updates.1.subtitle', 'Fichajes más rápidos')
        ->where('updates.2.kind', 'manual')
        ->where('updates.2.status', 'previous')
        ->where('updates.2.manual.body', '<p>Hola</p>')
        // Solo para quien gestiona: el selector de versiones.
        ->where('releases', null));

    // Abrirla otra vez no duplica la versión.
    $this->actingAs($this->employee)->get('/ayuda');
    expect(HelpRelease::query()->where('month_number', 10)->count())->toBe(1);
});

it('crea, edita con sus cambios en orden y oculta versiones (F-150)', function () {
    Event::fake([HelpCenterChanged::class]);

    $this->actingAs($this->manager)->post('/ayuda/novedades', [
        'major_version' => 1, 'month_number' => 9, 'week_of_month' => 3, 'summary' => '',
        'changes' => [['description' => 'Primero'], ['description' => 'Segundo']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $release = HelpRelease::query()->where('month_number', 9)->firstOrFail();
    [$first, $second] = $release->changes()->get()->all();

    // Reordenar, editar uno, quitar otro y añadir uno nuevo.
    $this->actingAs($this->manager)->put("/ayuda/novedades/{$release->id}", [
        'major_version' => 1, 'month_number' => 9, 'week_of_month' => 3, 'summary' => 'Semana de mejoras',
        'changes' => [['id' => $second->id, 'description' => 'Segundo, editado'], ['description' => 'Nuevo']],
    ])->assertSessionHasNoErrors();

    expect($release->changes()->get()->map(fn ($change) => [$change->description, $change->position])->all())
        ->toBe([['Segundo, editado', 1], ['Nuevo', 2]])
        ->and(HelpRelease::query()->find($release->id)->summary)->toBe('Semana de mejoras')
        ->and($first->fresh())->toBeNull();

    // Otra con la misma serie, mes y semana: no.
    $this->actingAs($this->manager)->post('/ayuda/novedades', ['major_version' => 1, 'month_number' => 9, 'week_of_month' => 3])
        ->assertSessionHasErrors('week_of_month');

    // «Eliminar» la oculta: deja de salir en el listado, pero sigue en el selector para volver a mostrarla.
    $this->actingAs($this->manager)->delete("/ayuda/novedades/{$release->id}")->assertSessionHasNoErrors();
    expect($release->fresh()->is_hidden)->toBeTrue();

    $this->actingAs($this->manager)->get('/ayuda')->assertInertia(fn (Assert $page) => $page
        ->has('updates', 1)
        ->has('releases', 2)
        ->where('releases.1.is_hidden', true));

    $this->actingAs($this->manager)->put("/ayuda/novedades/{$release->id}", [
        'major_version' => 1, 'month_number' => 9, 'week_of_month' => 3, 'summary' => 'Semana de mejoras', 'is_hidden' => false,
    ])->assertSessionHasNoErrors();
    expect($release->fresh()->is_hidden)->toBeFalse()
        ->and($release->changes()->count())->toBe(2);

    Event::assertDispatched(HelpCenterChanged::class, fn (HelpCenterChanged $event) => $event->scope === 'help');
    expect(Activity::query()->where('log_name', 'help_releases')->where('event', 'created')->exists())->toBeTrue();
});

it('las actualizaciones a mano: crear con formato saneado, editar y borrar con sus «me gusta» (F-151)', function () {
    $this->actingAs($this->admin)->post('/ayuda/actualizaciones', [
        'published_on' => '2026-10-02', 'title' => 'Nuevo flujo', 'subtitle' => 'Más fácil',
        'body' => '<p><strong>Hola</strong><script>alert(1)</script></p>',
    ])->assertSessionHasNoErrors();

    $update = HelpManualUpdate::query()->firstOrFail();
    expect($update->body)->toBe('<p><strong>Hola</strong></p>')
        ->and($update->created_by)->toBe($this->admin->id);

    // Los errores dicen el nombre del campo en español (revisión de formularios, D-310).
    $this->actingAs($this->admin)->post('/ayuda/actualizaciones', ['published_on' => '2026-10-02', 'title' => ''])
        ->assertSessionHasErrors(['title' => 'El campo título es obligatorio.', 'subtitle' => 'El campo subtítulo es obligatorio.']);

    $this->actingAs($this->employee)->post('/ayuda/me-gusta', ['kind' => 'manual', 'id' => $update->id]);
    $this->actingAs($this->admin)->delete("/ayuda/actualizaciones/{$update->id}")->assertSessionHasNoErrors();

    expect(HelpManualUpdate::query()->count())->toBe(0)
        ->and(HelpUpdateLike::query()->count())->toBe(0);
});

it('«me gusta» alterna, dice quién lo ha dado y no vale para una versión oculta (F-153)', function () {
    $release = HelpRelease::query()->create(['major_version' => 1, 'month_number' => 9, 'week_of_month' => 4, 'summary' => 'Cosas']);

    $this->actingAs($this->employee)->post('/ayuda/me-gusta', ['kind' => 'release', 'id' => $release->id])->assertRedirect();
    $this->actingAs($this->manager)->post('/ayuda/me-gusta', ['kind' => 'release', 'id' => $release->id]);

    $this->actingAs($this->employee)->get('/ayuda')->assertInertia(fn (Assert $page) => $page
        ->where('updates.1.liked_by_me', true)
        ->where('updates.1.likes.0.name', 'Elena')
        ->where('updates.1.likes.1.name', 'Marta'));

    // Otra vez: lo quita.
    $this->actingAs($this->employee)->post('/ayuda/me-gusta', ['kind' => 'release', 'id' => $release->id]);
    expect(HelpUpdateLike::query()->pluck('user_id')->all())->toBe([$this->manager->id]);

    $release->update(['is_hidden' => true]);
    $this->actingAs($this->employee)->postJson('/ayuda/me-gusta', ['kind' => 'release', 'id' => $release->id])->assertNotFound();
});

it('el manual en PDF y el enlace de soporte: subir, descargar con URL firmada y quitar (F-157)', function () {
    $pdf = UploadedFile::fake()->createWithContent('Manual Audax.pdf', "%PDF-1.4\n%abc\n1 0 obj\n");

    $this->actingAs($this->manager)->post('/ayuda/ajustes', [
        '_method' => 'put', 'support_url' => 'https://soporte.example.com', 'manual' => $pdf,
    ])->assertSessionHasNoErrors();

    $manual = Setting::get('help_manual');
    expect(Setting::get('help_support_url'))->toBe('https://soporte.example.com')
        ->and($manual['name'])->toBe('Manual Audax.pdf');
    Storage::disk('local')->assertExists($manual['path']);

    $settings = app(HelpCenter::class)->settings();
    $this->actingAs($this->employee)->get($settings['manual']['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    // Sin firma, no.
    $this->actingAs($this->employee)->get('/ayuda/manual')->assertForbidden();

    $this->actingAs($this->manager)->post('/ayuda/ajustes', ['_method' => 'put', 'manual' => UploadedFile::fake()->create('notas.txt', 1, 'text/plain')])
        ->assertSessionHasErrors('manual');
    $this->actingAs($this->employee)->put('/ayuda/ajustes', ['support_url' => null])->assertForbidden();

    $this->actingAs($this->manager)->put('/ayuda/ajustes', ['support_url' => '', 'remove_manual' => true])->assertSessionHasNoErrors();
    expect(Setting::get('help_manual'))->toBeNull()
        ->and(Setting::get('help_support_url'))->toBeNull();
    Storage::disk('local')->assertMissing($manual['path']);
    expect(Activity::query()->where('log_name', 'help')->where('event', 'settings_updated')->count())->toBe(2);
});

it('sube el vídeo por trozos, reintenta un trozo sin duplicarlo y rechaza los desordenados (D-207)', function () {
    $start = $this->actingAs($this->manager)->postJson('/ayuda/tutoriales/subidas', ['name' => 'v.mp4', 'size' => strlen($this->video)])->assertCreated();
    $id = (string) $start->json('upload');
    $chunk = fn (int $offset, int $length) => UploadedFile::fake()->createWithContent('blob', substr($this->video, $offset, $length));

    $this->actingAs($this->manager)->post("/ayuda/tutoriales/subidas/{$id}", ['offset' => 0, 'chunk' => $chunk(0, 1000)], ['Accept' => 'application/json'])
        ->assertJsonPath('received', 1000);
    // El mismo trozo otra vez (se perdió la respuesta): nada cambia.
    $this->actingAs($this->manager)->post("/ayuda/tutoriales/subidas/{$id}", ['offset' => 0, 'chunk' => $chunk(0, 1000)], ['Accept' => 'application/json'])
        ->assertJsonPath('received', 1000);
    // Saltarse un trozo: 422.
    $this->actingAs($this->manager)->post("/ayuda/tutoriales/subidas/{$id}", ['offset' => 2000, 'chunk' => $chunk(2000, 1000)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('offset');
    // La subida de otra persona: no.
    $this->actingAs($this->admin)->post("/ayuda/tutoriales/subidas/{$id}", ['offset' => 1000, 'chunk' => $chunk(1000, 1000)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('upload');

    // A medias, no se puede guardar el tutorial.
    $this->actingAs($this->manager)->post('/ayuda/tutoriales', ['title' => 'Fichar', 'upload' => $id])->assertSessionHasErrors('upload');
    expect(HelpTutorial::query()->count())->toBe(0);

    // Más de 200 MB: no se empieza.
    $this->actingAs($this->manager)->postJson('/ayuda/tutoriales/subidas', ['name' => 'grande.mp4', 'size' => TutorialVideoUploads::MAX_BYTES + 1])
        ->assertUnprocessable()->assertJsonValidationErrors('size');
    $this->actingAs($this->employee)->postJson('/ayuda/tutoriales/subidas', ['name' => 'v.mp4', 'size' => 10])->assertForbidden();
});

it('crea el tutorial con su vídeo, lo sirve con Range y URL firmada a la plantilla y lo sustituye (F-155)', function () {
    $release = HelpRelease::query()->create(['major_version' => 1, 'month_number' => 9, 'week_of_month' => 4, 'summary' => 'x']);
    $id = ($this->upload)($this->manager, $this->video, chunk: 1500);

    $this->actingAs($this->manager)->post('/ayuda/tutoriales', [
        'title' => 'Cómo fichar', 'description' => 'Paso a paso', 'help_release_id' => $release->id, 'upload' => $id,
    ])->assertSessionHasNoErrors();

    $tutorial = HelpTutorial::query()->with('video')->firstOrFail();
    $video = $tutorial->video;
    expect($video->mime)->toBe('video/mp4')
        ->and($video->original_name)->toBe('Cómo fichar.mp4')
        ->and($video->size)->toBe(strlen($this->video))
        ->and($video->project_id)->toBeNull()
        ->and(Storage::disk('local')->files(TutorialVideoUploads::DIRECTORY))->toBe([]);

    $props = app(HelpCenter::class)->tutorials();
    expect($props[0]['version'])->toBe('V.1.9.4')
        ->and($props[0]['video_name'])->toBe('Cómo fichar.mp4');

    $url = $props[0]['video_url'];
    $this->actingAs($this->employee)->get($url)->assertOk()
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Accept-Ranges', 'bytes');

    $partial = $this->actingAs($this->employee)->get($url, ['Range' => 'bytes=0-23'])->assertStatus(206);
    expect($partial->headers->get('Content-Range'))->toBe('bytes 0-23/'.strlen($this->video))
        ->and($partial->streamedContent())->toBe(substr($this->video, 0, 24));

    $this->actingAs($this->employee)->get("/ayuda/tutoriales/{$tutorial->id}/video")->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())->get($url)->assertForbidden();

    // Sustituir el vídeo: el anterior desaparece del disco.
    $replacement = ($this->upload)($this->manager, $this->video.'xx');
    $this->actingAs($this->manager)->put("/ayuda/tutoriales/{$tutorial->id}", [
        'title' => 'Cómo fichar (2)', 'help_release_id' => null, 'upload' => $replacement,
    ])->assertSessionHasNoErrors();

    Storage::disk('local')->assertMissing($video->path);
    $current = $tutorial->fresh()->video;
    expect($current->id)->not->toBe($video->id)
        ->and($tutorial->fresh()->help_release_id)->toBeNull()
        ->and(Attachment::query()->whereMorphedTo('attachable', $tutorial)->count())->toBe(1);

    // Editar sin vídeo nuevo conserva el que hay.
    $this->actingAs($this->manager)->put("/ayuda/tutoriales/{$tutorial->id}", ['title' => 'Otro título'])->assertSessionHasNoErrors();
    expect($tutorial->fresh()->video->id)->toBe($current->id);

    $this->actingAs($this->manager)->delete("/ayuda/tutoriales/{$tutorial->id}")->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($current->path);
    expect(HelpTutorial::query()->count())->toBe(0);
});

it('rechaza lo que no es un vídeo aunque se llame .mp4', function () {
    $id = ($this->upload)($this->manager, '%PDF-1.4 no soy un vídeo');

    $this->actingAs($this->manager)->post('/ayuda/tutoriales', ['title' => 'Falso', 'upload' => $id])->assertSessionHasErrors('upload');

    expect(HelpTutorial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->files(TutorialVideoUploads::DIRECTORY))->toBe([]);
});

it('reordena los tutoriales arrastrando y exige la lista completa', function () {
    $a = HelpTutorial::query()->create(['title' => 'A', 'position' => 1]);
    $b = HelpTutorial::query()->create(['title' => 'B', 'position' => 2]);

    $this->actingAs($this->manager)->put('/ayuda/tutoriales/orden', ['ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();
    expect(HelpTutorial::query()->orderBy('position')->pluck('title')->all())->toBe(['B', 'A']);

    $this->actingAs($this->manager)->put('/ayuda/tutoriales/orden', ['ids' => [$b->id]])->assertSessionHasErrors('ids');
});

it('borra las subidas abandonadas pasado un día', function () {
    ($this->upload)($this->manager, $this->video);
    expect(Storage::disk('local')->files(TutorialVideoUploads::DIRECTORY))->toHaveCount(2);

    $this->artisan('help:prune-uploads')->assertSuccessful();
    expect(Storage::disk('local')->files(TutorialVideoUploads::DIRECTORY))->toHaveCount(2);

    foreach (Storage::disk('local')->files(TutorialVideoUploads::DIRECTORY) as $file) {
        touch(Storage::disk('local')->path($file), now()->subHours(25)->getTimestamp());
    }

    $this->artisan('help:prune-uploads')->assertSuccessful();
    expect(Storage::disk('local')->files(TutorialVideoUploads::DIRECTORY))->toBe([]);
});

it('preguntas frecuentes por secciones: crear, mover, reordenar y no borrar una sección con preguntas (F-156)', function () {
    $this->actingAs($this->manager)->post('/ayuda/secciones', ['name' => 'General']);
    $this->actingAs($this->manager)->post('/ayuda/secciones', ['name' => 'Horas']);
    [$general, $hours] = HelpFaqSection::query()->orderBy('position')->get()->all();

    $this->actingAs($this->manager)->post('/ayuda/preguntas', ['help_faq_section_id' => $general->id, 'question' => '¿Qué es?', 'answer' => '<p>Una app</p>']);
    $this->actingAs($this->manager)->post('/ayuda/preguntas', ['help_faq_section_id' => $general->id, 'question' => '¿Quién?', 'answer' => '<p>Audax</p>']);
    $this->actingAs($this->manager)->post('/ayuda/preguntas', ['help_faq_section_id' => $hours->id, 'question' => '¿Cuándo?', 'answer' => '<p>Ya</p>']);
    $this->actingAs($this->manager)->post('/ayuda/preguntas', ['help_faq_section_id' => $hours->id, 'question' => 'Vacía', 'answer' => '<p></p>'])
        ->assertSessionHasErrors('answer');

    $what = HelpFaq::query()->where('question', '¿Qué es?')->firstOrFail();
    $who = HelpFaq::query()->where('question', '¿Quién?')->firstOrFail();

    $this->actingAs($this->manager)->put('/ayuda/preguntas/orden', ['help_faq_section_id' => $general->id, 'ids' => [$who->id, $what->id]])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->put('/ayuda/secciones/orden', ['ids' => [$hours->id, $general->id]])->assertSessionHasNoErrors();

    // Al cambiar de sección, al final de la nueva.
    $this->actingAs($this->manager)->put("/ayuda/preguntas/{$what->id}", ['help_faq_section_id' => $hours->id, 'question' => '¿Qué es Audax?', 'answer' => '<p>Una app</p>'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->employee)->get('/ayuda?pestana=preguntas')->assertInertia(fn (Assert $page) => $page
        ->has('faq_sections', 2)
        ->where('faq_sections.0.name', 'Horas')
        ->where('faq_sections.0.faqs.0.question', '¿Cuándo?')
        ->where('faq_sections.0.faqs.1.question', '¿Qué es Audax?')
        ->where('faq_sections.1.faqs.0.question', '¿Quién?'));

    $this->actingAs($this->manager)->delete("/ayuda/secciones/{$hours->id}")->assertSessionHasErrors('section');
    $this->actingAs($this->manager)->delete("/ayuda/preguntas/{$who->id}")->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->delete("/ayuda/secciones/{$general->id}")->assertSessionHasNoErrors();
    expect(HelpFaqSection::query()->pluck('name')->all())->toBe(['Horas']);

    $this->actingAs($this->employee)->put("/ayuda/preguntas/{$what->id}", ['help_faq_section_id' => $hours->id, 'question' => 'x', 'answer' => 'y'])->assertForbidden();
});

it('el canal de tiempo real «help» es de quien usa la ayuda (F-170)', function () {
    $channels = app(BroadcastManager::class)->driver()->getChannels();
    $callback = $channels->get('help');

    expect($callback($this->employee))->toBeTrue()
        ->and($callback(User::factory()->collaborator()->create()))->toBeFalse();

    $event = new HelpCenterChanged('suggestions', 7);
    expect($event->broadcastAs())->toBe('help.changed')
        ->and($event->broadcastWith())->toBe(['scope' => 'suggestions', 'post_id' => 7])
        ->and($event->broadcastOn()[0]->name)->toBe('private-help');
});

it('la URL firmada del vídeo caduca', function () {
    $tutorial = HelpTutorial::query()->create(['title' => 'A', 'position' => 1]);
    $expired = URL::temporarySignedRoute('help.tutorials.video', now()->subMinute(), ['tutorial' => $tutorial->id], absolute: false);

    $this->actingAs($this->employee)->get($expired)->assertForbidden();
});
