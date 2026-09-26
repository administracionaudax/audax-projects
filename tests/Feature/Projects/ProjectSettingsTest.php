<?php

use App\Enums\ProjectAlert;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Ajustes del proyecto (SPEC §6, D-005, D-023, D-032, D-037): editar los datos, miembros y
| gestores, cambio de gestor principal, alertas de cada gestor y archivo.
*/

beforeEach(function () {
    $this->owner = userWithRole('employee');
    $this->project = Project::factory()->create([
        'owner_user_id' => $this->owner->id,
        'code' => 'ACME-WEB',
        'status' => ProjectStatus::OnHold,
    ]);
    $this->url = "/proyectos/{$this->project->id}";

    $this->payload = fn (array $overrides = []): array => [
        'name' => $this->project->name,
        'code' => $this->project->code,
        'client_id' => $this->project->client_id,
        'billing_type' => $this->project->billing_type->value,
        'status' => $this->project->status->value,
        'color' => $this->project->color,
        ...$overrides,
    ];
});

test('el gestor edita los datos del proyecto y queda en la auditoría', function () {
    $this->actingAs($this->owner)
        ->put($this->url, ($this->payload)(['name' => 'Web nueva', 'description' => 'Rediseño', 'due_date' => '2026-12-31']))
        ->assertRedirect(route('projects.settings', $this->project))
        ->assertSessionHasNoErrors();

    $project = $this->project->fresh();

    expect($project->name)->toBe('Web nueva')
        ->and($project->description)->toBe('Rediseño')
        ->and($project->due_date?->toDateString())->toBe('2026-12-31');

    $this->assertDatabaseHas('activity_log', [
        'subject_id' => $project->id,
        'subject_type' => $project->getMorphClass(),
        'event' => 'updated',
        'causer_id' => $this->owner->id,
    ]);
});

test('puede conservar su código y su cliente aunque el cliente esté desactivado', function () {
    $this->project->client?->update(['is_active' => false]);

    $this->actingAs($this->owner)
        ->put($this->url, ($this->payload)())
        ->assertSessionHasNoErrors();
});

test('no puede usar el código de otro proyecto', function () {
    Project::factory()->create(['code' => 'OTRO']);

    $this->actingAs($this->owner)
        ->put($this->url, ($this->payload)(['code' => 'OTRO']))
        ->assertSessionHasErrors(['code' => 'El código OTRO ya existe. Prueba con OTRO-2.']);
});

test('un proyecto con bolsas no deja de ser de bolsas', function () {
    $project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    HourBank::factory()->create(['project_id' => $project->id]);

    $this->actingAs($this->owner)
        ->put("/proyectos/{$project->id}", [
            'name' => $project->name,
            'code' => $project->code,
            'client_id' => $project->client_id,
            'billing_type' => 'time_and_materials',
            'status' => 'active',
            'color' => $project->color,
        ])
        ->assertSessionHasErrors('billing_type');
});

test('en un proyecto archivado el estado no se cambia desde el formulario', function () {
    $this->project->update(['status' => ProjectStatus::Archived]);

    $this->actingAs($this->owner)
        ->put($this->url, ($this->payload)(['status' => 'active']))
        ->assertSessionHasErrors('status');

    $payload = ($this->payload)(['name' => 'Renombrado']);
    unset($payload['status']);

    $this->actingAs($this->owner)->put($this->url, $payload)->assertSessionHasNoErrors();

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Archived)
        ->and($this->project->fresh()->name)->toBe('Renombrado');
});

test('archivar y recuperar: vuelve al estado que tenía antes (D-037)', function () {
    $this->actingAs($this->owner)->post("{$this->url}/archivar")->assertRedirect();
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Archived);

    $this->actingAs($this->owner)->post("{$this->url}/desarchivar")->assertRedirect();
    expect($this->project->fresh()->status)->toBe(ProjectStatus::OnHold)
        ->and(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();
});

test('recuperar un proyecto archivado sin historial lo deja activo', function () {
    $project = Project::factory()->archived()->create(['owner_user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->post("/proyectos/{$project->id}/desarchivar");

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('añadir miembros: internos activos, como gestores o no, y sin repetir', function () {
    $designer = userWithRole('employee');
    $lead = userWithRole('employee');

    $this->actingAs($this->owner)
        ->post("{$this->url}/miembros", ['user_id' => $designer->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->owner)
        ->post("{$this->url}/miembros", ['user_id' => $lead->id, 'is_manager' => true])
        ->assertSessionHasNoErrors();

    expect($this->project->hasMember($designer))->toBeTrue()
        ->and($this->project->isManagedBy($designer))->toBeFalse()
        ->and($this->project->isManagedBy($lead))->toBeTrue();

    $this->actingAs($this->owner)
        ->post("{$this->url}/miembros", ['user_id' => $designer->id])
        ->assertSessionHasErrors('user_id');

    $this->actingAs($this->owner)
        ->post("{$this->url}/miembros", ['user_id' => userWithRole('client')->id])
        ->assertSessionHasErrors('user_id');
});

test('los cambios de miembros quedan en la auditoría del proyecto', function () {
    $designer = userWithRole('employee', ['name' => 'Laura Gómez']);

    $this->actingAs($this->owner)->post("{$this->url}/miembros", ['user_id' => $designer->id]);
    $this->actingAs($this->owner)->delete("{$this->url}/miembros/{$designer->id}");

    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->project->id, 'event' => 'member_added']);
    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->project->id, 'event' => 'member_removed']);
});

test('marcar y desmarcar gestor; al gestor principal no se le desmarca', function () {
    $member = userWithRole('employee');
    $this->project->addMember($member);

    $this->actingAs($this->owner)->patch("{$this->url}/miembros/{$member->id}", ['is_manager' => true]);
    expect($this->project->isManagedBy($member))->toBeTrue();

    $this->actingAs($this->owner)->patch("{$this->url}/miembros/{$member->id}", ['is_manager' => false]);
    expect($this->project->isManagedBy($member))->toBeFalse();

    $this->actingAs($this->owner)
        ->patch("{$this->url}/miembros/{$this->owner->id}", ['is_manager' => false])
        ->assertSessionHasErrors(['is_manager' => 'El gestor principal siempre es gestor del proyecto.']);

    expect($this->project->isManagedBy($this->owner))->toBeTrue();
});

test('quitar miembros, nunca al gestor principal', function () {
    $member = userWithRole('employee');
    $this->project->addMember($member);

    $this->actingAs($this->owner)->delete("{$this->url}/miembros/{$member->id}")->assertSessionHasNoErrors();
    expect($this->project->hasMember($member))->toBeFalse();

    $this->actingAs(userWithRole('admin'))
        ->delete("{$this->url}/miembros/{$this->owner->id}")
        ->assertSessionHasErrors('user_id');
    expect($this->project->hasMember($this->owner))->toBeTrue();

    $this->actingAs($this->owner)
        ->delete("{$this->url}/miembros/{$member->id}")
        ->assertSessionHasErrors('user_id');
});

test('cambiar de gestor principal: el nuevo pasa a gestor y el anterior sigue como gestor (D-032)', function () {
    $newOwner = userWithRole('employee');

    $this->actingAs(userWithRole('admin'))
        ->put("{$this->url}/gestor-principal", ['owner_user_id' => $newOwner->id])
        ->assertSessionHasNoErrors();

    $project = $this->project->fresh();

    expect($project->owner_user_id)->toBe($newOwner->id)
        ->and($project->isManagedBy($newOwner))->toBeTrue()
        ->and($project->isManagedBy($this->owner))->toBeTrue();

    $this->actingAs(userWithRole('admin'))
        ->put("{$this->url}/gestor-principal", ['owner_user_id' => $newOwner->id])
        ->assertSessionHasErrors('owner_user_id');

    $this->actingAs(userWithRole('admin'))
        ->put("{$this->url}/gestor-principal", ['owner_user_id' => User::factory()->employee()->inactive()->create()->id])
        ->assertSessionHasErrors('owner_user_id');
});

test('un miembro con sus alertas conserva sus preferencias al pasar a gestor principal', function () {
    $member = userWithRole('employee');
    $this->project->addMember($member, true, [ProjectAlert::HourBankOverage->value => false]);

    $this->actingAs($this->owner)->put("{$this->url}/gestor-principal", ['owner_user_id' => $member->id]);

    $membership = $this->project->members()->whereKey($member->id)->firstOrFail()->membership;

    expect($membership?->wantsAlert(ProjectAlert::HourBankOverage))->toBeFalse()
        ->and($membership?->is_manager)->toBeTrue();
});

test('alertas (D-023): cada gestor cambia las suyas y un admin las de cualquiera', function () {
    $coManager = userWithRole('employee');
    $this->project->addMember($coManager, true);
    $alerts = fn (User $user) => $this->project->members()->whereKey($user->id)->firstOrFail()->membership;

    $this->actingAs($this->owner)
        ->put("{$this->url}/miembros/{$this->owner->id}/alertas", ['alerts' => ['hour_bank_overage' => false]])
        ->assertSessionHasNoErrors();

    expect($alerts($this->owner)?->wantsAlert(ProjectAlert::HourBankOverage))->toBeFalse()
        ->and($alerts($this->owner)?->wantsAlert(ProjectAlert::HourBankThreshold))->toBeTrue();

    // Un gestor no toca las de otro gestor.
    $this->actingAs($this->owner)
        ->put("{$this->url}/miembros/{$coManager->id}/alertas", ['alerts' => ['hour_bank_threshold' => false]])
        ->assertForbidden();

    // Un responsable (que gestiona el proyecto) tampoco.
    $this->actingAs(userWithRole('department_manager'))
        ->put("{$this->url}/miembros/{$coManager->id}/alertas", ['alerts' => ['hour_bank_threshold' => false]])
        ->assertForbidden();

    // Un admin sí.
    $this->actingAs(userWithRole('admin'))
        ->put("{$this->url}/miembros/{$coManager->id}/alertas", ['alerts' => ['hour_bank_threshold' => false]])
        ->assertSessionHasNoErrors();

    expect($alerts($coManager)?->wantsAlert(ProjectAlert::HourBankThreshold))->toBeFalse()
        ->and($alerts($coManager)?->wantsAlert(ProjectAlert::HourBankOverage))->toBeTrue();
});

test('las alertas son solo de los gestores', function () {
    $member = userWithRole('employee');
    $this->project->addMember($member);

    $this->actingAs($member)
        ->put("{$this->url}/miembros/{$member->id}/alertas", ['alerts' => ['hour_bank_threshold' => false]])
        ->assertForbidden();
});

test('la página de ajustes lleva los miembros ordenados, sus alertas y qué alertas puede tocar cada cual', function () {
    $coManager = userWithRole('employee', ['name' => 'Ana Co']);
    $member = userWithRole('employee', ['name' => 'Beatriz Miembro']);
    $this->project->addMember($coManager, true);
    $this->project->addMember($member);

    $this->actingAs($this->owner)
        ->get("{$this->url}/ajustes")
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/settings')
            ->has('members', 3)
            ->where('members.0.id', $this->owner->id)
            ->where('members.0.is_owner', true)
            ->where('members.0.is_manager', true)
            ->where('members.1.id', $coManager->id)
            ->where('members.2.id', $member->id)
            ->where('members.2.is_manager', false)
            ->where('members.0.alert_preferences.hour_bank_threshold', true)
            ->where('can.editAlertsOf', [$this->owner->id])
            ->where('can.manageMembers', true)
            ->where('hasHourBanks', false));

    $this->actingAs(userWithRole('admin'))
        ->get("{$this->url}/ajustes")
        ->assertInertia(fn (Assert $page) => $page->where('can.editAlertsOf', [$this->owner->id, $coManager->id]));
});

test('sin view-financials la página de ajustes no lleva datos económicos', function () {
    $this->project->update(['hourly_rate' => '55.00']);

    $this->actingAs($this->owner)
        ->get("{$this->url}/ajustes")
        ->assertInertia(fn (Assert $page) => $page->missing('project.hourly_rate')->missing('project.fixed_price_amount'));

    $this->actingAs(userWithRole('admin'))
        ->get("{$this->url}/ajustes")
        ->assertInertia(fn (Assert $page) => $page->where('project.hourly_rate', '55.00'));
});

test('los clientes del selector son los activos y el actual', function () {
    Client::factory()->inactive()->create(['name' => 'Inactivo']);
    $this->project->client?->update(['is_active' => false]);

    $this->actingAs($this->owner)
        ->get("{$this->url}/ajustes")
        ->assertInertia(fn (Assert $page) => $page
            ->has('clients', 1)
            ->where('clients.0.id', $this->project->client_id)
            ->where('clients.0.is_active', false));
});
