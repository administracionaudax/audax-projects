<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Privacy\Export\Sections\ChatMessagesSection;
use App\Domain\Privacy\Export\Sections\TaskCommentsSection;
use App\Models\AudioTranscription;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Exportación de los datos personales (D-075) con el chat de la Fase 6: los mensajes propios con
| su conversación, fecha y texto, sus adjuntos y la transcripción de los audios propios; nunca los
| de otras personas ni los de sistema.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo('2026-10-05 10:00:00');
    $this->writer = app(MessageWriter::class);
    $this->directory = app(ConversationDirectory::class);
    $this->elena = User::factory()->employee()->create(['name' => 'Elena Empleada']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis López']);
    $this->project = Project::factory()->create(['code' => 'WEB', 'name' => 'Web corporativa']);
    $this->project->addMember($this->elena);
    $this->project->addMember($this->luis);
    $this->chat = $this->directory->forProject($this->project);
});

test('la sección del chat está en el ZIP, después de los comentarios', function () {
    $sections = config('privacy.export_sections');

    expect($sections)->toContain(ChatMessagesSection::class)
        ->and(array_search(ChatMessagesSection::class, $sections, true))->toBeGreaterThan(array_search(TaskCommentsSection::class, $sections, true));
});

test('lleva los mensajes propios con su conversación, texto, adjuntos y transcripción; nunca los ajenos', function () {
    $samples = str_repeat("\0\0", 16000);
    $path = (string) tempnam(sys_get_temp_dir(), 'export-chat');
    file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples);

    $first = $this->writer->post($this->elena, $this->chat, "Hola <@{$this->luis->id}>, **revisa** el plano", files: [UploadedFile::fake()->create('plano.pdf', 10, 'application/pdf')]);
    $this->writer->post($this->luis, $this->chat, 'Mensaje de Luis que no es de Elena');
    $audio = $this->writer->post($this->elena, $this->chat, null, audio: new UploadedFile($path, 'nota.wav', null, null, true), audioDurationMs: 1000);
    AudioTranscription::query()->where('message_id', $audio->id)->update(['status' => 'done', 'text' => 'Transcripción de mi audio']);
    $direct = $this->directory->direct($this->elena, $this->luis);
    $reply = $this->writer->post($this->elena, $direct, 'Te escribo por privado');
    $this->writer->delete($this->elena, $reply);
    $group = $this->directory->group($this->elena, 'Diseño', [$this->luis->id]);
    $this->writer->post($this->elena, $group, 'En el grupo');

    $rows = iterator_to_array((new ChatMessagesSection)->rows($this->elena), false);

    expect($rows)->toHaveCount(4)
        ->and(implode(' ', array_column($rows, 'body')))->not->toContain('Mensaje de Luis')
        ->and($rows[0])->toMatchArray([
            'id' => $first->id,
            'conversation' => 'Proyecto WEB · Web corporativa',
            'type' => 'Texto',
            'body' => 'Hola @Luis López, **revisa** el plano',
            'attachments' => 'plano.pdf',
            'transcription' => null,
            'created_at' => '2026-10-05T12:00:00+02:00',
            'deleted_at' => null,
        ])
        ->and($rows[1])->toMatchArray(['type' => 'Audio', 'body' => null, 'transcription' => 'Transcripción de mi audio'])
        ->and($rows[2])->toMatchArray(['conversation' => 'Directa con Luis López', 'body' => 'Te escribo por privado', 'deleted_at' => '2026-10-05T12:00:00+02:00'])
        ->and($rows[3]['conversation'])->toBe('Grupo «Diseño»');

    // Luis solo tiene el suyo.
    expect(array_column(iterator_to_array((new ChatMessagesSection)->rows($this->luis), false), 'body'))->toBe(['Mensaje de Luis que no es de Elena']);
});
