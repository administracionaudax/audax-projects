<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Models\Attachment;
use App\Models\AudioTranscription;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\Chat\ChatMentionNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Retención de los mensajes del chat (SPEC §15, D-075): sin límite por defecto. Con un plazo,
| app:prune-data borra los mensajes más antiguos con sus adjuntos (filas y ficheros), audios,
| transcripciones, reacciones, menciones y avisos; las respuestas recientes pierden la cita. Nunca
| toca conversaciones, tareas creadas desde un mensaje ni horas.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    TaskStatus::ensureDefaults();
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->chat = app(ConversationDirectory::class)->forProject($this->project);
    $this->wav = function (): UploadedFile {
        $samples = str_repeat("\0\0", 16000);
        $path = (string) tempnam(sys_get_temp_dir(), 'retention');
        file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples);

        return new UploadedFile($path, 'nota.wav', null, null, true);
    };

    // Un mensaje antiguo con de todo (adjunto, audio y transcripción, reacción, mención, aviso y
    // tarea creada desde él), y uno reciente que lo cita.
    $this->travelTo('2026-01-10 10:00:00');
    $this->old = $this->writer->post($this->ana, $this->chat, "Mira esto <@{$this->luis->id}>", files: [UploadedFile::fake()->create('plano.pdf', 10, 'application/pdf')]);
    $this->oldAudio = $this->writer->post($this->ana, $this->chat, null, audio: ($this->wav)(), audioDurationMs: 1000);
    $this->writer->toggleReaction($this->luis, $this->old, '👍');
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Revisar plano']);
    $this->old->forceFill(['task_id' => $this->task->id])->save();
    TimeEntry::factory()->forTask($this->task)->create(['user_id' => $this->ana->id, 'date' => '2026-01-10']);
    // Aviso de la campana guardado (Notification::fake no escribe en la base).
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => ChatMentionNotification::class,
        'notifiable_type' => $this->luis->getMorphClass(),
        'notifiable_id' => $this->luis->id,
        'chat_message_id' => $this->old->id,
        'data' => json_encode(['kind' => 'chat.mention', 'title' => 'Ana te ha mencionado', 'body' => 'Mira esto', 'url' => null, 'icon' => null]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->travelTo('2026-10-05 01:10:00');
    $this->recent = $this->writer->post($this->luis, $this->chat, 'Visto', parentId: $this->old->id);
    $this->files = Storage::disk('local')->allFiles();
});

test('sin plazo (por defecto) no se borra ningún mensaje', function () {
    expect(Setting::get('retention_chat_messages_months'))->toBeNull();

    $this->artisan('app:prune-data')->assertSuccessful();

    expect(Message::withTrashed()->count())->toBe(3)
        ->and(Attachment::query()->count())->toBe(2)
        ->and(Storage::disk('local')->allFiles())->toBe($this->files);
});

test('con un plazo borra los mensajes antiguos con sus adjuntos, audios, transcripciones y avisos', function () {
    expect($this->files)->not->toBeEmpty()
        ->and(AudioTranscription::query()->count())->toBe(1)
        ->and(DB::table('notifications')->where('chat_message_id', $this->old->id)->count())->toBe(1);

    Setting::set('retention_chat_messages_months', 6);

    $this->artisan('app:prune-data')->assertSuccessful();

    expect(Message::withTrashed()->pluck('id')->all())->toBe([$this->recent->id])
        ->and($this->recent->refresh()->parent_id)->toBeNull()
        ->and(Attachment::withTrashed()->count())->toBe(0)
        ->and(AudioTranscription::query()->count())->toBe(0)
        ->and(DB::table('message_reactions')->count())->toBe(0)
        ->and(DB::table('message_mentions')->count())->toBe(0)
        ->and(DB::table('notifications')->where('chat_message_id', $this->old->id)->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    // Lo demás sigue: la conversación, sus participantes, la tarea creada desde el mensaje y las horas.
    expect(Conversation::query()->whereKey($this->chat->id)->exists())->toBeTrue()
        ->and(DB::table('conversation_participants')->where('conversation_id', $this->chat->id)->count())->toBe(3)
        ->and(Task::query()->whereKey($this->task->id)->exists())->toBeTrue()
        ->and(TimeEntry::query()->count())->toBe(1);
});

test('borra por lotes sin dejarse ninguno', function () {
    $this->travelTo('2026-01-11 10:00:00');
    foreach (range(1, 5) as $i) {
        $this->writer->post($this->ana, $this->chat, "Antiguo {$i}");
    }
    $this->travelTo('2026-10-05 01:10:00');
    Setting::set('retention_chat_messages_months', 1);
    config(['privacy.prune_batch_size' => 2]);

    $this->artisan('app:prune-data')->assertSuccessful();

    expect(Message::withTrashed()->pluck('id')->all())->toBe([$this->recent->id]);
});
