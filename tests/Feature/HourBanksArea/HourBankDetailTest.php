<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\TimeEntryStatus;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Detalle de una bolsa (SPEC §8, D-021): consumo por semana ISO (dentro frente a exceso), por
| persona (solo gestores, responsables y admins), por tipo de tarea, tareas con lo comprometido y
| entradas (un empleado solo ve las suyas). Sin datos económicos sin view-financials.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);

    $this->department = Department::factory()->create();
    $this->owner = userWithRole('employee');
    $this->ana = User::factory()->employee()->inDepartment($this->department)->create(['name' => 'Ana']);
    $this->bruno = User::factory()->employee()->create(['name' => 'Bruno']);
    $this->project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    $this->project->addMember($this->ana);
    $this->project->addMember($this->bruno);
    $this->bank = HourBank::factory()->hours(10)->create(['project_id' => $this->project->id, 'price_amount' => '800.00']);

    $this->design = TaskType::factory()->create(['name' => 'Diseño UI']);
    $this->task = Task::factory()->inBank($this->bank)->create(['task_type_id' => $this->design->id, 'title' => 'Maquetar']);
    $this->untyped = Task::factory()->inBank($this->bank)->create(['title' => 'Reunión']);

    // Semana del 21/09: 8 h de Ana; semana del 28/09: 3 h de Bruno (1 h en exceso).
    TimeEntry::factory()->forTask($this->task)->minutes(8 * 60)->on('2026-09-22')->create(['user_id' => $this->ana->id]);
    TimeEntry::factory()->forTask($this->untyped)->minutes(3 * 60)->on('2026-09-29')->create(['user_id' => $this->bruno->id]);

    $this->url = "/proyectos/{$this->project->id}/bolsas/{$this->bank->id}";
});

test('consumo por semana ISO: dentro de la bolsa frente a exceso, sin huecos', function () {
    TimeEntry::factory()->forTask($this->task)->minutes(30)->on('2026-10-14')->create(['user_id' => $this->ana->id]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/hour-bank')
            ->has('weekly', 4)
            ->where('weekly.0.week', '2026-W39')
            ->where('weekly.0.week_start', '2026-09-21')
            ->where('weekly.0.in_bank_minutes', 480)
            ->where('weekly.0.overage_minutes', 0)
            ->where('weekly.1.week', '2026-W40')
            ->where('weekly.1.in_bank_minutes', 120)
            ->where('weekly.1.overage_minutes', 60)
            ->where('weekly.2.in_bank_minutes', 0)
            ->where('weekly.2.overage_minutes', 0)
            ->where('weekly.3.week', '2026-W42')
            ->where('weekly.3.overage_minutes', 30));
});

test('por persona: lo ven el gestor, los responsables y los admins; el empleado no', function () {
    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('byPerson', 2)
            ->where('byPerson.0.user.name', 'Ana')
            ->where('byPerson.0.minutes', 480)
            ->where('byPerson.1.user.name', 'Bruno')
            ->where('byPerson.1.overage_minutes', 60));

    $this->actingAs(userWithRole('admin'))
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page->has('byPerson', 2));

    $this->actingAs($this->ana)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page->where('byPerson', null));
});

test('un responsable ve en el reparto por persona solo a su equipo (D-021)', function () {
    $lead = userWithRole('department_manager');
    $lead->managedDepartments()->attach($this->department->id);

    $this->actingAs($lead)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('byPerson', 1)
            ->where('byPerson.0.user.name', 'Ana')
            ->has('entries.data', 1));
});

test('por tipo de tarea, con «sin tipo»', function () {
    $this->actingAs($this->ana)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('byType', 2)
            ->where('byType.0.type.name', 'Diseño UI')
            ->where('byType.0.minutes', 480)
            ->where('byType.1.type', null)
            ->where('byType.1.minutes', 180)
            ->where('byType.1.overage_minutes', 60));
});

test('entradas: un empleado solo ve las suyas; el gestor, todas (D-021)', function () {
    $this->actingAs($this->ana)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.user.name', 'Ana')
            ->where('entries.data.0.task.title', 'Maquetar')
            ->where('entries.meta.total', 1));

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 2)
            ->where('entries.data.0.date', '2026-09-29')
            ->where('entries.data.0.overage_minutes', 60)
            ->where('entries.data.1.date', '2026-09-22'));
});

test('las entradas se paginan de 20 en 20', function () {
    foreach (range(1, 22) as $day) {
        TimeEntry::factory()->forTask($this->task)->minutes(10)->on('2026-08-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT))
            ->create(['user_id' => $this->ana->id]);
    }

    $this->actingAs($this->owner)
        ->get("{$this->url}?pagina=2")
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 4)
            ->where('entries.meta.total', 24)
            ->where('entries.meta.current_page', 2));
});

test('sin view-financials no llegan tarifas, precios ni instantáneas', function () {
    TimeEntry::query()->update(['hourly_rate_snapshot' => '60.00', 'status' => TimeEntryStatus::Approved]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->missing('bank.price_amount')
            ->missing('bank.hourly_rate')
            ->missing('entries.data.0.hourly_rate_snapshot')
            ->missing('project.hourly_rate'));

    $this->actingAs(userWithRole('admin'))
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('bank.price_amount', '800.00')
            ->where('entries.data.0.hourly_rate_snapshot', '60.00'));
});

test('cualquier interno ve el detalle y el % de consumo; un cliente no', function () {
    $this->actingAs(userWithRole('employee'))
        ->get($this->url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bank.consumed_minutes', 660)
            ->where('bank.consumed_pct', 110)
            ->where('bank.overage_minutes', 60)
            ->where('bank.can.update', false)
            ->has('entries.data', 0));

    $this->actingAs(userWithRole('client'))->get($this->url)->assertRedirect(route('portal.home'));
});

test('tareas de la bolsa con estimada, imputada y comprometida', function () {
    $this->task->update(['estimated_minutes' => 600]);
    $parent = Task::factory()->inBank($this->bank)->create(['title' => 'Padre', 'estimated_minutes' => 999]);
    Task::factory()->subtaskOf($parent)->create(['title' => 'Hija', 'estimated_minutes' => 120]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(function (Assert $page) {
            $rows = collect($page->toArray()['props']['tasks'])->keyBy('title');

            expect($rows['Maquetar'])
                ->toMatchArray(['depth' => 0, 'estimated_minutes' => 600, 'logged_minutes' => 480, 'committed_minutes' => 120])
                ->and($rows['Reunión'])->toMatchArray(['estimated_minutes' => null, 'logged_minutes' => 180, 'committed_minutes' => 0])
                ->and($rows['Padre'])->toMatchArray(['estimated_minutes' => 120, 'committed_minutes' => null])
                ->and($rows['Hija'])->toMatchArray(['depth' => 1, 'estimated_minutes' => 120, 'committed_minutes' => 120])
                ->and($page->toArray()['props']['bank']['committed_minutes'])->toBe(240);

            // La subtarea va justo debajo de su padre.
            $titles = array_column($page->toArray()['props']['tasks'], 'title');
            expect(array_search('Hija', $titles, true))->toBe(array_search('Padre', $titles, true) + 1);
        });
});

test('sin N+1 en el detalle con varias entradas, personas, tipos y tareas', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get($this->url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Todas las relaciones ya presentes (una colección vacía no lanza su consulta de eager loading).
    $this->task->update(['assignee_user_id' => $this->ana->id]);

    $queries();
    $few = $queries();

    foreach (range(1, 4) as $i) {
        $type = TaskType::factory()->create();
        $task = Task::factory()->inBank($this->bank)->create(['task_type_id' => $type->id, 'assignee_user_id' => $this->ana->id]);
        Task::factory()->subtaskOf($task)->create(['assignee_user_id' => $this->bruno->id]);
        $person = User::factory()->employee()->create();
        $this->project->addMember($person);
        TimeEntry::factory()->forTask($task)->minutes(30)->on('2026-09-2'.$i)->create(['user_id' => $person->id]);
    }

    expect($queries())->toBe($few);
});
