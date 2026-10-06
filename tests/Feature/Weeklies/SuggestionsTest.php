<?php

use App\Enums\SuggestionReaction;
use App\Enums\SuggestionStatus;
use App\Http\Resources\Tasks\AttachmentResource;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use App\Models\SuggestionComment;
use App\Models\SuggestionCommentReaction;
use App\Models\SuggestionPost;
use App\Models\SuggestionStatusEvent;
use App\Models\SuggestionVote;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\Suggestions\SuggestionMentioned;
use App\Notifications\Suggestions\SuggestionReplied;
use App\Notifications\Suggestions\SuggestionStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Sugerencias del centro de ayuda (10.7, F-159 a F-170, D-209 a D-211): la pestaña con Roadmap y
| Feedback, crear con adjuntos y «similares», votar, comentar con respuestas anidadas, adjuntos,
| menciones y reacciones, el estado con nota oficial e historial, arrastrar en el roadmap, tableros
| y categorías, y los avisos (estado de tu sugerencia, te responden y te mencionan).
*/

function mention(User $user): string
{
    return '<span data-type="mention" data-id="'.$user->id.'" data-label="'.$user->name.'">@'.$user->name.'</span>';
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    Notification::fake();
    $this->manager = userWithRole('department_manager', ['name' => 'Marta']);
    $this->elena = userWithRole('employee', ['name' => 'Elena']);
    $this->pablo = userWithRole('employee', ['name' => 'Pablo']);
    $this->board = SuggestionBoard::query()->where('slug', 'sugerencias')->firstOrFail();
    $this->bugs = SuggestionCategory::query()->where('slug', SuggestionCategory::BUGS_SLUG)->firstOrFail();
    $this->ideas = SuggestionCategory::factory()->create(['suggestion_board_id' => $this->board->id, 'name' => 'Ideas', 'slug' => 'ideas', 'position' => 1]);
    $this->post = fn (array $attributes = []): SuggestionPost => SuggestionPost::factory()->create([
        'suggestion_board_id' => $this->board->id,
        'author_id' => $this->elena->id,
        ...$attributes,
    ]);
});

it('crea una sugerencia con formato saneado, adjuntos y slug único; avisa a quien menciona (F-161)', function () {
    $this->actingAs($this->elena)->post('/ayuda/sugerencias', [
        'title' => 'Modo oscuro',
        'body' => '<p>Por favor <script>x</script>'.mention($this->pablo).'</p>',
        'suggestion_board_id' => $this->board->id,
        'suggestion_category_id' => $this->ideas->id,
        'files' => [UploadedFile::fake()->image('captura.png', 20, 20), UploadedFile::fake()->createWithContent('nota.txt', 'hola')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $post = SuggestionPost::query()->with('attachments')->firstOrFail();
    expect($post->status)->toBe(SuggestionStatus::Open)
        ->and($post->slug)->toBe('modo-oscuro')
        ->and($post->body)->not->toContain('script')
        ->and($post->attachments)->toHaveCount(2)
        ->and($post->attachments->first()->path)->toStartWith("attachments/suggestions/{$post->id}/");

    Notification::assertSentTo($this->pablo, SuggestionMentioned::class, fn ($n) => $n->postId === $post->id && $n->context === 'post');
    Notification::assertNotSentTo($this->elena, SuggestionMentioned::class);

    // El mismo título en el mismo tablero: otro slug.
    $this->actingAs($this->pablo)->post('/ayuda/sugerencias', ['title' => 'Modo oscuro', 'body' => '<p>Yo también</p>', 'suggestion_board_id' => $this->board->id]);
    expect(SuggestionPost::query()->latest('id')->value('slug'))->toBe('modo-oscuro-2');

    // Los adjuntos los ve la plantilla con su URL firmada; un colaborador externo no.
    $url = AttachmentResource::downloadUrl($post->attachments->first());
    $this->actingAs($this->pablo)->get($url)->assertOk();
    $this->actingAs(User::factory()->collaborator()->create())->get($url)->assertForbidden();
});

it('una sugerencia o un bug lleva vídeos de la pantalla (MP4, MOV y WebM); las tareas, no (D-235)', function () {
    $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
    $mov = "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  ".str_repeat("\x00", 64);
    $webm = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02".str_repeat("\x00", 32);

    $this->actingAs($this->elena)->post('/ayuda/sugerencias', [
        'title' => 'Falla el guardado',
        'body' => '<p>Mira el vídeo</p>',
        'suggestion_board_id' => $this->board->id,
        'files' => [
            UploadedFile::fake()->createWithContent('pantalla.mp4', $mp4),
            UploadedFile::fake()->createWithContent('pantalla.mov', $mov),
            UploadedFile::fake()->createWithContent('pantalla.webm', $webm),
        ],
    ])->assertSessionHasNoErrors();

    $post = SuggestionPost::query()->with('attachments')->firstOrFail();
    expect($post->attachments->pluck('mime')->map(fn ($mime) => $mime === 'application/mp4' ? 'video/mp4' : $mime)->sort()->values()->all())->toBe(['video/mp4', 'video/quicktime', 'video/webm'])
        ->and($post->attachments->pluck('path')->map(fn ($path) => pathinfo($path, PATHINFO_EXTENSION))->sort()->values()->all())->toBe(['mov', 'mp4', 'webm']);

    // Un comentario también.
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>Me pasa</p>', 'files' => [UploadedFile::fake()->createWithContent('yo.mp4', $mp4)]])
        ->assertSessionHasNoErrors();

    // Una extensión de vídeo que no es de las admitidas, no.
    $this->actingAs($this->elena)->post('/ayuda/sugerencias', ['title' => 'x', 'body' => '<p>x</p>', 'suggestion_board_id' => $this->board->id, 'files' => [UploadedFile::fake()->createWithContent('pantalla.avi', $mp4)]])
        ->assertSessionHasErrors('files.0');

    // En una tarea, un vídeo no se admite (D-037).
    TaskStatus::ensureDefaults();
    $project = Project::factory()->create();
    $project->addMember($this->elena);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $this->actingAs($this->elena)->from('/')->post("/tareas/{$task->id}/adjuntos", ['files' => [UploadedFile::fake()->createWithContent('pantalla.mp4', $mp4)]])
        ->assertSessionHasErrors('files.0');
});

it('valida el tablero, la categoría y el detalle', function () {
    $hidden = SuggestionBoard::factory()->create(['is_active' => false]);
    $other = SuggestionBoard::factory()->create();
    $foreign = SuggestionCategory::factory()->create(['suggestion_board_id' => $other->id]);

    $this->actingAs($this->elena)->post('/ayuda/sugerencias', ['title' => 'x', 'body' => '<p>x</p>', 'suggestion_board_id' => $hidden->id])
        ->assertSessionHasErrors('suggestion_board_id');
    $this->actingAs($this->elena)->post('/ayuda/sugerencias', ['title' => 'x', 'body' => '<p>x</p>', 'suggestion_board_id' => $this->board->id, 'suggestion_category_id' => $foreign->id])
        ->assertSessionHasErrors('suggestion_category_id');
    $this->actingAs($this->elena)->post('/ayuda/sugerencias', ['title' => 'x', 'body' => '<p> </p>', 'suggestion_board_id' => $this->board->id])
        ->assertSessionHasErrors('body');
    $this->actingAs(User::factory()->collaborator()->create())->post('/ayuda/sugerencias', ['title' => 'x', 'body' => '<p>x</p>', 'suggestion_board_id' => $this->board->id])
        ->assertForbidden();
    expect(SuggestionPost::query()->count())->toBe(0);
});

it('edita o borra el autor o quien gestiona, con sus adjuntos y los de sus comentarios (F-164)', function () {
    $post = ($this->post)(['title' => 'Exportar a PDF']);
    $this->actingAs($this->elena)->post("/ayuda/sugerencias/{$post->id}", [
        '_method' => 'put', 'title' => 'Exportar a PDF y Excel', 'body' => '<p>Detalle</p>',
        'suggestion_board_id' => $this->board->id, 'files' => [UploadedFile::fake()->createWithContent('a.txt', 'a')],
    ])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->firstOrFail();

    $this->actingAs($this->pablo)->put("/ayuda/sugerencias/{$post->id}", ['title' => 'Mío', 'body' => '<p>x</p>', 'suggestion_board_id' => $this->board->id])->assertForbidden();
    $this->actingAs($this->pablo)->delete("/ayuda/sugerencias/{$post->id}")->assertForbidden();

    // Quien gestiona quita el adjunto al editar.
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/{$post->id}", [
        'title' => 'Exportar a PDF y Excel', 'body' => '<p>Detalle</p>', 'suggestion_board_id' => $this->board->id,
        'remove_attachment_ids' => [$attachment->id],
    ])->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($attachment->path);
    expect($post->fresh()->slug)->toBe('exportar-a-pdf-y-excel');

    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>+1</p>', 'files' => [UploadedFile::fake()->createWithContent('b.txt', 'b')]]);
    $commentFile = Attachment::query()->latest('id')->firstOrFail();
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/voto");

    $this->actingAs($this->manager)->delete("/ayuda/sugerencias/{$post->id}")->assertRedirect('/ayuda?pestana=sugerencias&vista=feedback');
    Storage::disk('local')->assertMissing($commentFile->path);
    expect(SuggestionPost::query()->count())->toBe(0)
        ->and(SuggestionComment::query()->count())->toBe(0)
        ->and(SuggestionVote::query()->count())->toBe(0);
});

it('un voto por persona: alterna, recuenta y dice quién ha votado (F-163)', function () {
    $post = ($this->post)();

    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/voto")->assertRedirect();
    $this->actingAs($this->manager)->post("/ayuda/sugerencias/{$post->id}/voto");
    expect($post->fresh()->vote_count)->toBe(2);

    $this->actingAs($this->pablo)->get("/ayuda/sugerencias/{$post->id}")->assertInertia(fn (Assert $page) => $page
        ->component('help/index')
        ->where('tab', 'sugerencias')
        ->where('suggestions.post.id', $post->id)
        ->where('suggestions.post.voted_by_me', true)
        ->where('suggestions.post.vote_count', 2)
        ->where('suggestions.post.voters.0.name', 'Pablo')
        ->where('suggestions.post.voters.1.name', 'Marta')
        ->where('suggestions.post.can.update', false)
        ->where('suggestions.post.can.moderate', false));

    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/voto");
    expect($post->fresh()->vote_count)->toBe(1)
        ->and(SuggestionVote::query()->pluck('user_id')->all())->toBe([$this->manager->id]);

    // El índice único impide dos votos de la misma persona (en un punto de guardado, por PostgreSQL).
    expect(inSavepoint(fn () => SuggestionVote::query()->insert([
        ['suggestion_post_id' => $post->id, 'user_id' => $this->manager->id, 'created_at' => now()],
    ])))->toThrow(UniqueConstraintViolationException::class)
        ->and(SuggestionVote::query()->count())->toBe(1);
});

it('comentarios con respuestas anidadas, adjuntos, edición y borrado con sus respuestas (F-165)', function () {
    $post = ($this->post)();

    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>Primero</p>'])->assertSessionHasNoErrors();
    $root = SuggestionComment::query()->firstOrFail();
    $this->actingAs($this->elena)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>Respuesta</p>', 'parent_id' => $root->id]);
    $reply = SuggestionComment::query()->latest('id')->firstOrFail();
    $this->actingAs($this->manager)->post("/ayuda/sugerencias/{$post->id}/comentarios", [
        'body' => '', 'parent_id' => $reply->id, 'files' => [UploadedFile::fake()->createWithContent('c.txt', 'c')],
    ])->assertSessionHasNoErrors();
    $deep = SuggestionComment::query()->latest('id')->firstOrFail();

    expect($post->fresh()->comment_count)->toBe(3);

    $this->actingAs($this->elena)->get("/ayuda/sugerencias/{$post->id}")->assertInertia(fn (Assert $page) => $page
        ->has('suggestions.post.comments', 1)
        ->where('suggestions.post.comments.0.body', '<p>Primero</p>')
        ->where('suggestions.post.comments.0.replies.0.body', '<p>Respuesta</p>')
        ->where('suggestions.post.comments.0.replies.0.replies.0.id', $deep->id)
        ->has('suggestions.post.comments.0.replies.0.replies.0.attachments', 1));

    // Vacío y sin adjuntos: no. Respuesta a un comentario de otra sugerencia: no.
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p></p>'])->assertSessionHasErrors('body');
    $other = ($this->post)();
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$other->id}/comentarios", ['body' => '<p>x</p>', 'parent_id' => $root->id])->assertSessionHasErrors('parent_id');

    // Edita solo su autor; borra el autor o quien gestiona.
    $this->actingAs($this->elena)->put("/ayuda/sugerencias/comentarios/{$root->id}", ['body' => '<p>No</p>'])->assertForbidden();
    $this->actingAs($this->pablo)->put("/ayuda/sugerencias/comentarios/{$root->id}", ['body' => '<p>Primero, editado</p>'])->assertSessionHasNoErrors();
    expect($root->fresh()->body)->toBe('<p>Primero, editado</p>')
        ->and($root->fresh()->edited_at)->not->toBeNull();
    $this->actingAs($this->elena)->delete("/ayuda/sugerencias/comentarios/{$root->id}")->assertForbidden();

    $file = Attachment::query()->firstOrFail();
    $this->actingAs($this->manager)->delete("/ayuda/sugerencias/comentarios/{$root->id}")->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($file->path);
    expect(SuggestionComment::query()->where('suggestion_post_id', $post->id)->count())->toBe(0)
        ->and($post->fresh()->comment_count)->toBe(0);
});

it('avisa cuando te responden o te mencionan, una vez y nunca a uno mismo (D-209)', function () {
    $post = ($this->post)(['title' => 'Atajos de teclado']);

    // Comentario en la sugerencia de Elena: le avisa a ella.
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>¡Sí!</p>']);
    Notification::assertSentTo($this->elena, SuggestionReplied::class, fn ($n) => $n->context === 'post' && $n->postTitle === 'Atajos de teclado');
    $root = SuggestionComment::query()->firstOrFail();

    // Elena responde a Pablo mencionándole: solo la mención, no además la respuesta.
    $this->actingAs($this->elena)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>Gracias '.mention($this->pablo).'</p>', 'parent_id' => $root->id]);
    Notification::assertSentToTimes($this->pablo, SuggestionMentioned::class, 1);
    Notification::assertNotSentTo($this->pablo, SuggestionReplied::class);

    // Marta responde a Pablo: respuesta a su comentario.
    $this->actingAs($this->manager)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>Ok</p>', 'parent_id' => $root->id]);
    Notification::assertSentTo($this->pablo, SuggestionReplied::class, fn ($n) => $n->context === 'comment');

    // Elena comenta en su propia sugerencia: nadie recibe nada nuevo de «te responden».
    $this->actingAs($this->elena)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>Yo misma</p>']);
    Notification::assertSentToTimes($this->elena, SuggestionReplied::class, 1);

    // Mencionar a un colaborador externo o a alguien desactivado no avisa.
    $outsider = User::factory()->collaborator()->create();
    $inactive = userWithRole('employee', ['is_active' => false]);
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>'.mention($outsider).mention($inactive).'</p>']);
    Notification::assertNotSentTo([$outsider, $inactive], SuggestionMentioned::class);

    // Editar un comentario solo avisa a los mencionados nuevos.
    $mine = SuggestionComment::query()->latest('id')->firstOrFail();
    $this->actingAs($this->pablo)->put("/ayuda/sugerencias/comentarios/{$mine->id}", ['body' => '<p>'.mention($this->manager).'</p>']);
    $this->actingAs($this->pablo)->put("/ayuda/sugerencias/comentarios/{$mine->id}", ['body' => '<p>'.mention($this->manager).' otra vez</p>']);
    Notification::assertSentToTimes($this->manager, SuggestionMentioned::class, 1);
});

it('una reacción por persona: ponerla, cambiarla y quitarla (F-166)', function () {
    $post = ($this->post)();
    $comment = SuggestionComment::query()->create(['suggestion_post_id' => $post->id, 'author_id' => $this->elena->id, 'body' => '<p>x</p>']);

    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/comentarios/{$comment->id}/reaccion", ['reaction' => 'rocket'])->assertRedirect();
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/comentarios/{$comment->id}/reaccion", ['reaction' => 'heart']);
    $this->actingAs($this->manager)->post("/ayuda/sugerencias/comentarios/{$comment->id}/reaccion", ['reaction' => 'eyes']);

    expect(SuggestionCommentReaction::query()->orderBy('id')->get()->map(fn ($r) => [$r->user_id, $r->reaction])->all())
        ->toBe([[$this->pablo->id, SuggestionReaction::Heart], [$this->manager->id, SuggestionReaction::Eyes]]);

    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/comentarios/{$comment->id}/reaccion", ['reaction' => 'heart']);
    expect(SuggestionCommentReaction::query()->count())->toBe(1);

    $this->actingAs($this->pablo)->postJson("/ayuda/sugerencias/comentarios/{$comment->id}/reaccion", ['reaction' => 'clap'])->assertJsonValidationErrors('reaction');
});

it('el estado con nota oficial e historial lo cambia quien gestiona y avisa al autor (F-167)', function () {
    $post = ($this->post)(['title' => 'Calendario']);
    ($this->post)(['status' => SuggestionStatus::Planned, 'position' => 1]);

    $this->actingAs($this->pablo)->put("/ayuda/sugerencias/{$post->id}/estado", ['status' => 'planned'])->assertForbidden();
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/{$post->id}/estado", ['status' => 'planned', 'note' => 'Para noviembre'])->assertSessionHasNoErrors();

    $post->refresh();
    expect($post->status)->toBe(SuggestionStatus::Planned)
        ->and($post->position)->toBe(2);
    $event = SuggestionStatusEvent::query()->firstOrFail();
    expect([$event->from_status, $event->to_status, $event->note, $event->changed_by])->toBe([SuggestionStatus::Open, SuggestionStatus::Planned, 'Para noviembre', $this->manager->id]);
    Notification::assertSentTo($this->elena, SuggestionStatusChanged::class, fn ($n) => $n->note === 'Para noviembre' && $n->statusLabel === 'Planificada');
    expect(Activity::query()->where('log_name', 'suggestions')->where('event', 'status_changed')->count())->toBe(1);

    // Mismo estado sin nota: nada. Con nota: queda en el historial.
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/{$post->id}/estado", ['status' => 'planned']);
    expect(SuggestionStatusEvent::query()->count())->toBe(1);
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/{$post->id}/estado", ['status' => 'planned', 'note' => 'Confirmado']);
    expect(SuggestionStatusEvent::query()->count())->toBe(2);

    $this->actingAs($this->pablo)->get("/ayuda/sugerencias/{$post->id}")->assertInertia(fn (Assert $page) => $page
        ->has('suggestions.post.status_events', 2)
        ->where('suggestions.post.status_events.0.to_status', 'planned')
        ->where('suggestions.post.status_events.0.changed_by.name', 'Marta'));

    // Su propio cambio no avisa a quien lo hace.
    $own = ($this->post)(['author_id' => $this->manager->id]);
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/{$own->id}/estado", ['status' => 'beta']);
    Notification::assertNotSentTo($this->manager, SuggestionStatusChanged::class);
});

it('arrastrar en el roadmap ordena la columna y, entre columnas, cambia el estado (F-168)', function () {
    [$a, $b, $c] = collect(['A', 'B', 'C'])->map(fn ($title, $i) => ($this->post)(['title' => $title, 'status' => SuggestionStatus::Planned, 'position' => $i + 1]))->all();
    $d = ($this->post)(['title' => 'D', 'status' => SuggestionStatus::Beta, 'position' => 1]);

    // C antes de A en «Planificada».
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/{$c->id}/orden", ['status' => 'planned', 'before_id' => $a->id])->assertSessionHasNoErrors();
    expect(SuggestionPost::query()->where('status', 'planned')->orderBy('position')->pluck('title')->all())->toBe(['C', 'A', 'B'])
        ->and(SuggestionStatusEvent::query()->count())->toBe(0);

    // A a «Beta», detrás de D: cambia el estado, queda en el historial y avisa a la autora.
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/{$a->id}/orden", ['status' => 'beta', 'after_id' => $d->id])->assertSessionHasNoErrors();
    expect(SuggestionPost::query()->where('status', 'beta')->orderBy('position')->pluck('title')->all())->toBe(['D', 'A'])
        ->and(SuggestionStatusEvent::query()->firstOrFail()->to_status)->toBe(SuggestionStatus::Beta);
    Notification::assertSentTo($this->elena, SuggestionStatusChanged::class);

    $this->actingAs($this->manager)->put("/ayuda/sugerencias/{$a->id}/orden", ['status' => 'open'])->assertSessionHasErrors('status');
    $this->actingAs($this->elena)->put("/ayuda/sugerencias/{$a->id}/orden", ['status' => 'beta'])->assertForbidden();

    $this->actingAs($this->elena)->get('/ayuda?pestana=sugerencias')->assertInertia(fn (Assert $page) => $page
        ->where('suggestions.view', 'roadmap')
        ->has('suggestions.roadmap', 4)
        ->where('suggestions.roadmap.0.status', 'planned')
        ->where('suggestions.roadmap.0.items.0.title', 'C')
        ->where('suggestions.roadmap.2.status', 'beta')
        ->where('suggestions.roadmap.2.items.1.title', 'A')
        ->where('suggestions.feed', null));

    // Filtro de estados del roadmap.
    $this->actingAs($this->elena)->get('/ayuda?pestana=sugerencias&estados=beta,completed')->assertInertia(fn (Assert $page) => $page
        ->has('suggestions.roadmap', 2)
        ->where('suggestions.filters.statuses', ['beta', 'completed']));
});

it('el feed: Trending, Top y Nuevo, estado, categoría, búsqueda global y «cargar más» (F-162)', function () {
    $this->travelBack();
    $old = ($this->post)(['title' => 'Antigua muy votada', 'vote_count' => 9, 'comment_count' => 0, 'created_at' => now()->subDays(5), 'suggestion_category_id' => $this->bugs->id]);
    $talked = ($this->post)(['title' => 'Muy comentada', 'vote_count' => 1, 'comment_count' => 7, 'created_at' => now()->subDays(3), 'suggestion_category_id' => $this->ideas->id]);
    $new = ($this->post)(['title' => 'Recién llegada', 'body' => '<p>Sobre facturas</p>', 'created_at' => now()->subDay(), 'suggestion_category_id' => $this->ideas->id]);
    $beta = ($this->post)(['title' => 'En beta', 'status' => SuggestionStatus::Beta, 'created_at' => now()->subDays(10)]);
    $hiddenBoard = SuggestionBoard::factory()->create(['is_active' => false]);
    ($this->post)(['title' => 'De un tablero oculto', 'suggestion_board_id' => $hiddenBoard->id]);

    $titles = fn (string $query) => $this->actingAs($this->pablo)->get('/ayuda?pestana=sugerencias&vista=feedback'.$query)
        ->viewData('page')['props']['suggestions']['feed'];

    expect(array_column($titles('')['items'], 'title'))->toBe(['Muy comentada', 'Antigua muy votada', 'Recién llegada', 'En beta'])
        ->and(array_column($titles('&orden=top')['items'], 'title'))->toBe(['Antigua muy votada', 'Muy comentada', 'Recién llegada', 'En beta'])
        ->and(array_column($titles('&orden=new')['items'], 'title'))->toBe(['Recién llegada', 'Muy comentada', 'Antigua muy votada', 'En beta'])
        ->and(array_column($titles('&orden=beta')['items'], 'title'))->toBe(['En beta'])
        ->and(array_column($titles('&categoria=ideas')['items'], 'title'))->toBe(['Muy comentada', 'Recién llegada'])
        // Con búsqueda se ignora la categoría (resultados globales) y se busca también en el detalle y en el nombre de la categoría.
        ->and(array_column($titles('&categoria=ideas&q=facturas')['items'], 'title'))->toBe(['Recién llegada'])
        ->and(array_column($titles('&q=bugs')['items'], 'title'))->toBe(['Antigua muy votada']);

    SuggestionPost::factory()->count(21)->create(['suggestion_board_id' => $this->board->id, 'author_id' => $this->elena->id]);
    $page = $titles('');
    expect($page['items'])->toHaveCount(20)->and($page['total'])->toBe(25)->and($page['has_more'])->toBeTrue();
    $more = $titles('&limite=40');
    expect($more['items'])->toHaveCount(25)->and($more['has_more'])->toBeFalse();
});

it('la pestaña trae los tableros con sus recuentos, la categoría Bugs, las personas y el formulario de bug (F-149)', function () {
    ($this->post)(['suggestion_category_id' => $this->bugs->id]);
    SuggestionBoard::factory()->create(['name' => 'Oculto', 'is_active' => false]);

    $this->actingAs($this->pablo)->get('/ayuda?pestana=sugerencias&vista=feedback&nueva=bug')->assertInertia(fn (Assert $page) => $page
        ->where('suggestions.composer', 'bug')
        ->where('suggestions.bugs_category_id', $this->bugs->id)
        ->has('suggestions.boards', 1)
        ->where('suggestions.boards.0.post_count', 1)
        ->where('suggestions.boards.0.categories.0.slug', 'bugs')
        ->where('suggestions.boards.0.categories.0.post_count', 1)
        ->where('suggestions.can.manage_boards', false)
        ->has('suggestions.people', 3));

    // Quien gestiona ve también los ocultos, para editarlos.
    $this->actingAs($this->manager)->get('/ayuda?pestana=sugerencias')->assertInertia(fn (Assert $page) => $page
        ->has('suggestions.boards', 2)
        ->where('suggestions.can.manage_boards', true));
});

it('«sugerencias similares» al escribir el título (F-161)', function () {
    ($this->post)(['title' => 'Exportar informes', 'vote_count' => 3]);
    ($this->post)(['title' => 'Exportar a Excel', 'vote_count' => 5]);
    ($this->post)(['title' => 'Otra cosa']);

    $this->actingAs($this->pablo)->getJson('/ayuda/sugerencias/similares?q=exportar')->assertOk()
        ->assertJsonPath('items.0.title', 'Exportar a Excel')
        ->assertJsonPath('items.1.title', 'Exportar informes')
        ->assertJsonCount(2, 'items');
    $this->actingAs($this->pablo)->getJson('/ayuda/sugerencias/similares?q=ex')->assertJsonCount(0, 'items');
    $this->actingAs($this->pablo)->getJson('/ayuda/sugerencias/similares?q=Exportar a Excel')->assertJsonCount(0, 'items');
});

it('una sugerencia de un tablero oculto solo la abre quien gestiona', function () {
    $board = SuggestionBoard::factory()->create(['is_active' => false]);
    $post = ($this->post)(['suggestion_board_id' => $board->id]);

    $this->actingAs($this->pablo)->get("/ayuda/sugerencias/{$post->id}")->assertNotFound();
    $this->actingAs($this->manager)->get("/ayuda/sugerencias/{$post->id}")->assertOk();
});

it('en un tablero oculto nadie vota, comenta ni reacciona, y sus adjuntos solo los baja quien gestiona (D-226)', function () {
    $board = SuggestionBoard::factory()->create(['is_active' => false]);
    $post = ($this->post)(['suggestion_board_id' => $board->id]);
    $comment = SuggestionComment::query()->create(['suggestion_post_id' => $post->id, 'author_id' => $this->elena->id, 'body' => '<p>x</p>']);
    $file = Attachment::factory()->create(['user_id' => $this->elena->id, 'attachable_type' => $post->getMorphClass(), 'attachable_id' => $post->id, 'project_id' => null]);
    Storage::disk('local')->put($file->path, 'contenido');
    $url = AttachmentResource::downloadUrl($file);

    foreach ([$this->pablo, $this->manager] as $user) {
        $this->actingAs($user)->post("/ayuda/sugerencias/{$post->id}/voto")->assertForbidden();
        $this->actingAs($user)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>+1</p>'])->assertForbidden();
        $this->actingAs($user)->post("/ayuda/sugerencias/comentarios/{$comment->id}/reaccion", ['reaction' => 'rocket'])->assertForbidden();
    }

    $this->actingAs($this->pablo)->get($url)->assertForbidden();
    $this->actingAs($this->manager)->get($url)->assertOk();
    expect(SuggestionVote::query()->count())->toBe(0)
        ->and(SuggestionComment::query()->count())->toBe(1);

    // Al volver a mostrarlo, todo funciona de nuevo.
    $board->update(['is_active' => true]);
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/voto")->assertRedirect();
    $this->actingAs($this->pablo)->get($url)->assertOk();
});

it('tableros y categorías: crear, editar, ocultar, reordenar y proteger «Bugs» (F-160 y F-169)', function () {
    $this->actingAs($this->pablo)->post('/ayuda/sugerencias/tableros', ['name' => 'Ideas'])->assertForbidden();

    $this->actingAs($this->manager)->post('/ayuda/sugerencias/tableros', ['name' => 'Ideas de producto', 'description' => 'Lo nuevo'])->assertSessionHasNoErrors();
    $board = SuggestionBoard::query()->where('slug', 'ideas-de-producto')->firstOrFail();
    expect($board->position)->toBe(1)->and($board->created_by)->toBe($this->manager->id);

    $this->actingAs($this->manager)->post("/ayuda/sugerencias/tableros/{$board->id}/categorias", ['name' => 'Móvil']);
    $this->actingAs($this->manager)->post("/ayuda/sugerencias/tableros/{$board->id}/categorias", ['name' => 'Web']);
    [$mobile, $web] = $board->categories()->get()->all();
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/tableros/{$board->id}/categorias/orden", ['ids' => [$web->id, $mobile->id]])->assertSessionHasNoErrors();
    expect($board->categories()->pluck('name')->all())->toBe(['Web', 'Móvil']);

    $this->actingAs($this->manager)->put("/ayuda/sugerencias/categorias/{$mobile->id}", ['name' => 'App', 'is_active' => false])->assertSessionHasNoErrors();
    expect($mobile->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->manager)->put('/ayuda/sugerencias/tableros/orden', ['ids' => [$board->id, $this->board->id]])->assertSessionHasNoErrors();
    expect(SuggestionBoard::query()->orderBy('position')->pluck('id')->all())->toBe([$board->id, $this->board->id]);

    // «Bugs»: se puede renombrar u ocultar, pero no borrar ni cambiar su slug.
    $this->actingAs($this->manager)->delete("/ayuda/sugerencias/categorias/{$this->bugs->id}")->assertSessionHasErrors('category');
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/categorias/{$this->bugs->id}", ['name' => 'Errores', 'slug' => 'errores'])->assertSessionHasNoErrors();
    expect($this->bugs->fresh()->slug)->toBe('bugs')->and($this->bugs->fresh()->name)->toBe('Errores');

    // Un tablero con sugerencias no se borra: se oculta.
    ($this->post)(['suggestion_board_id' => $board->id]);
    $this->actingAs($this->manager)->delete("/ayuda/sugerencias/tableros/{$board->id}")->assertSessionHasErrors('board');
    $this->actingAs($this->manager)->put("/ayuda/sugerencias/tableros/{$board->id}", ['name' => 'Ideas de producto', 'is_active' => false])->assertSessionHasNoErrors();
    expect($board->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->manager)->delete("/ayuda/sugerencias/categorias/{$web->id}")->assertSessionHasNoErrors();
    expect(SuggestionCategory::query()->find($web->id))->toBeNull();
    expect(Activity::query()->where('log_name', 'suggestion_boards')->exists())->toBeTrue();
});

it('con el módulo de sugerencias apagado sus rutas dan 404 y la ayuda sigue', function () {
    $post = ($this->post)();
    Setting::set('modules', ['suggestions' => false]);

    $this->actingAs($this->pablo)->get("/ayuda/sugerencias/{$post->id}")->assertNotFound();
    $this->actingAs($this->pablo)->post("/ayuda/sugerencias/{$post->id}/voto")->assertNotFound();
    $this->actingAs($this->pablo)->get('/ayuda')->assertOk();

    // Sus adjuntos tampoco se sirven.
    $file = Attachment::factory()->create(['attachable_type' => $post->getMorphClass(), 'attachable_id' => $post->id, 'project_id' => null]);
    $this->actingAs($this->pablo)->get(AttachmentResource::downloadUrl($file))->assertForbidden();
});
