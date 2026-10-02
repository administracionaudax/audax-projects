<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Pestaña Archivos del proyecto (SPEC §6, D-118): incluye los adjuntos del chat del proyecto, solo
| para quien ve esa conversación (D-071), sin las notas de voz y sin los de mensajes borrados u
| ocultados salvo para el admin que modera; cada uno enlaza a su mensaje y nunca se borra suelto.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    TaskStatus::ensureDefaults();
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->outsider = User::factory()->employee()->create(['name' => 'Eva']);
    $this->admin = User::factory()->admin()->create();
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->chat = app(ConversationDirectory::class)->forProject($this->project);
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Diseño']);
    Attachment::factory()->create([
        'project_id' => $this->project->id, 'attachable_type' => Task::class, 'attachable_id' => $this->task->id,
        'original_name' => 'boceto.png', 'mime' => 'image/png',
    ]);
    $this->pdf = fn (string $name) => UploadedFile::fake()->create($name, 10, 'application/pdf');
    $this->files = fn (User $user, string $query = ''): array => $this->actingAs($user)
        ->get("/proyectos/{$this->project->id}/archivos{$query}")
        ->assertOk()
        ->viewData('page')['props']['files'];
    $this->names = fn (User $user, string $query = ''): array => array_column(($this->files)($user, $query), 'original_name');
});

it('quien ve el chat del proyecto ve sus adjuntos con el enlace al mensaje, sin poder borrarlos sueltos', function () {
    $message = $this->writer->post($this->luis, $this->chat, 'Aquí va', files: [($this->pdf)('presupuesto.pdf')]);

    $this->actingAs($this->ana)
        ->get("/proyectos/{$this->project->id}/archivos")
        ->assertInertia(fn (Assert $page) => $page
            ->has('files', 2)
            ->where('files.0.original_name', 'presupuesto.pdf')
            ->where('files.0.task', null)
            ->where('files.0.in_comment', false)
            ->where('files.0.can_delete', false)
            ->where('files.0.message.conversation_id', $this->chat->id)
            ->where('files.0.message.message_id', $message->id)
            ->where('files.0.message.deleted', false)
            ->where('files.1.original_name', 'boceto.png')
            ->where('files.1.message', null)
            ->where('pagination.total', 2));

    // Quien lo subió tampoco lo borra suelto (se borra el mensaje).
    expect(collect(($this->files)($this->luis))->firstWhere('original_name', 'presupuesto.pdf')['can_delete'])->toBeFalse();
});

it('quien no participa en el chat del proyecto no ve sus adjuntos (sí los de las tareas)', function () {
    $this->writer->post($this->luis, $this->chat, 'Aquí va', files: [($this->pdf)('presupuesto.pdf')]);

    expect(($this->names)($this->outsider))->toBe(['boceto.png']);

    // Quien sale del proyecto deja de verlos.
    $this->project->members()->detach($this->ana->id);
    app(ConversationDirectory::class)->syncProject($this->project);
    expect(($this->names)($this->ana))->toBe(['boceto.png']);
});

it('sin los de mensajes borrados u ocultados, salvo para el admin que modera', function () {
    $this->writer->post($this->luis, $this->chat, 'Vivo', files: [($this->pdf)('vivo.pdf')]);
    $hidden = $this->writer->post($this->luis, $this->chat, 'Oculto', files: [($this->pdf)('oculto.pdf')]);
    $deleted = $this->writer->post($this->luis, $this->chat, 'Borrado', files: [($this->pdf)('borrado.pdf')]);
    $this->writer->setHidden($this->admin, $hidden, true);
    $this->writer->delete($this->luis, $deleted);

    expect(($this->names)($this->ana))->toBe(['vivo.pdf', 'boceto.png'])
        ->and(($this->names)($this->admin))->toBe(['borrado.pdf', 'oculto.pdf', 'vivo.pdf', 'boceto.png']);

    $deletedRow = collect(($this->files)($this->admin))->firstWhere('original_name', 'borrado.pdf');
    expect($deletedRow['message']['deleted'])->toBeTrue();
});

it('las notas de voz no son archivos: se escuchan en el chat', function () {
    $samples = str_repeat("\0\0", 16000);
    $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
    $path = (string) tempnam(sys_get_temp_dir(), 'c6files');
    file_put_contents($path, $wav);

    $this->writer->post($this->luis, $this->chat, null, files: [($this->pdf)('con-audio.pdf')], audio: new UploadedFile($path, 'nota.wav', null, null, true), audioDurationMs: 1000);

    expect(($this->names)($this->ana))->toBe(['con-audio.pdf', 'boceto.png']);
});

it('el filtro por tarea deja fuera los del chat y el filtro por tipo los incluye', function () {
    $this->writer->post($this->luis, $this->chat, 'Aquí va', files: [($this->pdf)('presupuesto.pdf')]);

    expect(($this->names)($this->ana, "?tarea_id={$this->task->id}"))->toBe(['boceto.png'])
        ->and(($this->names)($this->ana, '?tipo=pdf'))->toBe(['presupuesto.pdf']);
});

it('con muchos adjuntos del chat hace las mismas consultas que con uno', function () {
    $this->writer->post($this->luis, $this->chat, 'Uno', files: [($this->pdf)('uno.pdf')]);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->files)($this->ana);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    ($this->files)($this->ana); // calienta las cachés de roles y ajustes
    $one = $count();
    foreach (range(1, 8) as $i) {
        $this->writer->post($i % 2 === 0 ? $this->luis : $this->ana, $this->chat, "Archivo {$i}", files: [($this->pdf)("archivo-{$i}.pdf")]);
    }

    expect($count())->toBe($one);
});
