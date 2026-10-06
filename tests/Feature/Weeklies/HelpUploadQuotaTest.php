<?php

use App\Domain\System\DiskSpace;
use App\Domain\System\DiskUsage;
use App\Domain\Weeklies\Help\TutorialVideoUploads;
use App\Models\Attachment;
use App\Models\SuggestionBoard;
use App\Models\SuggestionPost;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Cuotas de las subidas de la ayuda y las sugerencias (D-223, hallazgo 4 de la revisión de seguridad
| de la Fase 10): el servidor es compartido con 38 webs y ninguna subida puede llenar el disco.
*/

/** Un disco con `$freeMb` libres. */
function helpDiskWithFree(int $freeMb): void
{
    app()->instance(DiskUsage::class, new class($freeMb) implements DiskUsage
    {
        public function __construct(private readonly int $freeMb) {}

        public function measure(string $path): ?DiskSpace
        {
            return new DiskSpace(100_000 * 1024 * 1024, $this->freeMb * 1024 * 1024);
        }
    });
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    Notification::fake();
    $this->manager = userWithRole('department_manager');
    $this->other = userWithRole('department_manager');
    $this->elena = userWithRole('employee');
    $this->board = SuggestionBoard::query()->where('slug', 'sugerencias')->firstOrFail();
});

it('vídeos: una subida abierta por persona; empezar otra descarta la anterior', function () {
    $first = $this->actingAs($this->manager)->postJson('/ayuda/tutoriales/subidas', ['name' => 'a.mp4', 'size' => 1000])->assertCreated()->json('upload');
    $second = $this->actingAs($this->manager)->postJson('/ayuda/tutoriales/subidas', ['name' => 'b.mp4', 'size' => 1000])->assertCreated()->json('upload');

    expect(Storage::disk('local')->exists(TutorialVideoUploads::DIRECTORY."/{$first}.json"))->toBeFalse()
        ->and(Storage::disk('local')->exists(TutorialVideoUploads::DIRECTORY."/{$second}.json"))->toBeTrue();
});

it('vídeos: un tope de lo que está a medio subir entre todas las personas', function () {
    config(['help.uploads.tutorial_pending_mb' => 300]);

    $this->actingAs($this->other)->postJson('/ayuda/tutoriales/subidas', ['name' => 'a.mp4', 'size' => 150 * 1024 * 1024])->assertCreated();
    $this->actingAs($this->manager)->postJson('/ayuda/tutoriales/subidas', ['name' => 'b.mp4', 'size' => 160 * 1024 * 1024])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['size' => __('help.quota.tutorials_busy')]);
    $this->actingAs($this->manager)->postJson('/ayuda/tutoriales/subidas', ['name' => 'b.mp4', 'size' => 140 * 1024 * 1024])->assertCreated();
});

it('vídeos: sin espacio libre en el disco no se empieza ni se añade un trozo', function () {
    config(['help.uploads.min_free_mb' => 5120]);
    helpDiskWithFree(6000);
    $id = $this->actingAs($this->manager)->postJson('/ayuda/tutoriales/subidas', ['name' => 'a.mp4', 'size' => 100])->assertCreated()->json('upload');

    helpDiskWithFree(5000);
    $this->actingAs($this->manager)->post("/ayuda/tutoriales/subidas/{$id}", ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('blob', str_repeat('a', 100))], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chunk' => __('help.quota.disk_full')]);
    $this->actingAs($this->other)->postJson('/ayuda/tutoriales/subidas', ['name' => 'b.mp4', 'size' => 100])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['size' => __('help.quota.disk_full')]);
});

it('sugerencias: cada persona tiene un máximo de adjuntos; quitar los suyos libera espacio', function () {
    config(['help.uploads.suggestion_user_mb' => 1]);
    $post = SuggestionPost::factory()->create(['suggestion_board_id' => $this->board->id, 'author_id' => $this->elena->id]);
    $old = Attachment::factory()->create(['user_id' => $this->elena->id, 'size' => 900 * 1024, 'attachable_type' => $post->getMorphClass(), 'attachable_id' => $post->id, 'project_id' => null]);
    $file = fn () => UploadedFile::fake()->createWithContent('nota.txt', str_repeat('a', 200 * 1024));

    $this->actingAs($this->elena)->post('/ayuda/sugerencias', [
        'title' => 'Otra', 'body' => '<p>x</p>', 'suggestion_board_id' => $this->board->id, 'files' => [$file()],
    ])->assertSessionHasErrors(['files' => __('help.quota.suggestions_full', ['limit' => 1])]);
    $this->actingAs($this->elena)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>+1</p>', 'files' => [$file()]])
        ->assertSessionHasErrors('files');

    // Quitando el adjunto anterior, el nuevo cabe.
    $this->actingAs($this->elena)->post("/ayuda/sugerencias/{$post->id}", [
        '_method' => 'put', 'title' => $post->title, 'body' => '<p>x</p>', 'suggestion_board_id' => $this->board->id,
        'remove_attachment_ids' => [$old->id], 'files' => [$file()],
    ])->assertSessionHasNoErrors();

    // La cuota es de cada persona.
    $this->actingAs($this->manager)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>+1</p>', 'files' => [$file()]])
        ->assertSessionHasNoErrors();
});

it('sugerencias: sin espacio libre en el disco no se aceptan adjuntos (el texto sí)', function () {
    config(['help.uploads.min_free_mb' => 5120]);
    helpDiskWithFree(1000);

    $this->actingAs($this->elena)->post('/ayuda/sugerencias', [
        'title' => 'Con archivo', 'body' => '<p>x</p>', 'suggestion_board_id' => $this->board->id,
        'files' => [UploadedFile::fake()->createWithContent('nota.txt', 'hola')],
    ])->assertSessionHasErrors(['files' => __('help.quota.disk_full')]);
    $this->actingAs($this->elena)->post('/ayuda/sugerencias', ['title' => 'Sin archivo', 'body' => '<p>x</p>', 'suggestion_board_id' => $this->board->id])
        ->assertSessionHasNoErrors();
});

it('editar sugerencias y comentarios tiene su propio límite de peticiones', function () {
    $routes = collect(app('router')->getRoutes())->keyBy(fn ($route) => $route->getName());

    expect($routes['suggestions.update']->gatherMiddleware())->toContain('throttle:30,1,suggestions.update')
        ->and($routes['suggestions.comments.update']->gatherMiddleware())->toContain('throttle:60,1,suggestions.comments.update');
});
