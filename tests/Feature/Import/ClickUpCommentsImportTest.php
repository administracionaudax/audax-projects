<?php

use App\Domain\Import\ClickUp\Comments\TaskCommentImporter;
use App\Models\ImportRef;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
| Comentarios de las tareas de ClickUp (app:import-clickup-comments): van a la tarea importada por
| import_refs, con su autor por email, sus fechas y sus menciones; sin ClickBot, sin duplicar al
| repetir y sin avisar a nadie.
*/

beforeEach(function () {
    Notification::fake();
    $this->task = Task::factory()->create();
    ImportRef::query()->create(['source' => 'clickup', 'kind' => 'task', 'external_id' => 'cu-1', 'local_type' => 'task', 'local_id' => $this->task->id]);
    $this->jero = User::factory()->create(['email' => 'jero@audaxstudio.com', 'name' => 'Jero']);
    $this->ana = User::factory()->create(['email' => 'ana@audaxstudio.com', 'name' => 'Ana']);
    $this->path = tempnam(sys_get_temp_dir(), 'comentarios');
    file_put_contents($this->path, json_encode([
        'cu-1' => [
            [
                'id' => 'c-1',
                'date' => '1753347151314',
                'user' => ['email' => 'Jero@audaxstudio.com'],
                'comment' => [
                    ['text' => 'Revisado con ', 'attributes' => []],
                    ['type' => 'tag', 'text' => '@Ana', 'user' => ['email' => 'ana@audaxstudio.com']],
                    ['text' => ' y ', 'attributes' => []],
                    ['text' => 'aprobado', 'attributes' => ['bold' => true]],
                    ['text' => "\n<script>x</script>\n", 'attributes' => []],
                    ['type' => 'link_mention', 'link_mention' => ['url' => 'https://docs.google.com/x']],
                ],
            ],
            ['id' => 'c-2', 'date' => '1753347151314', 'user' => ['email' => 'clickbot@clickup.com'], 'comment' => [['text' => 'Revisión: Tarea en fecha límite']]],
            ['id' => 'c-3', 'date' => '1753347151314', 'user' => ['email' => 'nadie@otro.com'], 'comment' => [['text' => 'Hola']]],
        ],
        'cu-sin-tarea' => [
            ['id' => 'c-4', 'date' => '1753347151314', 'user' => ['email' => 'jero@audaxstudio.com'], 'comment' => [['text' => 'Hola']]],
        ],
    ]));
});

afterEach(fn () => @unlink($this->path));

it('importa el comentario con su autor, fecha, formato y mención, sin ClickBot y sin avisos', function () {
    $this->artisan('app:import-clickup-comments', ['fichero' => $this->path])->assertSuccessful();

    $comment = TaskComment::query()->sole();

    expect($comment->task_id)->toBe($this->task->id)
        ->and($comment->user_id)->toBe($this->jero->id)
        ->and($comment->created_at?->getTimestampMs())->toBe(1753347151000)
        ->and($comment->mentioned_user_ids)->toBe([$this->ana->id])
        ->and($comment->body)->toContain('<strong>aprobado</strong>')
        ->and($comment->body)->toContain('data-type="mention"')
        ->and($comment->body)->toContain('href="https://docs.google.com/x"')
        ->and($comment->body)->not->toContain('<script>');
    Notification::assertNothingSent();
});

it('repetir no duplica y la simulación no guarda', function () {
    $this->artisan('app:import-clickup-comments', ['fichero' => $this->path, '--dry-run' => true])->assertSuccessful();
    expect(TaskComment::query()->count())->toBe(0);

    $this->artisan('app:import-clickup-comments', ['fichero' => $this->path])->assertSuccessful();
    $this->artisan('app:import-clickup-comments', ['fichero' => $this->path])->assertSuccessful();

    expect(TaskComment::query()->count())->toBe(1);
});

it('cuenta lo que no importa', function () {
    $counts = app(TaskCommentImporter::class)->import(json_decode((string) file_get_contents($this->path), true));

    expect($counts)->toMatchArray(['created' => 1, 'bot' => 1, 'no_author' => 1, 'no_task' => 1]);
});
