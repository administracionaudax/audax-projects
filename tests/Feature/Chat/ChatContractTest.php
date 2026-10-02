<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Enums\ProjectStatus;
use App\Events\Chat\MessagePosted;
use App\Models\Conversation;
use App\Models\MessageMention;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Contrato del chat (Fase 6, D-068 a D-071): conversaciones, participantes y escritura.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
});

it('el chat de un proyecto se crea al primer uso con sus miembros y los sigue', function () {
    $chat = $this->directory->forProject($this->project);

    expect($chat->type)->toBe(ConversationType::Project)
        ->and($chat->activeParticipants()->pluck('user_id')->sort()->values()->all())
        ->toEqual(collect([$this->ana->id, $this->luis->id, $this->project->owner_user_id])->unique()->sort()->values()->all());

    $nuevo = User::factory()->employee()->create();
    $this->project->addMember($nuevo);
    expect($chat->hasParticipant($nuevo))->toBeTrue();

    $this->project->members()->detach($this->luis->id);
    expect($chat->hasParticipant($this->luis))->toBeFalse()
        ->and($chat->participants()->where('user_id', $this->luis->id)->whereNotNull('left_at')->exists())->toBeTrue();
});

it('una conversación directa por pareja, solo entre internos activos', function () {
    $first = $this->directory->direct($this->ana, $this->luis);

    expect($this->directory->direct($this->luis, $this->ana)->id)->toBe($first->id)
        ->and(fn () => $this->directory->direct($this->ana, $this->ana))->toThrow(ValidationException::class)
        ->and(fn () => $this->directory->direct($this->ana, User::factory()->client()->create()))->toThrow(ValidationException::class)
        ->and(fn () => $this->directory->direct($this->ana, User::factory()->employee()->create(['is_active' => false])))->toThrow(ValidationException::class);
});

it('publica con menciones válidas (solo participantes) y @todos, y avanza lo leído del autor', function () {
    Event::fake([MessagePosted::class]);
    $chat = $this->directory->forProject($this->project);
    $outsider = User::factory()->employee()->create();

    $message = $this->writer->post($this->ana, $chat, "Hola <@{$this->luis->id}> y <@{$outsider->id}>, @todos al lío");

    expect($message->type)->toBe(MessageType::Text)
        ->and(MessageMention::query()->where('message_id', $message->id)->whereNotNull('user_id')->pluck('user_id')->all())->toBe([$this->luis->id])
        ->and(MessageMention::query()->where('message_id', $message->id)->where('everyone', true)->exists())->toBeTrue()
        ->and($chat->participants()->where('user_id', $this->ana->id)->value('last_read_message_id'))->toBe($message->id)
        ->and($chat->fresh()->last_message_at)->not->toBeNull();
    Event::assertDispatched(MessagePosted::class);
});

it('solo los participantes escriben; en un proyecto archivado ya no se escribe', function () {
    $chat = $this->directory->forProject($this->project);

    expect(fn () => $this->writer->post(User::factory()->employee()->create(), $chat, 'Hola'))->toThrow(AuthorizationException::class);

    $this->project->update(['status' => ProjectStatus::Archived]);
    expect(fn () => $this->writer->post($this->ana, $chat->fresh(), 'Hola'))->toThrow(AuthorizationException::class);
});

it('el admin ve y modera los chats de proyecto, pero nunca los directos', function () {
    $admin = User::factory()->admin()->create();
    $chat = $this->directory->forProject($this->project);
    $dm = $this->directory->direct($this->ana, $this->luis);

    expect($admin->can('view', $chat))->toBeTrue()
        ->and($admin->can('post', $chat))->toBeFalse()
        ->and($admin->can('view', $dm))->toBeFalse()
        ->and($admin->can('moderate', $dm))->toBeFalse();

    $message = $this->writer->post($this->ana, $chat, 'Algo que moderar');
    $this->writer->setHidden($admin, $message, true);
    expect($message->fresh()->hidden_at)->not->toBeNull();
});

it('edita y borra solo lo propio, reacciona y fija', function () {
    $chat = $this->directory->direct($this->ana, $this->luis);
    $message = $this->writer->post($this->ana, $chat, 'Primera versión');

    expect(fn () => $this->writer->edit($this->luis, $message, 'No es mío'))->toThrow(AuthorizationException::class);
    $this->writer->edit($this->ana, $message, 'Segunda versión');
    expect($message->fresh()->body)->toBe('Segunda versión')->and($message->fresh()->edited_at)->not->toBeNull();

    expect($this->writer->toggleReaction($this->luis, $message, '👍'))->toBeTrue()
        ->and($this->writer->toggleReaction($this->luis, $message, '👍'))->toBeFalse()
        ->and(fn () => $this->writer->toggleReaction($this->luis, $message, '<script>'))->toThrow(ValidationException::class);

    $this->writer->setPinned($this->luis, $message, true);
    expect($message->fresh()->pinned_by)->toBe($this->luis->id);

    $this->writer->delete($this->ana, $message);
    expect($message->fresh()->trashed())->toBeTrue();
});

it('una respuesta en hilo tiene que ser de la misma conversación', function () {
    $chat = $this->directory->direct($this->ana, $this->luis);
    $other = $this->directory->forProject($this->project);
    $foreign = $this->writer->post($this->ana, $other, 'De otro sitio');

    expect(fn () => $this->writer->post($this->ana, $chat, 'Respuesta', $foreign->id))->toThrow(ValidationException::class);

    $root = $this->writer->post($this->ana, $chat, 'Pregunta');
    expect($this->writer->post($this->luis, $chat, 'Respuesta', $root->id)->parent_id)->toBe($root->id);
});

it('guarda adjuntos del chat directo fuera de los proyectos', function () {
    $chat = $this->directory->direct($this->ana, $this->luis);
    $message = $this->writer->post($this->ana, $chat, null, files: [UploadedFile::fake()->createWithContent('nota.txt', 'hola')]);

    $attachment = $message->attachments()->firstOrFail();
    expect($message->type)->toBe(MessageType::File)
        ->and($attachment->project_id)->toBeNull()
        ->and($attachment->path)->toStartWith("attachments/chat/{$chat->id}/");
});

it('el mensaje de sistema no tiene autor y cuenta como último mensaje', function () {
    $chat = $this->directory->forProject($this->project);
    $message = $this->writer->system($chat, 'hour_bank.threshold', ['bank' => 'Mantenimiento', 'threshold' => 90]);

    expect($message->user_id)->toBeNull()
        ->and($message->type)->toBe(MessageType::System)
        ->and(Conversation::query()->find($chat->id)->last_message_at)->not->toBeNull();
});
