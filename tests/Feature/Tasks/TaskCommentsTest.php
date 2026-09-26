<?php

use App\Models\Attachment;
use App\Models\CommentReaction;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\Tasks\TaskCommentedNotification;
use App\Notifications\Tasks\TaskMentionedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Comentarios (SPEC §6): cuerpo saneado, menciones que avisan, editar y borrar los propios,
| reacciones y adjuntos.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Storage::fake('local');
    Notification::fake();

    $this->author = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Revisar la maqueta']);
    $this->mention = fn (User $user): string => sprintf('<span data-type="mention" data-id="%d" data-label="%s">@%s</span>', $user->id, e($user->name), e($user->name));
    $this->comment = fn (array $data, ?User $as = null) => $this->actingAs($as ?? $this->author)
        ->from("/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}")
        ->post("/tareas/{$this->task->id}/comentarios", $data);
});

it('publica un comentario saneado (sin XSS) y vuelve al panel', function () {
    ($this->comment)(['body' => '<p>Mira esto <script>alert(1)</script><a href="javascript:alert(1)" onclick="x()">enlace</a> <a href="https://audaxstudio.com">web</a><img src=x onerror=alert(1)></p>'])
        ->assertRedirect("/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}")
        ->assertSessionHasNoErrors();

    $body = TaskComment::query()->firstOrFail()->body;

    expect($body)->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('onclick')
        ->not->toContain('<img')
        ->toContain('href="https://audaxstudio.com"')
        ->toContain('rel="noopener noreferrer nofollow"');
});

it('no publica comentarios vacíos (ni solo con etiquetas)', function () {
    ($this->comment)(['body' => '<p>   </p>'])->assertSessionHasErrors(['body' => __('tasks.errors.comment_empty')]);
    ($this->comment)(['body' => '<script>alert(1)</script>'])->assertSessionHasErrors('body');

    expect(TaskComment::query()->count())->toBe(0);
});

it('admite un comentario solo con adjuntos', function () {
    ($this->comment)(['files' => [UploadedFile::fake()->create('acta.pdf', 20, 'application/pdf')]])->assertSessionHasNoErrors();

    $comment = TaskComment::query()->firstOrFail();

    expect($comment->body)->toBe('')
        ->and($comment->attachments()->count())->toBe(1)
        ->and($comment->attachments()->first()->project_id)->toBe($this->project->id);
});

it('avisa solo a los mencionados internos y activos, sin el autor, y guarda las menciones', function () {
    $ana = User::factory()->employee()->create(['name' => 'Ana']);
    $inactive = User::factory()->employee()->inactive()->create();
    $client = User::factory()->client()->create();

    ($this->comment)(['body' => '<p>Hola '.($this->mention)($ana).' '.($this->mention)($inactive).' '.($this->mention)($client).' '.($this->mention)($this->author).'</p>'])
        ->assertSessionHasNoErrors();

    // Se guardan las menciones a internos activos (también la propia); solo avisan las de otros.
    expect(TaskComment::query()->firstOrFail()->mentioned_user_ids)->toBe([$this->author->id, $ana->id]);

    Notification::assertSentTo($ana, TaskMentionedNotification::class, function (TaskMentionedNotification $notification) use ($ana): bool {
        $data = $notification->toArray($ana);

        return $data['kind'] === 'task.mentioned'
            && $data['url'] === "/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}"
            && str_contains($data['title'], 'Revisar la maqueta');
    });
    Notification::assertNotSentTo([$inactive, $client, $this->author], TaskMentionedNotification::class);
});

it('avisa a los seguidores del comentario, salvo al autor y a quien ya avisó la mención', function () {
    $watcher = User::factory()->employee()->create();
    $mentionedWatcher = User::factory()->employee()->create();
    $this->task->watchers()->attach([$watcher->id, $mentionedWatcher->id, $this->author->id]);

    ($this->comment)(['body' => '<p>Listo '.($this->mention)($mentionedWatcher).'</p>'])->assertSessionHasNoErrors();

    Notification::assertSentTo($watcher, TaskCommentedNotification::class);
    Notification::assertSentTo($mentionedWatcher, TaskMentionedNotification::class);
    Notification::assertNotSentTo($mentionedWatcher, TaskCommentedNotification::class);
    Notification::assertNotSentTo($this->author, TaskCommentedNotification::class);
});

it('el autor edita su comentario: queda marcado como editado y solo avisa a los mencionados nuevos', function () {
    $ana = User::factory()->employee()->create();
    $luis = User::factory()->employee()->create();
    $comment = TaskComment::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->author->id,
        'body' => '<p>'.($this->mention)($ana).'</p>',
        'mentioned_user_ids' => [$ana->id],
    ]);

    $this->actingAs($this->author)
        ->patch("/comentarios/{$comment->id}", ['body' => '<p>'.($this->mention)($ana).' y '.($this->mention)($luis).'<script>x</script></p>'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $comment->refresh();
    expect($comment->edited_at)->not->toBeNull()
        ->and($comment->body)->not->toContain('<script')
        ->and($comment->mentioned_user_ids)->toBe([$ana->id, $luis->id]);

    Notification::assertSentTo($luis, TaskMentionedNotification::class);
    Notification::assertNotSentTo($ana, TaskMentionedNotification::class);
});

it('nadie edita ni borra comentarios ajenos; el admin puede borrarlos pero no editarlos', function () {
    $comment = TaskComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->author->id, 'body' => '<p>Original</p>']);
    $other = userWithRole('employee');
    $admin = userWithRole('admin');
    $manager = userWithRole('department_manager');

    foreach ([$other, $manager, $admin] as $user) {
        $this->actingAs($user)->patch("/comentarios/{$comment->id}", ['body' => '<p>Cambiado</p>'])->assertForbidden();
    }

    $this->actingAs($other)->delete("/comentarios/{$comment->id}")->assertForbidden();
    $this->actingAs($manager)->delete("/comentarios/{$comment->id}")->assertForbidden();
    expect($comment->fresh()->body)->toBe('<p>Original</p>');

    $this->actingAs($admin)->delete("/comentarios/{$comment->id}")->assertRedirect();
    expect(TaskComment::query()->find($comment->id))->toBeNull();
});

it('el autor borra su comentario y sus adjuntos (ficheros incluidos)', function () {
    ($this->comment)(['body' => '<p>Con archivo</p>', 'files' => [UploadedFile::fake()->image('foto.png', 600, 300)]])->assertSessionHasNoErrors();
    $comment = TaskComment::query()->firstOrFail();
    $attachment = $comment->attachments()->firstOrFail();

    Storage::disk('local')->assertExists([$attachment->path, (string) $attachment->thumbnail_path]);

    $this->actingAs($this->author)->delete("/comentarios/{$comment->id}")->assertRedirect();

    Storage::disk('local')->assertMissing([$attachment->path, (string) $attachment->thumbnail_path]);
    expect(TaskComment::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
});

it('las reacciones se activan y se quitan, solo con los emojis permitidos', function () {
    $comment = TaskComment::factory()->create(['task_id' => $this->task->id]);
    $react = fn (string $emoji) => $this->actingAs($this->author)->post("/comentarios/{$comment->id}/reacciones", ['emoji' => $emoji]);

    $react('👍')->assertRedirect()->assertSessionHasNoErrors();
    expect(CommentReaction::query()->where('task_comment_id', $comment->id)->count())->toBe(1);

    $react('🎉')->assertSessionHasNoErrors();
    $react('👍')->assertSessionHasNoErrors();
    expect(CommentReaction::query()->pluck('emoji')->all())->toBe(['🎉']);

    $react('💩')->assertSessionHasErrors(['emoji' => __('tasks.errors.reaction_invalid')]);
    $react('<script>')->assertSessionHasErrors('emoji');
});

it('el panel agrupa las reacciones y marca las propias', function () {
    $comment = TaskComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->author->id]);
    $other = User::factory()->employee()->create(['name' => 'Berta']);
    CommentReaction::query()->create(['task_comment_id' => $comment->id, 'user_id' => $this->author->id, 'emoji' => '👍']);
    CommentReaction::query()->create(['task_comment_id' => $comment->id, 'user_id' => $other->id, 'emoji' => '👍']);
    CommentReaction::query()->create(['task_comment_id' => $comment->id, 'user_id' => $other->id, 'emoji' => '👀']);

    $panel = $this->actingAs($this->author)
        ->get("/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}")
        ->viewData('page')['props']['panel'];

    expect($panel['comments'][0]['reactions'])->toBe([
        ['emoji' => '👍', 'count' => 2, 'reacted' => true, 'users' => [$this->author->name, 'Berta']],
        ['emoji' => '👀', 'count' => 1, 'reacted' => false, 'users' => ['Berta']],
    ])
        ->and($panel['comments'][0]['can_update'])->toBeTrue()
        ->and($panel['comments'][0]['can_delete'])->toBeTrue()
        ->and($panel['reaction_emojis'])->toBe(CommentReaction::EMOJIS);
});

it('los comentarios de una tarea borrada ya no se editan ni reciben reacciones', function () {
    $comment = TaskComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->author->id, 'body' => '<p>Original</p>']);
    $this->task->delete();

    $this->actingAs($this->author)->patch("/comentarios/{$comment->id}", ['body' => '<p>Cambiado</p>'])->assertNotFound();
    $this->actingAs($this->author)->post("/comentarios/{$comment->id}/reacciones", ['emoji' => '👍'])->assertNotFound();
    $this->actingAs($this->author)->post("/tareas/{$this->task->id}/comentarios", ['body' => '<p>Hola</p>'])->assertNotFound();

    expect($comment->fresh()?->body)->toBe('<p>Original</p>')
        ->and($comment->fresh()?->edited_at)->toBeNull()
        ->and(CommentReaction::query()->count())->toBe(0);
});
