<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Pestaña Archivos (SPEC §6): adjuntos de las tareas y de sus comentarios, con filtros por tipo y
| por tarea; nunca los de tareas o comentarios borrados ni los de otros proyectos.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Diseño']);
    $this->other = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Textos']);
    $this->file = fn (array $attributes): Attachment => Attachment::factory()->create([
        'project_id' => $this->project->id,
        'attachable_type' => Task::class,
        'attachable_id' => $this->task->id,
        ...$attributes,
    ]);
    $this->names = fn (string $query = ''): array => array_column(
        $this->actingAs($this->user)->get("/proyectos/{$this->project->id}/archivos{$query}")->assertOk()->viewData('page')['props']['files'],
        'original_name',
    );
});

it('lista los adjuntos de las tareas y de sus comentarios, los más recientes primero', function () {
    ($this->file)(['original_name' => 'boceto.png', 'mime' => 'image/png']);
    $comment = TaskComment::factory()->create(['task_id' => $this->other->id]);
    ($this->file)(['original_name' => 'textos.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'attachable_type' => TaskComment::class, 'attachable_id' => $comment->id]);
    Attachment::factory()->create(['original_name' => 'ajeno.pdf']);

    $this->actingAs($this->user)->get("/proyectos/{$this->project->id}/archivos")
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/files')
            ->has('files', 2)
            ->where('files.0.original_name', 'textos.docx')
            ->where('files.0.task.title', 'Textos')
            ->where('files.0.in_comment', true)
            ->where('files.1.original_name', 'boceto.png')
            ->where('files.1.task.id', $this->task->id)
            ->where('pagination.total', 2)
            ->has('tasks', 2));
});

it('filtra por tipo y por tarea', function () {
    ($this->file)(['original_name' => 'boceto.png', 'mime' => 'image/png']);
    ($this->file)(['original_name' => 'presupuesto.pdf', 'mime' => 'application/pdf']);
    ($this->file)(['original_name' => 'datos.csv', 'mime' => 'text/csv', 'attachable_id' => $this->other->id]);

    expect(($this->names)('?tipo=image'))->toBe(['boceto.png'])
        ->and(($this->names)('?tipo=pdf'))->toBe(['presupuesto.pdf'])
        ->and(($this->names)('?tipo=spreadsheet'))->toBe(['datos.csv'])
        ->and(($this->names)("?tarea_id={$this->other->id}"))->toBe(['datos.csv'])
        ->and(($this->names)('?tipo=desconocido'))->toHaveCount(3);
});

it('no muestra adjuntos de tareas o comentarios borrados', function () {
    ($this->file)(['original_name' => 'vivo.pdf']);
    ($this->file)(['original_name' => 'de-borrada.pdf', 'attachable_id' => $this->other->id]);
    $comment = TaskComment::factory()->create(['task_id' => $this->other->id]);
    ($this->file)(['original_name' => 'de-comentario.pdf', 'attachable_type' => TaskComment::class, 'attachable_id' => $comment->id]);
    $this->other->delete();

    expect(($this->names)())->toBe(['vivo.pdf']);
});

it('indica qué adjuntos puede borrar cada persona', function () {
    $mine = ($this->file)(['original_name' => 'mío.pdf', 'user_id' => $this->user->id]);
    ($this->file)(['original_name' => 'ajeno.pdf']);

    $files = collect($this->actingAs($this->user)->get("/proyectos/{$this->project->id}/archivos")->viewData('page')['props']['files'])->keyBy('original_name');

    expect($files['mío.pdf']['can_delete'])->toBeTrue()
        ->and($files['ajeno.pdf']['can_delete'])->toBeFalse()
        ->and($files['mío.pdf']['url'])->toStartWith("/adjuntos/{$mine->id}?");
});

it('pagina y no hace N+1', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get("/proyectos/{$this->project->id}/archivos")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $seed = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $comment = TaskComment::factory()->create(['task_id' => $this->task->id]);
            ($this->file)(['attachable_type' => TaskComment::class, 'attachable_id' => $comment->id]);
            ($this->file)([]);
        }
    };

    $count();
    $seed(2);
    $few = $count();
    $seed(8);

    expect($count())->toBe($few);
});
