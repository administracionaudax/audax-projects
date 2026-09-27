<?php

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Notifications\Tasks\TaskAssignedNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Workload\Concerns\BuildsWorkloadScenario;

/*
| Reasignar y replanificar desde la vista Carga (D-052): TaskPolicy::update + alcance de la vista,
| siempre con TaskWriter. Tras guardar, la matriz se recalcula.
*/

pest()->use(BuildsWorkloadScenario::class);

beforeEach(function () {
    $this->buildWorkloadScenario();

    $this->patch = fn (string $who, string $task, array $data, string $from = '/carga') => $this->actingAs($this->people[$who])
        ->from($from)
        ->patch("/carga/tareas/{$this->tasks[$task]->id}", $data);

    $this->cellPlanned = function (string $who, string $person, string $date): int {
        $planned = 0;

        $this->actingAs($this->people[$who])->get('/carga')->assertInertia(function (Assert $page) use ($person, $date, &$planned) {
            $planned = $this->workloadCell($page->toArray()['props']['matrix'], $person, $date)['planned'];
        });

        return $planned;
    };
});

describe('reasignar desde la celda de una persona sobrecargada', function () {
    it('el responsable reasigna a alguien de su equipo y la carga se recalcula', function () {
        Notification::fake();
        $cell = "/carga?celda={$this->people['elena']->id}:2026-10-13";

        expect(($this->cellPlanned)('raul', 'elena', '2026-10-13'))->toBe(600);

        ($this->patch)('raul', 'elena_app', ['assignee_user_id' => $this->people['raul']->id], $cell)
            ->assertRedirect($cell)
            ->assertSessionHasNoErrors();

        expect($this->tasks['elena_app']->fresh()->assignee_user_id)->toBe($this->people['raul']->id)
            ->and(($this->cellPlanned)('raul', 'elena', '2026-10-13'))->toBe(300)
            ->and(($this->cellPlanned)('raul', 'raul', '2026-10-13'))->toBe(300);

        // Con TaskWriter: queda en la auditoría de la tarea (y no se avisa a sí mismo).
        Notification::assertNotSentTo($this->people['raul'], TaskAssignedNotification::class);
        expect(Activity::query()->where('subject_type', (new Task)->getMorphClass())->where('subject_id', $this->tasks['elena_app']->id)->where('event', 'updated')->exists())->toBeTrue();
    });

    it('si el nuevo responsable no es miembro del proyecto, pasa a serlo para poder imputar', function () {
        expect($this->projects['app']->hasMember($this->people['raul']))->toBeFalse();

        ($this->patch)('raul', 'elena_app', ['assignee_user_id' => $this->people['raul']->id])
            ->assertRedirect('/carga')
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Tarea «Pantalla de reservas» asignada a Raúl Responsable, que pasa a ser miembro de APP para poder imputar. La carga se ha recalculado.');

        expect($this->projects['app']->hasMember($this->people['raul']))->toBeTrue();
    });

    it('sin cambio de miembros, avisa de que la carga se ha recalculado; el nuevo responsable recibe el aviso de asignación', function () {
        Notification::fake();

        ($this->patch)('raul', 'elena_web', ['assignee_user_id' => $this->people['lucia']->id])
            ->assertInertiaFlash('toast.message', 'Tarea «Maquetar la home» actualizada. La carga se ha recalculado.');

        Notification::assertSentTo($this->people['lucia'], TaskAssignedNotification::class);
    });

    it('el alta como miembro y la reasignación van juntas: si falla el alta, la tarea no cambia ni sale el aviso', function () {
        Notification::fake();
        ProjectMember::creating(fn () => throw new RuntimeException('Fallo simulado al dar de alta al miembro'));

        ($this->patch)('raul', 'elena_app', ['assignee_user_id' => $this->people['lucia']->id])->assertServerError();

        expect($this->tasks['elena_app']->fresh()->assignee_user_id)->toBe($this->people['elena']->id)
            ->and($this->projects['app']->hasMember($this->people['lucia']))->toBeFalse();
        Notification::assertNothingSent();
    });

    it('y si falla el cambio de la tarea, tampoco queda el alta como miembro', function () {
        Notification::fake();

        // «Pantalla de reservas» empieza el 13/10: una entrega anterior la rechaza TaskWriter.
        ($this->patch)('raul', 'elena_app', ['assignee_user_id' => $this->people['lucia']->id, 'due_date' => '2026-10-10'])
            ->assertSessionHasErrors('due_date');

        expect($this->tasks['elena_app']->fresh()->assignee_user_id)->toBe($this->people['elena']->id)
            ->and($this->projects['app']->hasMember($this->people['lucia']))->toBeFalse()
            ->and(Activity::query()->where('subject_id', $this->projects['app']->id)->where('event', 'member_added')->exists())->toBeFalse();
        Notification::assertNothingSent();
    });

    it('añade al nuevo responsable como miembro (sin gestión) y lo deja en la auditoría del proyecto', function () {
        ($this->patch)('raul', 'elena_app', ['assignee_user_id' => $this->people['raul']->id])->assertRedirect('/carga');

        $membership = $this->projects['app']->members()->whereKey($this->people['raul']->id)->first();

        expect($membership)->not->toBeNull()
            ->and($membership->membership->is_manager)->toBeFalse()
            ->and(Activity::query()->where('subject_id', $this->projects['app']->id)->where('event', 'member_added')->exists())->toBeTrue();
    });

    it('cambia las fechas y la estimación con las reglas de Tareas', function () {
        ($this->patch)('raul', 'elena_web', ['start_date' => '2026-10-15', 'due_date' => '2026-10-16', 'estimated_minutes' => 600])
            ->assertRedirect('/carga')
            ->assertSessionHasNoErrors();

        $task = $this->tasks['elena_web']->fresh();

        expect($task->start_date->toDateString())->toBe('2026-10-15')
            ->and($task->estimated_minutes)->toBe(600)
            ->and(($this->cellPlanned)('raul', 'elena', '2026-10-13'))->toBe(300)
            ->and(($this->cellPlanned)('raul', 'elena', '2026-10-15'))->toBe(300);
    });

    it('rechaza una entrega anterior al inicio o una estimación no válida', function () {
        ($this->patch)('raul', 'elena_web', ['start_date' => '2026-10-16', 'due_date' => '2026-10-13'])
            ->assertSessionHasErrors('due_date');

        ($this->patch)('raul', 'elena_web', ['estimated_minutes' => 0])
            ->assertSessionHasErrors('estimated_minutes');

        ($this->patch)('raul', 'elena_web', ['due_date' => '13/10/2026'])
            ->assertSessionHasErrors('due_date');

        expect($this->tasks['elena_web']->fresh()->due_date->toDateString())->toBe('2026-10-16');
    });

    it('ignora los campos que no son de la vista Carga', function () {
        ($this->patch)('raul', 'elena_web', ['title' => 'Otro título', 'estimated_minutes' => 900])->assertSessionHasNoErrors();

        expect($this->tasks['elena_web']->fresh())
            ->title->toBe('Maquetar la home')
            ->estimated_minutes->toBe(900);
    });
});

describe('quién puede reasignar (D-052)', function () {
    it('un empleado cambia fechas y estimación de lo suyo, pero no lo reasigna', function () {
        ($this->patch)('elena', 'elena_web', ['due_date' => '2026-10-20'])->assertSessionHasNoErrors();
        expect($this->tasks['elena_web']->fresh()->due_date->toDateString())->toBe('2026-10-20');

        ($this->patch)('elena', 'elena_web', ['assignee_user_id' => $this->people['lucia']->id])->assertForbidden();
        expect($this->tasks['elena_web']->fresh()->assignee_user_id)->toBe($this->people['elena']->id);
    });

    it('un empleado no toca las tareas de otros, aunque sea miembro del proyecto', function () {
        ($this->patch)('elena', 'lucia_web', ['due_date' => '2026-10-30'])->assertForbidden();
        ($this->patch)('elena', 'lucia_web', ['assignee_user_id' => $this->people['elena']->id])->assertForbidden();

        expect($this->tasks['lucia_web']->fresh()->assignee_user_id)->toBe($this->people['lucia']->id);
    });

    it('sin ser miembro del proyecto, un empleado no edita ni lo suyo (TaskPolicy)', function () {
        $other = Project::factory()->create();
        $this->tasks['foreign'] = Task::factory()->create(['project_id' => $other->id, 'assignee_user_id' => $this->people['elena']->id, 'estimated_minutes' => 60, 'due_date' => '2026-10-15']);

        ($this->patch)('elena', 'foreign', ['due_date' => '2026-10-20'])->assertForbidden();
    });

    it('un responsable no toca la carga de otro departamento ni asigna fuera de su equipo', function () {
        ($this->patch)('raul', 'pablo_app', ['due_date' => '2026-10-30'])->assertForbidden();

        ($this->patch)('raul', 'elena_web', ['assignee_user_id' => $this->people['pablo']->id])
            ->assertSessionHasErrors('assignee_user_id');

        expect($this->tasks['elena_web']->fresh()->assignee_user_id)->toBe($this->people['elena']->id);
    });

    it('un gestor reasigna las tareas de su proyecto entre sus miembros, pero no a otras personas', function () {
        ($this->patch)('sergio', 'lucia_web', ['assignee_user_id' => $this->people['pablo']->id])->assertSessionHasNoErrors();
        expect($this->tasks['lucia_web']->fresh()->assignee_user_id)->toBe($this->people['pablo']->id);

        ($this->patch)('sergio', 'lucia_web', ['assignee_user_id' => $this->people['marta']->id])
            ->assertSessionHasErrors('assignee_user_id');

        // APP no la gestiona: no la toca.
        ($this->patch)('sergio', 'pablo_app', ['assignee_user_id' => $this->people['sergio']->id])->assertForbidden();
    });

    it('el admin reasigna a cualquiera y puede dejar una tarea sin asignar', function () {
        ($this->patch)('ana', 'pablo_app', ['assignee_user_id' => $this->people['elena']->id])->assertSessionHasNoErrors();
        expect($this->tasks['pablo_app']->fresh()->assignee_user_id)->toBe($this->people['elena']->id);

        ($this->patch)('ana', 'pablo_app', ['assignee_user_id' => null])->assertSessionHasNoErrors();
        expect($this->tasks['pablo_app']->fresh()->assignee_user_id)->toBeNull();
    });

    it('nadie asigna a una persona desactivada o a un cliente', function () {
        ($this->patch)('ana', 'pablo_app', ['assignee_user_id' => $this->people['olga']->id])->assertSessionHasErrors('assignee_user_id');
        ($this->patch)('ana', 'pablo_app', ['assignee_user_id' => $this->people['client']->id])->assertSessionHasErrors('assignee_user_id');

        expect($this->tasks['pablo_app']->fresh()->assignee_user_id)->toBe($this->people['pablo']->id);
    });

    it('los clientes y los invitados no entran', function () {
        ($this->patch)('client', 'elena_web', ['due_date' => '2026-10-20'])->assertRedirect(route('portal.home'));
        auth()->logout();
        $this->patch("/carga/tareas/{$this->tasks['elena_web']->id}", ['due_date' => '2026-10-20'])->assertRedirect(route('login'));
    });
});

describe('bandeja «Sin asignar»', function () {
    it('el responsable asigna una tarea de su departamento a alguien de su equipo', function () {
        ($this->patch)('raul', 'unassigned_design', ['assignee_user_id' => $this->people['lucia']->id])->assertSessionHasNoErrors();

        expect($this->tasks['unassigned_design']->fresh()->assignee_user_id)->toBe($this->people['lucia']->id);
    });

    it('pero no las de otros departamentos ni las que no tienen departamento', function () {
        ($this->patch)('raul', 'unassigned_dev', ['assignee_user_id' => $this->people['lucia']->id])->assertForbidden();
        ($this->patch)('raul', 'unassigned_none', ['assignee_user_id' => $this->people['lucia']->id])->assertForbidden();

        ($this->patch)('ana', 'unassigned_none', ['assignee_user_id' => $this->people['lucia']->id])->assertSessionHasNoErrors();
    });

    it('un empleado no se asigna tareas de la bandeja desde aquí', function () {
        ($this->patch)('elena', 'unassigned_design', ['assignee_user_id' => $this->people['elena']->id])->assertForbidden();
    });
});

describe('bandeja «De tus proyectos»', function () {
    it('un gestor reparte desde ahí las tareas de su proyecto: la carga de los miembros no se ve, la suya sí', function () {
        $managedTitles = function (): array {
            $titles = [];

            $this->actingAs($this->people['sergio'])->get('/carga')->assertInertia(function (Assert $page) use (&$titles) {
                $titles = array_column($page->toArray()['props']['trays']['managed']['tasks'], 'assignee', 'title');
            });

            return $titles;
        };

        // Asigna una sin asignar de Diseño a Lucía (miembro de WEB): sigue en la bandeja, ya con su nombre.
        ($this->patch)('sergio', 'unassigned_design', ['assignee_user_id' => $this->people['lucia']->id])->assertSessionHasNoErrors();
        expect($managedTitles()['Banner de campaña'])->toBe(['id' => $this->people['lucia']->id, 'name' => 'Lucía Martín']);

        // Se queda «Iconos» (de Lucía): sale de la bandeja y pasa a su carga (Lucía tenía vacaciones el
        // 13 y el 14; él trabaja del 13 al 16: 2 h cada día).
        expect(($this->cellPlanned)('sergio', 'sergio', '2026-10-13'))->toBe(0);
        ($this->patch)('sergio', 'lucia_web', ['assignee_user_id' => $this->people['sergio']->id])->assertSessionHasNoErrors();
        expect($managedTitles())->not->toHaveKey('Iconos')
            ->and(($this->cellPlanned)('sergio', 'sergio', '2026-10-13'))->toBe(120)
            ->and(($this->cellPlanned)('sergio', 'sergio', '2026-10-16'))->toBe(120);

        // Replanifica una de Elena sin tocar el responsable.
        ($this->patch)('sergio', 'elena_unplanned', ['estimated_minutes' => 120])->assertSessionHasNoErrors();
        expect($this->tasks['elena_unplanned']->fresh()->estimated_minutes)->toBe(120);
    });

    it('un empleado sin proyectos que gestionar no reparte las tareas de esa bandeja', function () {
        ($this->patch)('lucia', 'unassigned_none', ['assignee_user_id' => $this->people['lucia']->id])->assertForbidden();
        ($this->patch)('lucia', 'elena_web', ['assignee_user_id' => $this->people['lucia']->id])->assertForbidden();
    });
});

describe('bandeja «Sin planificar»', function () {
    it('poner la estimación o la entrega saca la tarea de la bandeja y la pone en la matriz', function () {
        ($this->patch)('raul', 'elena_unplanned', ['estimated_minutes' => 480, 'start_date' => '2026-10-15'])->assertSessionHasNoErrors();

        $this->actingAs($this->people['raul'])->get('/carga')->assertInertia(fn (Assert $page) => $page
            ->where('trays.unplanned.total', 0)
            ->where('matrix.groups.0.people.0.cells.3.planned', 300 + 480));
    });
});
