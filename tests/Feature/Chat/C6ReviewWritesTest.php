<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Enums\ProjectStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Revisión global de la Fase 6 (D-115 y D-117): escrituras del chat que la revisión encontró
| abiertas.
| - En el chat de un proyecto archivado todo es de solo lectura (D-071): editar, borrar lo propio,
|   reaccionar, fijar y crear una tarea.
| - Quien escribió un mensaje ocultado por un admin no lo borra (el moderador conserva su
|   contenido) y la auditoría de la moderación guarda el texto que tenía.
| - Reacciones: los emojis del selector (también 0️⃣ e ℹ️), nunca texto.
| - Si falla un adjunto a mitad, no quedan ficheros huérfanos en el disco.
| - Las peticiones del chat se autorizan antes de validar: el admin no distingue una directa
|   ajena por los errores de validación.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    TaskStatus::ensureDefaults();
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->project = Project::factory()->hourBank()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->bank = HourBank::factory()->create(['project_id' => $this->project->id]);
    $this->chat = $this->directory->forProject($this->project);
});

it('en un proyecto archivado no se edita, no se borra lo propio, no se reacciona, no se fija ni se crea una tarea', function () {
    $mine = $this->writer->post($this->ana, $this->chat, 'Mío');
    $theirs = $this->writer->post($this->luis, $this->chat, 'De Luis');
    $this->writer->setPinned($this->ana, $theirs, true);
    $this->project->update(['status' => ProjectStatus::Archived]);

    $this->actingAs($this->ana)->patchJson("/chat/mensajes/{$mine->id}", ['body' => 'Cambiado'])->assertForbidden();
    $this->actingAs($this->ana)->deleteJson("/chat/mensajes/{$mine->id}")->assertForbidden();
    $this->actingAs($this->ana)->postJson("/chat/mensajes/{$theirs->id}/reacciones", ['emoji' => '👍'])->assertForbidden();
    $this->actingAs($this->ana)->patchJson("/chat/mensajes/{$theirs->id}/fijado", ['pinned' => false])->assertForbidden();
    $this->actingAs($this->ana)->patchJson("/chat/mensajes/{$mine->id}/fijado", ['pinned' => true])->assertForbidden();
    $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$theirs->id}/tarea", ['title' => 'Tarea', 'hour_bank_id' => $this->bank->id])
        ->assertForbidden();

    expect($mine->fresh()->body)->toBe('Mío')
        ->and($mine->fresh()->trashed())->toBeFalse()
        ->and($theirs->fresh()->reactions()->count())->toBe(0)
        ->and($theirs->fresh()->pinned_at)->not->toBeNull()
        ->and($mine->fresh()->pinned_at)->toBeNull()
        ->and(Task::query()->count())->toBe(0);

    // La interfaz no ofrece ninguna de esas acciones.
    $this->actingAs($this->ana)
        ->getJson("/chat/mensajes/{$mine->id}")
        ->assertOk()
        ->assertJsonPath('message.can.edit', false)
        ->assertJsonPath('message.can.delete', false)
        ->assertJsonPath('message.can.react', false)
        ->assertJsonPath('message.can.pin', false)
        ->assertJsonPath('message.can.create_task', false);

    // El admin sigue pudiendo moderar.
    $this->actingAs($this->admin)->patchJson("/chat/mensajes/{$mine->id}/moderacion", ['hidden' => true])->assertOk();
});

it('quien escribió un mensaje ocultado no lo borra y el moderador conserva su contenido', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Algo que moderar');
    $this->writer->setHidden($this->admin, $message, true);

    $this->actingAs($this->ana)->deleteJson("/chat/mensajes/{$message->id}")->assertForbidden();
    expect(fn () => $this->writer->delete($this->ana, $message->fresh()))->toThrow(AuthorizationException::class);

    $this->actingAs($this->ana)
        ->getJson("/chat/mensajes/{$message->id}")
        ->assertJsonPath('message.body', null)
        ->assertJsonPath('message.can.delete', false);

    $this->actingAs($this->admin)
        ->getJson("/chat/mensajes/{$message->id}")
        ->assertJsonPath('message.body', 'Algo que moderar')
        ->assertJsonPath('message.deleted', false)
        ->assertJsonPath('message.hidden', true);
});

it('la auditoría de la moderación guarda el texto que tenía el mensaje', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Texto ofensivo');

    $this->writer->setHidden($this->admin, $message, true);

    $activity = Activity::query()->where('log_name', 'chat')->latest('id')->firstOrFail();
    expect($activity->event)->toBe('hidden')
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties->get('body'))->toBe('Texto ofensivo')
        ->and($activity->properties->get('conversation_id'))->toBe($this->chat->id);
});

it('admite como reacción cualquier emoji del selector, también los que llevan cifras o letras', function () {
    $message = $this->writer->post($this->luis, $this->chat, 'Hola');

    // Los del selector y los atajos de la interfaz (QUICK_REACTIONS), con o sin U+FE0F.
    foreach (['0️⃣', '9️⃣', 'ℹ️', '🅰️', '👍🏽', '❤️', '👍', '👍️', '😂', '🎉', '👀', '🙏', '✅'] as $emoji) {
        expect($this->writer->toggleReaction($this->ana, $message, $emoji))->toBeTrue();
    }

    $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$message->id}/reacciones", ['emoji' => '#️⃣'])
        ->assertOk();

    expect($message->reactions()->count())->toBe(14);
});

it('rechaza como reacción el texto, las etiquetas y los símbolos que no son emojis', function () {
    $message = $this->writer->post($this->luis, $this->chat, 'Hola');

    foreach (['a', '0', 'ok', '<b>', '1️⃣2️⃣', '👍x', '→', '*'] as $text) {
        expect(fn () => $this->writer->toggleReaction($this->ana, $message, $text))->toThrow(ValidationException::class);
    }

    $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$message->id}/reacciones", ['emoji' => 'hola'])
        ->assertJsonValidationErrors('emoji');

    expect($message->reactions()->count())->toBe(0);
});

it('si falla un adjunto a mitad, no queda el mensaje ni ningún fichero en el disco', function () {
    $good = UploadedFile::fake()->create('presupuesto.pdf', 10, 'application/pdf');
    $bad = UploadedFile::fake()->create('programa.exe', 10, 'application/octet-stream');

    expect(fn () => $this->writer->post($this->ana, $this->chat, 'Con archivos', files: [$good, $bad]))
        ->toThrow(RuntimeException::class);

    expect($this->chat->messages()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('autoriza antes de validar: el admin no distingue una directa ajena por los errores', function () {
    $dm = $this->directory->direct($this->ana, $this->luis);
    $message = $this->writer->post($this->ana, $dm, 'Privado');

    // Datos no válidos: 403 (no 422), igual que con datos válidos.
    $this->actingAs($this->admin)->postJson("/chat/{$dm->id}/mensajes", [])->assertForbidden();
    $this->actingAs($this->admin)->postJson("/chat/{$dm->id}/mensajes", ['body' => 'Hola'])->assertForbidden();
    $this->actingAs($this->admin)->postJson("/chat/mensajes/{$message->id}/reacciones", [])->assertForbidden();
    $this->actingAs($this->admin)->patchJson("/chat/mensajes/{$message->id}", ['body' => ''])->assertForbidden();
    $this->actingAs($this->admin)->patchJson("/chat/{$dm->id}/silencio", [])->assertForbidden();
    $this->actingAs($this->admin)->postJson("/chat/{$dm->id}/leido", [])->assertForbidden();

    // Quien sí la ve recibe los errores de validación de siempre.
    $this->actingAs($this->luis)->postJson("/chat/mensajes/{$message->id}/reacciones", [])->assertJsonValidationErrors('emoji');
});
