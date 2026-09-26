<?php

use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Alta de proyecto (SPEC §6, D-022, D-032): el gestor principal (por defecto quien lo crea) queda
| como miembro gestor; los miembros iniciales entran sin gestión; el código es único y se sugiere;
| los datos económicos solo con view-financials.
*/

beforeEach(function () {
    $this->manager = userWithRole('department_manager');
    $this->client = Client::factory()->create(['name' => 'Acme Corporación']);

    $this->valid = fn (array $overrides = []): array => [
        'name' => 'Web corporativa',
        'client_id' => $this->client->id,
        'billing_type' => 'hour_bank',
        'status' => 'active',
        'color' => '#179FA5',
        ...$overrides,
    ];
});

test('crea el proyecto con quien lo crea como gestor principal y miembro gestor', function () {
    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['code' => 'acme web']))
        ->assertSessionHasNoErrors();

    $project = Project::query()->where('code', 'ACME-WEB')->firstOrFail();

    expect($project->owner_user_id)->toBe($this->manager->id)
        ->and($project->billing_type)->toBe(BillingType::HourBank)
        ->and($project->status)->toBe(ProjectStatus::Active)
        ->and($project->isManagedBy($this->manager))->toBeTrue()
        ->and($project->members()->count())->toBe(1);
});

test('redirige al resumen del proyecto nuevo con un aviso', function () {
    $response = $this->actingAs($this->manager)->post('/proyectos', ($this->valid)());

    $project = Project::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('projects.show', $project));
});

test('otro gestor principal: es miembro gestor; los miembros iniciales entran sin gestión', function () {
    $owner = userWithRole('employee');
    $members = User::factory()->employee()->count(2)->create();

    $this->actingAs($this->manager)->post('/proyectos', ($this->valid)([
        'owner_user_id' => $owner->id,
        'member_ids' => [$members[0]->id, $members[1]->id, $owner->id],
    ]))->assertSessionHasNoErrors();

    $project = Project::query()->latest('id')->firstOrFail();

    expect($project->owner_user_id)->toBe($owner->id)
        ->and($project->isManagedBy($owner))->toBeTrue()
        ->and($project->isManagedBy($members[0]))->toBeFalse()
        ->and($project->hasMember($members[1]))->toBeTrue()
        ->and($project->hasMember($this->manager))->toBeFalse()
        ->and($project->members()->count())->toBe(3);
});

test('el gestor principal y los miembros son personas internas activas', function (string $state) {
    $person = match ($state) {
        'cliente' => userWithRole('client'),
        'desactivada' => User::factory()->employee()->inactive()->create(),
    };

    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['owner_user_id' => $person->id, 'member_ids' => [$person->id]]))
        ->assertSessionHasErrors(['owner_user_id', 'member_ids.0']);

    expect(Project::query()->count())->toBe(0);
})->with(['cliente', 'desactivada']);

test('sin código, genera uno único a partir del cliente y el nombre', function () {
    Project::factory()->create(['code' => 'ACME-WEB']);

    $this->actingAs($this->manager)->post('/proyectos', ($this->valid)())->assertSessionHasNoErrors();

    expect(Project::query()->latest('id')->firstOrFail()->code)->toBe('ACME-WEB-2');
});

test('un código repetido da un error que sugiere otro libre', function () {
    Project::factory()->create(['code' => 'ACME-WEB']);

    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['code' => 'acme-web']))
        ->assertSessionHasErrors(['code' => 'El código ACME-WEB ya existe. Prueba con ACME-WEB-2.']);
});

test('el código repetido cuenta también los proyectos borrados (índice único)', function () {
    Project::factory()->create(['code' => 'VIEJO'])->delete();

    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['code' => 'VIEJO']))
        ->assertSessionHasErrors('code');
});

test('el código solo admite letras sin acentos, números y guiones', function () {
    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['code' => 'ACME/WEB']))
        ->assertSessionHasErrors('code');
});

test('un proyecto interno no lleva cliente; los demás lo necesitan y activo', function () {
    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['billing_type' => 'internal', 'client_id' => $this->client->id, 'code' => 'FORMACION']))
        ->assertSessionHasNoErrors();

    expect(Project::query()->where('code', 'FORMACION')->value('client_id'))->toBeNull();

    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['client_id' => null]))
        ->assertSessionHasErrors(['client_id' => 'Elige el cliente del proyecto.']);

    $inactive = Client::factory()->inactive()->create();

    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['client_id' => $inactive->id]))
        ->assertSessionHasErrors(['client_id' => 'Ese cliente está desactivado. Elige un cliente activo.']);
});

test('valida el color de la paleta, el estado inicial y las fechas', function () {
    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)([
            'color' => '#FF0000',
            'status' => 'archived',
            'start_date' => '2026-10-10',
            'due_date' => '2026-10-01',
        ]))
        ->assertSessionHasErrors(['color', 'status', 'due_date']);
});

test('el presupuesto se guarda en minutos', function () {
    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['budget_minutes' => 120 * 60, 'start_date' => '2026-10-01']))
        ->assertSessionHasNoErrors();

    $project = Project::query()->latest('id')->firstOrFail();

    expect($project->budget_minutes)->toBe(7200)
        ->and($project->start_date?->toDateString())->toBe('2026-10-01');
});

test('sin view-financials se ignoran el importe cerrado y la tarifa', function () {
    $this->actingAs($this->manager)
        ->post('/proyectos', ($this->valid)(['billing_type' => 'fixed_price', 'fixed_price_amount' => '9000', 'hourly_rate' => '70']))
        ->assertSessionHasNoErrors();

    $project = Project::query()->latest('id')->firstOrFail();

    expect($project->fixed_price_amount)->toBeNull()
        ->and($project->hourly_rate)->toBeNull();
});

test('con view-financials se guardan el importe cerrado y la tarifa', function () {
    $this->actingAs(userWithRole('admin'))
        ->post('/proyectos', ($this->valid)(['billing_type' => 'fixed_price', 'fixed_price_amount' => '9000.50', 'hourly_rate' => '70']))
        ->assertSessionHasNoErrors();

    $project = Project::query()->latest('id')->firstOrFail();

    expect($project->fixed_price_amount)->toBe('9000.50')
        ->and($project->hourly_rate)->toBe('70.00');
});

test('la creación queda en la auditoría', function () {
    $this->actingAs($this->manager)->post('/proyectos', ($this->valid)());

    $project = Project::query()->latest('id')->firstOrFail();

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => $project->getMorphClass(),
        'subject_id' => $project->id,
        'event' => 'created',
        'causer_id' => $this->manager->id,
    ]);
});

test('el formulario de alta ofrece clientes activos, personas internas activas y valores por defecto', function () {
    Client::factory()->inactive()->create(['name' => 'Zeta Desactivado']);
    userWithRole('client', ['name' => 'Contacto Cliente']);
    User::factory()->employee()->inactive()->create(['name' => 'Persona Baja']);

    $this->actingAs($this->manager)
        ->get('/proyectos/nuevo')
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/create')
            ->has('clients', 1)
            ->where('clients.0.name', 'Acme Corporación')
            ->where('defaults.owner_user_id', $this->manager->id)
            ->where('defaults.status', 'active')
            ->where('people', fn ($people) => collect($people)->pluck('name')->doesntContain('Contacto Cliente')
                && collect($people)->pluck('name')->doesntContain('Persona Baja')
                && collect($people)->pluck('id')->contains($this->manager->id)));
});
