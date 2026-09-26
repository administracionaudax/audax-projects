<?php

use App\Enums\TaskStatusCategory;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Estados de tarea (SPEC §4.3 y §14): uno por defecto, al menos un «todo» y un «done», reemplazo al
| borrar y completed_at sincronizado con la categoría.
*/

beforeEach(function () {
    $this->admin = userWithRole('admin');
    TaskStatus::ensureDefaults();
    $this->todo = TaskStatus::query()->where('name', 'Por hacer')->sole();
    $this->doing = TaskStatus::query()->where('name', 'En curso')->sole();
    $this->review = TaskStatus::query()->where('name', 'En revisión')->sole();
    $this->done = TaskStatus::query()->where('name', 'Hecha')->sole();
    $this->payload = fn (TaskStatus $status, array $changes = []) => [
        'name' => $status->name,
        'color' => $status->color,
        'category' => $status->category->value,
        'is_default' => $status->is_default,
        ...$changes,
    ];
});

test('lista los estados en orden con cuántas tareas tienen', function () {
    Task::factory()->count(2)->create(['status_id' => $this->doing->id]);

    $this->actingAs($this->admin)
        ->get('/admin/estados')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/statuses/index')
            ->has('statuses', 5)
            ->where('statuses.0.name', 'Por hacer')
            ->where('statuses.0.is_default', true)
            ->where('statuses.1.tasks_count', 2)
            ->where('statuses.4.category', 'done'));
});

test('crea un estado al final; marcarlo por defecto quita la marca al anterior', function () {
    $this->actingAs($this->admin)
        ->post('/admin/estados', ['name' => 'Pendiente de cliente', 'color' => '#0892C4', 'category' => 'todo', 'is_default' => true])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    $new = TaskStatus::query()->where('name', 'Pendiente de cliente')->sole();

    expect($new->is_default)->toBeTrue()
        ->and($new->position)->toBe(5)
        ->and($this->todo->fresh()?->is_default)->toBeFalse()
        ->and(TaskStatus::query()->where('is_default', true)->count())->toBe(1);
});

test('el estado por defecto no puede ser de categoría «done»', function () {
    $this->actingAs($this->admin)
        ->post('/admin/estados', ['name' => 'Cerrada', 'color' => '#179FA5', 'category' => 'done', 'is_default' => true])
        ->assertSessionHasErrors('is_default');

    $this->actingAs($this->admin)
        ->put("/admin/estados/{$this->todo->id}", ($this->payload)($this->todo, ['category' => 'done']))
        ->assertSessionHasErrors('category');

    expect(TaskStatus::query()->count())->toBe(5)->and($this->todo->fresh()?->category)->toBe(TaskStatusCategory::Todo);
});

test('siempre hay un estado por defecto: no se le quita la marca sin dársela a otro', function () {
    $this->actingAs($this->admin)
        ->put("/admin/estados/{$this->todo->id}", ($this->payload)($this->todo, ['is_default' => false]))
        ->assertSessionHasErrors('is_default');

    $this->actingAs($this->admin)
        ->put("/admin/estados/{$this->doing->id}", ($this->payload)($this->doing, ['is_default' => true]))
        ->assertSessionHasNoErrors();

    expect($this->doing->fresh()?->is_default)->toBeTrue()
        ->and($this->todo->fresh()?->is_default)->toBeFalse();
});

test('valida nombre único y datos', function () {
    $this->actingAs($this->admin)
        ->post('/admin/estados', ['name' => 'hecha', 'color' => '#000', 'category' => 'waiting', 'is_default' => 'quizá'])
        ->assertSessionHasErrors(['name', 'color', 'category', 'is_default']);
});

test('pasar un estado a «done» completa sus tareas; sacarlo de «done» las reabre', function () {
    $open = Task::factory()->count(2)->create(['status_id' => $this->review->id]);
    $other = Task::factory()->create(['status_id' => $this->doing->id]);
    expect($open[0]->completed_at)->toBeNull();

    $this->actingAs($this->admin)
        ->put("/admin/estados/{$this->review->id}", ($this->payload)($this->review, ['category' => 'done']))
        ->assertSessionHasNoErrors();

    expect($open[0]->fresh()?->completed_at)->not->toBeNull()
        ->and($open[1]->fresh()?->completed_at)->not->toBeNull()
        ->and($other->fresh()?->completed_at)->toBeNull()
        ->and(DB::table('activity_log')->where('event', 'task_status.category_changed')->count())->toBe(1);

    $this->actingAs($this->admin)
        ->put("/admin/estados/{$this->review->id}", ($this->payload)($this->review->fresh() ?? $this->review, ['category' => 'in_progress']))
        ->assertSessionHasNoErrors();

    expect($open[0]->fresh()?->completed_at)->toBeNull()
        ->and($open[1]->fresh()?->completed_at)->toBeNull();
});

test('siempre queda al menos un estado «todo» y otro «done»', function () {
    $this->actingAs($this->admin)
        ->put("/admin/estados/{$this->done->id}", ($this->payload)($this->done, ['category' => 'in_progress']))
        ->assertSessionHasErrors('category');

    $this->actingAs($this->admin)
        ->delete("/admin/estados/{$this->done->id}")
        ->assertSessionHasErrors('category');

    expect($this->done->fresh()?->category)->toBe(TaskStatusCategory::Done);
});

test('un estado sin tareas se borra; el de por defecto no', function () {
    $this->actingAs($this->admin)
        ->delete("/admin/estados/{$this->review->id}")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect(TaskStatus::query()->whereKey($this->review->id)->exists())->toBeFalse();

    $this->actingAs($this->admin)
        ->delete("/admin/estados/{$this->todo->id}")
        ->assertSessionHasErrors('status');

    expect(TaskStatus::query()->whereKey($this->todo->id)->exists())->toBeTrue();
});

test('un estado con tareas solo se borra eligiendo otro; las tareas pasan a él y se sincroniza completed_at', function () {
    $tasks = Task::factory()->count(3)->create(['status_id' => $this->review->id]);
    $trashed = Task::factory()->create(['status_id' => $this->review->id]);
    $trashed->delete();

    $this->actingAs($this->admin)
        ->delete("/admin/estados/{$this->review->id}")
        ->assertSessionHasErrors('replacement_status_id');

    $this->actingAs($this->admin)
        ->delete("/admin/estados/{$this->review->id}", ['replacement_status_id' => $this->review->id])
        ->assertSessionHasErrors('replacement_status_id');

    $this->actingAs($this->admin)
        ->delete("/admin/estados/{$this->review->id}", ['replacement_status_id' => $this->done->id])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect(TaskStatus::query()->whereKey($this->review->id)->exists())->toBeFalse();

    foreach ([...$tasks, $trashed] as $task) {
        $fresh = Task::withTrashed()->findOrFail($task->id);
        expect($fresh->status_id)->toBe($this->done->id)->and($fresh->completed_at)->not->toBeNull();
    }

    expect(DB::table('activity_log')->where('event', 'task_status.replaced')->value('properties'))->toContain('"tasks":4');
});

test('mover a un estado no «done» reabre las tareas que estaban completadas', function () {
    $closed = TaskStatus::factory()->done()->create(['name' => 'Archivada']);
    $task = Task::factory()->create(['status_id' => $closed->id]);
    expect($task->completed_at)->not->toBeNull();

    $this->actingAs($this->admin)
        ->delete("/admin/estados/{$closed->id}", ['replacement_status_id' => $this->doing->id])
        ->assertSessionHasNoErrors();

    expect($task->fresh()?->status_id)->toBe($this->doing->id)
        ->and($task->fresh()?->completed_at)->toBeNull();
});

test('subir y bajar estados', function () {
    $this->actingAs($this->admin)
        ->post("/admin/estados/{$this->done->id}/mover", ['direction' => 'up'])
        ->assertRedirect();

    expect(TaskStatus::query()->ordered()->pluck('name')->all())
        ->toBe(['Por hacer', 'En curso', 'En revisión', 'Hecha', 'Bloqueada']);
});
