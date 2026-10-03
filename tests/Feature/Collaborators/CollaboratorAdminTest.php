<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| Colaboradores externos (D-134) en la administración: el admin los crea, invita y edita con el rol
| «Colaborador externo» y departamento opcional; nunca son gestores de proyecto ni responsables de
| departamento; sus horas las aprueba el responsable de su departamento o, si no tiene, un admin.
*/

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
    $this->design = Department::factory()->create(['name' => 'Diseño']);
});

it('el admin crea e invita a un colaborador externo, con departamento o sin él', function () {
    $this->travelTo(LocalTime::today()->setTime(10, 0));

    $this->actingAs($this->admin)
        ->post('/admin/usuarios', ['name' => 'Amparo', 'email' => 'amparo@example.com', 'role' => 'collaborator', 'department_id' => $this->design->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->post('/admin/usuarios', ['name' => 'Daniel', 'email' => 'daniel@example.com', 'role' => 'collaborator'])
        ->assertSessionHasNoErrors();

    $amparo = User::query()->where('email', 'amparo@example.com')->sole();
    $daniel = User::query()->where('email', 'daniel@example.com')->sole();

    expect($amparo->isCollaborator())->toBeTrue()
        ->and($amparo->department_id)->toBe($this->design->id)
        ->and($daniel->isCollaborator())->toBeTrue()
        ->and($daniel->department_id)->toBeNull()
        ->and($amparo->isInternal())->toBeTrue();
});

it('al pasar a colaborador deja de ser responsable de departamento y co-gestor; si es gestor principal, no se puede', function () {
    $manager = User::factory()->departmentManager()->inDepartment($this->design)->create();
    $this->design->managers()->attach($manager);
    $project = Project::factory()->create();
    $project->addMember($manager, isManager: true);

    $this->actingAs($this->admin)
        ->put("/admin/usuarios/{$manager->id}", ['name' => $manager->name, 'email' => $manager->email, 'role' => 'collaborator', 'department_id' => $this->design->id])
        ->assertSessionHasNoErrors();

    $manager->refresh();
    expect($manager->isCollaborator())->toBeTrue()
        ->and($manager->managedDepartmentIds())->toBe([])
        ->and($project->isManagedBy($manager))->toBeFalse()
        ->and($project->hasMember($manager))->toBeTrue();

    $owner = User::factory()->employee()->create();
    Project::factory()->create(['owner_user_id' => $owner->id]);

    $this->actingAs($this->admin)
        ->put("/admin/usuarios/{$owner->id}", ['name' => $owner->name, 'email' => $owner->email, 'role' => 'collaborator'])
        ->assertSessionHasErrors(['role' => __('admin.users.errors.collaborator_owner')]);
    expect($owner->fresh()->hasRole(Role::Employee->value))->toBeTrue();
});

it('un colaborador nunca es gestor de un proyecto', function () {
    $sara = User::factory()->collaborator()->create();
    $project = Project::factory()->create();
    $url = "/proyectos/{$project->id}";

    $this->actingAs($this->admin)->post("{$url}/miembros", ['user_id' => $sara->id, 'is_manager' => true])
        ->assertSessionHasErrors(['is_manager' => __('projects.errors.collaborator_cannot_manage')]);
    expect($project->hasMember($sara))->toBeFalse();

    $this->actingAs($this->admin)->post("{$url}/miembros", ['user_id' => $sara->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->patch("{$url}/miembros/{$sara->id}", ['is_manager' => true])->assertSessionHasErrors('is_manager');
    $this->actingAs($this->admin)->put("{$url}/gestor-principal", ['owner_user_id' => $sara->id])->assertSessionHasErrors('owner_user_id');

    expect($project->fresh()->isManagedBy($sara))->toBeFalse()
        ->and($sara->canManageProject($project))->toBeFalse();
});

it('un colaborador no es gestor principal al crear un proyecto ni al dar de baja a otro', function () {
    $sara = User::factory()->collaborator()->create();
    $leaving = User::factory()->employee()->create();
    $project = Project::factory()->create(['owner_user_id' => $leaving->id]);

    $this->actingAs($this->admin)
        ->post('/proyectos', ['name' => 'Nuevo', 'billing_type' => 'internal', 'owner_user_id' => $sara->id])
        ->assertSessionHasErrors(['owner_user_id' => __('projects.errors.collaborator_cannot_manage')]);

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$leaving->id}/baja", ['owners' => [['project_id' => $project->id, 'owner_user_id' => $sara->id]]])
        ->assertSessionHasErrors('owners.0.owner_user_id');
    expect($project->fresh()->owner_user_id)->toBe($leaving->id);
});

it('un colaborador no puede ser responsable de un departamento', function () {
    $sara = User::factory()->collaborator()->create();

    $this->actingAs($this->admin)
        ->post('/admin/departamentos', ['name' => 'Producción', 'color' => '#0171FF', 'manager_ids' => [$sara->id]])
        ->assertSessionHasErrors('manager_ids');
});

describe('aprobación de sus horas (D-020, D-134)', function () {
    beforeEach(function () {
        Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

        $this->head = User::factory()->departmentManager()->inDepartment($this->design)->create();
        $this->design->managers()->attach($this->head);
        $development = Department::factory()->create(['name' => 'Desarrollo']);
        $this->otherHead = User::factory()->departmentManager()->inDepartment($development)->create();
        $development->managers()->attach($this->otherHead);

        $this->project = Project::factory()->create();
        $this->task = Task::factory()->create(['project_id' => $this->project->id]);

        $this->submitted = function (User $collaborator): TimesheetPeriod {
            $this->project->addMember($collaborator);
            TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->minutes(60)->create(['user_id' => $collaborator->id]);
            $this->actingAs($collaborator)->post('/horas/semana/enviar', ['week' => '2026-W39'])->assertSessionHasNoErrors();

            return TimesheetPeriod::query()->where('user_id', $collaborator->id)->sole();
        };
    });

    it('las aprueba el responsable de su departamento (y no el de otro)', function () {
        $amparo = User::factory()->collaborator()->inDepartment($this->design)->create();
        $period = ($this->submitted)($amparo);

        expect($period->status)->toBe(TimesheetStatus::Submitted);

        $this->actingAs($this->otherHead)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertForbidden();
        $this->actingAs($this->head)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertSessionHasNoErrors();

        expect($period->fresh()->status)->toBe(TimesheetStatus::Approved);
    });

    it('sin departamento, las aprueba un admin (y ningún responsable)', function () {
        $daniel = User::factory()->collaborator()->create();
        $period = ($this->submitted)($daniel);

        $this->actingAs($this->head)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertForbidden();
        $this->actingAs($this->admin)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertSessionHasNoErrors();

        expect($period->fresh()->status)->toBe(TimesheetStatus::Approved);
    });
});
