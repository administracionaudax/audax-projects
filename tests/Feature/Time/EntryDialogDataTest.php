<?php

use App\Models\Department;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Datos del diálogo de imputación: GET /horas/tareas (buscador) y GET /horas/opciones (personas y
| ajustes). "Hoy" es el viernes 25/09/2026.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->department = Department::factory()->create(['name' => 'Diseño']);
    $this->user = User::factory()->employee()->inDepartment($this->department)->create(['name' => 'Ana']);
    $this->web = Project::factory()->create(['code' => 'ACME-WEB', 'name' => 'Web de Acme']);
    $this->web->addMember($this->user);
    $this->foreign = Project::factory()->create(['code' => 'BETA', 'name' => 'Beta']);
    // Nombres fijos: las búsquedas con q=a no pueden depender de un nombre aleatorio de la factoría
    // («Reuniones» coincide por el nombre de su proyecto, «Agencia»).
    $this->internal = Project::factory()->internal()->create(['code' => 'INTERNO', 'name' => 'Agencia']);

    $this->home = Task::factory()->assignedTo($this->user)->create(['project_id' => $this->web->id, 'title' => 'Maquetar la home']);
    $this->menu = Task::factory()->create(['project_id' => $this->web->id, 'title' => 'Menú principal']);
    $this->milestone = Task::factory()->milestone()->create(['project_id' => $this->web->id, 'title' => 'Entrega de la home']);
    $this->meetings = Task::factory()->create(['project_id' => $this->internal->id, 'title' => 'Reuniones']);
    $this->strange = Task::factory()->create(['project_id' => $this->foreign->id, 'title' => 'Maquetar landing']);

    $this->titles = fn ($response): array => collect($response->json('tasks'))->pluck('title')->sort()->values()->all();
});

it('busca tareas donde puede imputar: sus proyectos y los internos, sin hitos', function () {
    $response = $this->actingAs($this->user)->getJson('/horas/tareas?q=maquet')->assertOk();

    expect(($this->titles)($response))->toBe(['Maquetar la home']);
    expect($response->json('tasks.0'))->toMatchArray([
        'id' => $this->home->id,
        'project_id' => $this->web->id,
        'is_billable' => true,
        'is_completed' => false,
    ])->and($response->json('tasks.0.project.code'))->toBe('ACME-WEB');

    // Por código de proyecto y en el proyecto interno (nunca facturable).
    expect(($this->titles)($this->actingAs($this->user)->getJson('/horas/tareas?q=acme-web')))->toBe(['Maquetar la home', 'Menú principal']);
    $internal = $this->actingAs($this->user)->getJson('/horas/tareas?q=reuni');
    expect($internal->json('tasks.0.title'))->toBe('Reuniones')
        ->and($internal->json('tasks.0.is_billable'))->toBeFalse()
        ->and($internal->json('tasks.0.project.is_internal'))->toBeTrue();
});

it('sin texto propone sus tareas abiertas, las imputadas hace poco y las internas', function () {
    TimeEntry::factory()->forTask($this->menu)->on('2026-09-22')->create(['user_id' => $this->user->id]);

    expect(($this->titles)($this->actingAs($this->user)->getJson('/horas/tareas')))
        ->toBe(['Maquetar la home', 'Menú principal', 'Reuniones']);
});

it('no ofrece tareas de proyectos archivados ni borradas', function () {
    $this->web->update(['status' => 'archived']);

    expect(($this->titles)($this->actingAs($this->user)->getJson('/horas/tareas?q=a')))->toBe(['Reuniones']);
});

it('para otra persona busca en sus proyectos, y solo si quien busca puede imputar por ella', function () {
    $colleague = User::factory()->employee()->create();
    $this->actingAs($colleague)->getJson("/horas/tareas?user_id={$this->user->id}")->assertForbidden();

    $head = User::factory()->departmentManager()->create();
    $this->department->managers()->attach($head);
    expect(($this->titles)($this->actingAs($head)->getJson("/horas/tareas?user_id={$this->user->id}&q=a")))
        ->toBe(['Maquetar la home', 'Menú principal', 'Reuniones']);

    // Un gestor, solo en los proyectos que gestiona.
    $manager = User::factory()->employee()->create();
    $this->foreign->addMember($manager, isManager: true);
    $this->foreign->addMember($this->user);
    expect(($this->titles)($this->actingAs($manager)->getJson("/horas/tareas?user_id={$this->user->id}&q=a")))
        ->toBe(['Maquetar landing']);
});

it('las opciones incluyen a quien pregunta y los ajustes de imputación', function () {
    Setting::set('time_entry_description_required', true);

    $this->actingAs($this->user)
        ->getJson('/horas/opciones')
        ->assertOk()
        ->assertJsonPath('people.0.id', $this->user->id)
        ->assertJsonCount(1, 'people')
        ->assertJsonPath('settings.today', '2026-09-25')
        ->assertJsonPath('settings.allow_future', false)
        ->assertJsonPath('settings.description_required', true);
});

it('las personas para imputar: el admin, todas; el responsable, su equipo; el gestor, su proyecto', function () {
    $admin = User::factory()->admin()->create(['name' => 'Zoe Admin']);
    $inactive = User::factory()->employee()->inactive()->create();
    $client = userWithRole('client');
    $head = User::factory()->departmentManager()->create(['name' => 'Hugo']);
    $this->department->managers()->attach($head);
    $manager = User::factory()->employee()->create(['name' => 'Marta']);
    $this->web->addMember($manager, isManager: true);

    $ids = fn (User $actor, string $query = '') => collect($this->actingAs($actor)->getJson('/horas/opciones'.$query)->json('people'))->pluck('id')->all();

    $all = $ids($admin);
    expect($all[0])->toBe($admin->id)
        ->and($all)->toContain($this->user->id, $head->id, $manager->id)
        ->and($all)->not->toContain($inactive->id, $client->id);

    expect($ids($head))->toBe([$head->id, $this->user->id]);
    expect($ids($manager, "?project_id={$this->web->id}"))->toContain($this->user->id, $manager->id);
    expect($ids($manager, "?project_id={$this->foreign->id}"))->toBe([$manager->id]);
});

it('los clientes y los invitados no acceden', function (string $url) {
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(userWithRole('client'))->get($url)->assertRedirect(route('portal.home'));
})->with(['/horas/tareas', '/horas/opciones']);
