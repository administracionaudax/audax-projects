<?php

use App\Enums\Permission;
use App\Enums\WeeklyExemptionReason;
use App\Models\Client;
use App\Models\Department;
use App\Models\Dictation;
use App\Models\SuggestionComment;
use App\Models\SuggestionPost;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RoleModel;

/*
| Permisos de la Weekly (D-147, D-134, D-151): `use-weeklies` para la plantilla interna,
| `manage-weeklies` para admins y responsables, nada para colaboradores externos ni clientes.
*/

function weeklyActor(string $actor): User
{
    return match ($actor) {
        'collaborator' => User::factory()->collaborator()->create(),
        'client' => User::factory()->portalOf(Client::factory()->create())->create(),
        default => userWithRole($actor),
    };
}

it('reparte las gates de la Weekly por rol', function (string $actor, bool $use, bool $manage, bool $aiUsage) {
    $user = weeklyActor($actor);

    expect(Gate::forUser($user)->allows('use-weeklies'))->toBe($use)
        ->and(Gate::forUser($user)->allows('manage-weeklies'))->toBe($manage)
        ->and(Gate::forUser($user)->allows('manage-help'))->toBe($manage)
        ->and(Gate::forUser($user)->allows('view-ai-usage'))->toBe($aiUsage);
})->with([
    'admin' => ['admin', true, true, true],
    'responsable' => ['department_manager', true, true, false],
    'empleado' => ['employee', true, false, false],
    'colaborador externo' => ['collaborator', false, false, false],
    'cliente' => ['client', false, false, false],
]);

it('el permiso manage-weeklies existe y lo tienen el rol admin y el de responsable', function () {
    expect(RoleModel::findByName('admin')->hasPermissionTo(Permission::ManageWeeklies->value))->toBeTrue()
        ->and(RoleModel::findByName('department_manager')->hasPermissionTo(Permission::ManageWeeklies->value))->toBeTrue()
        ->and(RoleModel::findByName('employee')->hasPermissionTo(Permission::ManageWeeklies->value))->toBeFalse();
});

it('un colaborador nunca gestiona la Weekly, aunque le den el permiso por error', function () {
    $collaborator = User::factory()->collaborator()->create();
    $collaborator->givePermissionTo(Permission::ManageWeeklies->value);

    expect(Gate::forUser($collaborator->fresh())->allows('manage-weeklies'))->toBeFalse();
});

it('un empleado puede recibir manage-weeklies expresamente; desactivado, nada', function () {
    $employee = userWithRole('employee');
    $employee->givePermissionTo(Permission::ManageWeeklies->value);
    $inactive = userWithRole('admin', ['is_active' => false]);

    expect(Gate::forUser($employee->fresh())->allows('manage-weeklies'))->toBeTrue()
        ->and(Gate::forUser($inactive)->allows('use-weeklies'))->toBeFalse()
        ->and(Gate::forUser($inactive)->allows('manage-weeklies'))->toBeFalse();
});

it('las semanas: verlas la plantilla; gestionarlas quien gestiona; plazo y cierre solo con la activa', function () {
    $active = WeeklyCycle::factory()->active()->create();
    $closed = WeeklyCycle::factory()->create();
    $manager = userWithRole('department_manager');
    $employee = userWithRole('employee');
    $collaborator = User::factory()->collaborator()->create();

    expect($employee->can('viewAny', WeeklyCycle::class))->toBeTrue()
        ->and($employee->can('view', $closed))->toBeTrue()
        ->and($collaborator->can('view', $active))->toBeFalse()
        ->and($employee->can('create', WeeklyCycle::class))->toBeFalse()
        ->and($employee->can('generate', $active))->toBeFalse()
        ->and($manager->can('create', WeeklyCycle::class))->toBeTrue()
        ->and($manager->can('generate', $closed))->toBeTrue()
        ->and($manager->can('update', $closed))->toBeTrue()
        ->and($manager->can('extendDeadline', $active))->toBeTrue()
        ->and($manager->can('extendDeadline', $closed))->toBeFalse()
        ->and($manager->can('close', $active))->toBeTrue()
        ->and($manager->can('close', $closed))->toBeFalse()
        ->and($manager->can('remind', $closed))->toBeFalse()
        ->and($manager->can('delete', $closed))->toBeTrue()
        ->and($employee->can('delete', $closed))->toBeFalse();
});

it('cada uno escribe la suya con la semana activa; los borradores son privados', function () {
    $active = WeeklyCycle::factory()->active()->create();
    $closed = WeeklyCycle::factory()->create();
    $author = userWithRole('employee');
    $colleague = userWithRole('employee');
    $admin = userWithRole('admin');
    $draft = WeeklySubmission::factory()->create(['weekly_cycle_id' => $active->id, 'user_id' => $author->id]);
    $sent = WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $author->id]);

    expect($author->can('create', [WeeklySubmission::class, $active]))->toBeTrue()
        ->and($author->can('create', [WeeklySubmission::class, $closed]))->toBeFalse()
        ->and(User::factory()->collaborator()->create()->can('create', [WeeklySubmission::class, $active]))->toBeFalse()
        ->and($author->can('update', $draft))->toBeTrue()
        ->and($author->can('update', $sent))->toBeFalse()
        ->and($colleague->can('update', $draft))->toBeFalse()
        ->and($author->can('view', $draft))->toBeTrue()
        ->and($colleague->can('view', $draft))->toBeFalse()
        ->and($admin->can('view', $draft))->toBeFalse()
        ->and($colleague->can('view', $sent))->toBeTrue()
        ->and($author->can('delete', $sent))->toBeFalse();
});

it('las exenciones: las pone quien gestiona; la quita quien gestiona o la propia persona', function () {
    $active = WeeklyCycle::factory()->active()->create();
    $closed = WeeklyCycle::factory()->create();
    $manager = userWithRole('department_manager');
    $employee = userWithRole('employee');
    $other = userWithRole('employee');
    $own = WeeklyExemption::factory()->create(['weekly_cycle_id' => $active->id, 'user_id' => $employee->id]);
    $foreign = WeeklyExemption::factory()->create(['weekly_cycle_id' => $active->id, 'user_id' => $other->id]);
    $frozen = WeeklyExemption::factory()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $employee->id]);
    $absence = WeeklyExemption::factory()->absence()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $other->id]);

    expect($manager->can('create', [WeeklyExemption::class, $active]))->toBeTrue()
        ->and($manager->can('create', [WeeklyExemption::class, $closed]))->toBeFalse()
        ->and($employee->can('create', [WeeklyExemption::class, $active]))->toBeFalse()
        ->and($employee->can('waive', [WeeklyExemption::class, $active]))->toBeTrue()
        ->and($employee->can('waive', [WeeklyExemption::class, $closed]))->toBeFalse()
        ->and($employee->can('delete', $own))->toBeTrue()
        ->and($employee->can('delete', $foreign))->toBeFalse()
        ->and($manager->can('delete', $foreign))->toBeTrue()
        ->and($manager->can('delete', $frozen))->toBeFalse()
        ->and($manager->can('delete', $absence))->toBeFalse()
        ->and($absence->reason)->toBe(WeeklyExemptionReason::Absence);
});

it('los resúmenes IA de una persona: el admin y sus responsables, nunca un compañero ni ella misma', function () {
    $department = Department::factory()->create();
    $person = userWithRole('employee', ['department_id' => $department->id]);
    $boss = userWithRole('department_manager');
    $boss->managedDepartments()->attach($department->id);
    $otherBoss = userWithRole('department_manager');
    $admin = userWithRole('admin');
    $colleague = userWithRole('employee', ['department_id' => $department->id]);

    expect(Gate::forUser($admin)->allows('view-person-ai-summary', $person))->toBeTrue()
        ->and(Gate::forUser($boss->fresh())->allows('view-person-ai-summary', $person))->toBeTrue()
        ->and(Gate::forUser($otherBoss)->allows('view-person-ai-summary', $person))->toBeFalse()
        ->and(Gate::forUser($colleague)->allows('view-person-ai-summary', $person))->toBeFalse()
        ->and(Gate::forUser($person)->allows('view-person-ai-summary', $person))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view-person-ai-summary', $admin))->toBeTrue();
});

it('las sugerencias: todos proponen, votan y comentan; el autor o quien gestiona editan; el estado, quien gestiona', function () {
    $author = userWithRole('employee');
    $other = userWithRole('employee');
    $manager = userWithRole('department_manager');
    $post = SuggestionPost::factory()->create(['author_id' => $author->id]);
    $comment = SuggestionComment::query()->create(['suggestion_post_id' => $post->id, 'author_id' => $other->id, 'body' => 'De acuerdo']);

    expect($other->can('create', SuggestionPost::class))->toBeTrue()
        ->and($other->can('vote', $post))->toBeTrue()
        ->and($other->can('update', $post))->toBeFalse()
        ->and($author->can('update', $post))->toBeTrue()
        ->and($manager->can('delete', $post))->toBeTrue()
        ->and($author->can('moderate', $post))->toBeFalse()
        ->and($manager->can('moderate', $post))->toBeTrue()
        ->and($manager->can('manageBoards', SuggestionPost::class))->toBeTrue()
        ->and(User::factory()->collaborator()->create()->can('viewAny', SuggestionPost::class))->toBeFalse()
        ->and($other->can('update', $comment))->toBeTrue()
        ->and($author->can('update', $comment))->toBeFalse()
        ->and($author->can('react', $comment))->toBeTrue()
        ->and($manager->can('delete', $comment))->toBeTrue()
        ->and($manager->can('update', $comment))->toBeFalse();
});

it('un dictado solo lo ve quien lo grabó', function () {
    $owner = userWithRole('employee');
    $dictation = Dictation::query()->create(['user_id' => $owner->id, 'context' => 'weekly_entry']);

    expect($owner->can('view', $dictation))->toBeTrue()
        ->and(userWithRole('admin')->can('view', $dictation))->toBeFalse()
        ->and($owner->can('create', Dictation::class))->toBeTrue()
        ->and(User::factory()->collaborator()->create()->can('create', Dictation::class))->toBeFalse();
});
