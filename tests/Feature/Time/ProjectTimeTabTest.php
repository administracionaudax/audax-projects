<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\TimeEntryStatus;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Pestaña Horas del proyecto /proyectos/{project}/horas (SPEC §6, D-021).
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->dev = Department::factory()->create(['name' => 'Desarrollo']);
    $this->ana = User::factory()->employee()->inDepartment($this->design)->create(['name' => 'Ana']);
    $this->bruno = User::factory()->employee()->inDepartment($this->dev)->create(['name' => 'Bruno']);

    $this->project = Project::factory()->hourBank()->create(['code' => 'ACME-WEB']);
    $this->project->addMember($this->ana);
    $this->project->addMember($this->bruno);
    $this->bank = HourBank::factory()->hours(2)->allowOverage()->create(['project_id' => $this->project->id, 'name' => 'Bolsa Q3']);
    $this->other = HourBank::factory()->create(['project_id' => $this->project->id, 'name' => 'Bolsa Q4']);
    $this->task = Task::factory()->inBank($this->bank)->create(['title' => 'Home']);
    $this->otherTask = Task::factory()->inBank($this->other)->create(['title' => 'Blog']);

    $this->log = fn (User $user, string $date, int $minutes, array $attributes = [], ?Task $task = null): TimeEntry => TimeEntry::factory()
        ->forTask($task ?? $this->task)->on($date)->minutes($minutes)->create(['user_id' => $user->id, ...$attributes]);

    ($this->log)($this->ana, '2026-09-01', 90);
    ($this->log)($this->bruno, '2026-09-02', 60, ['is_billable' => false]); // 30 min de exceso
    ($this->log)($this->ana, '2026-09-03', 45, ['status' => TimeEntryStatus::Approved], $this->otherTask);
});

it('un empleado solo ve sus entradas y sus totales', function () {
    $this->actingAs($this->ana)
        ->get("/proyectos/{$this->project->id}/horas")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/time', false)
            ->where('project.code', 'ACME-WEB')
            ->where('canManage', false)
            ->where('scope', 'mine')
            ->has('entries.data', 2)
            ->where('entries.data.0.user.name', 'Ana')
            ->where('totals.minutes', 135)
            ->where('options.people', [['id' => $this->ana->id, 'name' => 'Ana']]));
});

it('un gestor del proyecto y un admin ven todas, con totales dentro de bolsa, exceso y facturable', function (string $who) {
    $viewer = $who === 'gestor' ? User::factory()->employee()->create() : User::factory()->admin()->create();
    if ($who === 'gestor') {
        $this->project->addMember($viewer, isManager: true);
    }

    $this->actingAs($viewer)
        ->get("/proyectos/{$this->project->id}/horas")
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'all')
            ->where('canManage', true)
            ->has('entries.data', 3)
            // Más recientes primero.
            ->where('entries.data.0.date', '2026-09-03')
            ->where('totals.entries', 3)
            ->where('totals.minutes', 195)
            ->where('totals.overage_minutes', 30)
            ->where('totals.in_bank_minutes', 165)
            ->where('totals.billable_minutes', 135)
            ->has('options.people', 2)
            ->where('options.banks', [['id' => $this->bank->id, 'name' => 'Bolsa Q3'], ['id' => $this->other->id, 'name' => 'Bolsa Q4']]));
})->with(['gestor', 'admin']);

it('un responsable ve las de su equipo (y las suyas), no las de otros departamentos', function () {
    $head = User::factory()->departmentManager()->create();
    $this->design->managers()->attach($head);

    $this->actingAs($head)
        ->get("/proyectos/{$this->project->id}/horas")
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'team')
            ->has('entries.data', 2)
            ->where('totals.minutes', 135));
});

it('filtra por persona, fechas, bolsa, estado y facturable', function (array $query, int $count, int $minutes) {
    $admin = User::factory()->admin()->create();
    $query = array_map(fn ($value) => $value instanceof Closure ? $value($this) : $value, $query);

    $this->actingAs($admin)
        ->get("/proyectos/{$this->project->id}/horas?".http_build_query($query))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', $count)
            ->where('totals.minutes', $minutes));
})->with([
    'persona' => [['persona' => fn ($test) => $test->bruno->id], 1, 60],
    'desde' => [['desde' => '2026-09-02'], 2, 105],
    'hasta' => [['hasta' => '2026-09-01'], 1, 90],
    'bolsa' => [['bolsa' => fn ($test) => $test->other->id], 1, 45],
    'estado' => [['estado' => 'approved'], 1, 45],
    'facturable no' => [['facturable' => 'no'], 1, 60],
    'facturable sí' => [['facturable' => 'si'], 2, 135],
    'valores no válidos se ignoran' => [['desde' => 'ayer', 'estado' => 'x', 'persona' => 'y'], 3, 195],
]);

it('marca qué entradas puede editar quien mira', function () {
    ($this->log)($this->ana, '2026-09-04', 30, ['status' => TimeEntryStatus::Locked]);
    $admin = User::factory()->admin()->create();

    $editable = fn (User $viewer): array => collect($this->actingAs($viewer)->get("/proyectos/{$this->project->id}/horas")->inertiaProps()['entries']['data'])
        ->mapWithKeys(fn (array $entry) => [$entry['date'] => $entry['can_edit']])->all();

    expect($editable($this->ana))->toBe(['2026-09-04' => false, '2026-09-03' => false, '2026-09-01' => true])
        ->and($editable($admin))->toBe(['2026-09-04' => true, '2026-09-03' => false, '2026-09-02' => true, '2026-09-01' => true]);
});

it('pagina de 50 en 50 sin N+1 y con la exportación desactivada hasta la Fase 2', function () {
    foreach (range(1, 55) as $index) {
        ($this->log)($index % 2 === 0 ? $this->ana : $this->bruno, '2026-08-'.str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT), 10);
    }
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get("/proyectos/{$this->project->id}/horas")
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 50)
            ->where('entries.meta.total', 58)
            ->where('entries.meta.last_page', 2)
            ->where('entries.links.prev', null));

    $this->actingAs($admin)
        ->get("/proyectos/{$this->project->id}/horas?page=2")
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 8));
});

it('clientes e invitados fuera', function () {
    $this->get("/proyectos/{$this->project->id}/horas")->assertRedirect(route('login'));
    $this->actingAs(userWithRole('client'))->get("/proyectos/{$this->project->id}/horas")->assertRedirect(route('portal.home'));
});
