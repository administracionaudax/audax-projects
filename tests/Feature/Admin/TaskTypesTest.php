<?php

use App\Domain\Admin\TaskTypeIcons;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskType;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Tipos de tarea (SPEC §4.3 y §14): catálogo ordenado; un tipo en uso se desactiva, no se borra.
*/

beforeEach(function () {
    $this->admin = userWithRole('admin');
});

test('lista los tipos en su orden, con cuántas tareas los usan, sin N+1', function () {
    $design = TaskType::factory()->create(['name' => 'Diseño UI', 'position' => 1, 'icon' => 'palette']);
    $dev = TaskType::factory()->create(['name' => 'Desarrollo', 'position' => 0]);
    TaskType::factory()->create(['name' => 'Antiguo', 'position' => 2, 'is_active' => false]);
    Task::factory()->count(2)->create(['task_type_id' => $design->id]);
    Task::factory()->create(['task_type_id' => $dev->id])->delete();

    $this->actingAs($this->admin)
        ->get('/admin/tipos-de-tarea')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/task-types/index')
            ->has('taskTypes', 3)
            ->where('taskTypes.0.name', 'Desarrollo')
            ->where('taskTypes.0.tasks_count', 1)
            ->where('taskTypes.0.in_use', true)
            ->where('taskTypes.1.name', 'Diseño UI')
            ->where('taskTypes.1.icon', 'palette')
            ->where('taskTypes.1.tasks_count', 2)
            ->where('taskTypes.2.is_active', false)
            ->where('taskTypes.2.in_use', false)
            ->where('icons', TaskTypeIcons::ICONS));
});

test('crea un tipo al final de la lista', function () {
    TaskType::factory()->create(['position' => 4]);
    $department = Department::factory()->create();

    $this->actingAs($this->admin)
        ->post('/admin/tipos-de-tarea', [
            'name' => 'Vídeo',
            'color' => '#e65fb3',
            'icon' => 'video',
            'department_id' => $department->id,
            'is_billable_default' => false,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    $type = TaskType::query()->where('name', 'Vídeo')->sole();
    expect($type->color)->toBe('#E65FB3')
        ->and($type->icon)->toBe('video')
        ->and($type->department_id)->toBe($department->id)
        ->and($type->is_billable_default)->toBeFalse()
        ->and($type->position)->toBe(5);
});

test('valida nombre único, color de la paleta e icono de la lista', function () {
    TaskType::factory()->create(['name' => 'Bug']);

    $this->actingAs($this->admin)
        ->post('/admin/tipos-de-tarea', [
            'name' => 'BUG',
            'color' => '#ff0000',
            'icon' => 'skull',
            'department_id' => 999,
            'is_billable_default' => true,
            'is_active' => true,
        ])
        ->assertSessionHasErrors(['name', 'color', 'icon', 'department_id']);

    expect(TaskType::query()->count())->toBe(1);
});

test('edita un tipo', function () {
    $type = TaskType::factory()->create(['name' => 'Soporte', 'color' => '#0171FF']);

    $this->actingAs($this->admin)
        ->put("/admin/tipos-de-tarea/{$type->id}", [
            'name' => 'Soporte técnico',
            'color' => '#56667A',
            'icon' => null,
            'department_id' => null,
            'is_billable_default' => true,
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors();

    $type->refresh();
    expect($type->name)->toBe('Soporte técnico')
        ->and($type->color)->toBe('#56667A')
        ->and($type->is_active)->toBeFalse();
});

test('subir y bajar reordenan y dejan posiciones consecutivas', function () {
    // Tipos con posiciones repetidas, como los que crea una factoría.
    $a = TaskType::factory()->create(['name' => 'A', 'position' => 0]);
    $b = TaskType::factory()->create(['name' => 'B', 'position' => 0]);
    $c = TaskType::factory()->create(['name' => 'C', 'position' => 0]);

    $order = fn () => TaskType::query()->ordered()->orderBy('id')->pluck('name')->all();

    $this->actingAs($this->admin)->post("/admin/tipos-de-tarea/{$c->id}/mover", ['direction' => 'up'])->assertRedirect();
    expect($order())->toBe(['A', 'C', 'B'])
        ->and(TaskType::query()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);

    $this->actingAs($this->admin)->post("/admin/tipos-de-tarea/{$a->id}/mover", ['direction' => 'down']);
    expect($order())->toBe(['C', 'A', 'B']);

    // En los extremos no hace nada.
    $this->actingAs($this->admin)->post("/admin/tipos-de-tarea/{$c->id}/mover", ['direction' => 'up']);
    $this->actingAs($this->admin)->post("/admin/tipos-de-tarea/{$b->id}/mover", ['direction' => 'down']);
    expect($order())->toBe(['C', 'A', 'B']);

    $this->actingAs($this->admin)
        ->post("/admin/tipos-de-tarea/{$b->id}/mover", ['direction' => 'sideways'])
        ->assertSessionHasErrors('direction');
});

test('un tipo sin tareas se borra; uno en uso se desactiva y las tareas lo conservan', function () {
    $unused = TaskType::factory()->create();
    $used = TaskType::factory()->create();
    $task = Task::factory()->create(['task_type_id' => $used->id]);

    $this->actingAs($this->admin)
        ->delete("/admin/tipos-de-tarea/{$unused->id}")
        ->assertInertiaFlash('toast.type', 'success');

    expect(TaskType::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(TaskType::withTrashed()->whereKey($unused->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->delete("/admin/tipos-de-tarea/{$used->id}")
        ->assertInertiaFlash('toast.type', 'info');

    expect($used->fresh()?->is_active)->toBeFalse()
        ->and($used->fresh()?->trashed())->toBeFalse()
        ->and($task->fresh()?->task_type_id)->toBe($used->id);
});
