<?php

use App\Models\Client;
use App\Models\Department;
use App\Models\Setting;
use App\Models\SuggestionPost;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Database\Seeders\DefaultSettingsSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Rutas de la Weekly (contrato 10.1, F-016): URLs en español, nombres en inglés, autorización antes
| de nada (403), 501 en lo que aún no existe y 404 con el módulo apagado (F-177).
*/

function weeklyRouteActor(string $actor): ?User
{
    return match ($actor) {
        'guest' => null,
        'collaborator' => User::factory()->collaborator()->create(),
        'client' => User::factory()->portalOf(Client::factory()->create())->create(),
        default => userWithRole($actor),
    };
}

it('las páginas de la Weekly: la plantilla entra; colaboradores, clientes e invitados no', function (string $path, string $actor, int $status) {
    WeeklyCycle::factory()->active()->create();
    $user = weeklyRouteActor($actor);

    $response = $user === null ? $this->get($path) : $this->actingAs($user)->get($path);

    $response->assertStatus($status);
})->with([
    '/weeklies',
    '/mi-espacio',
    '/ia',
    '/ayuda',
])->with([
    'admin' => ['admin', 200],
    'responsable' => ['department_manager', 200],
    'empleado' => ['employee', 200],
    'colaborador' => ['collaborator', 403],
    'cliente' => ['client', 302],
    'invitado' => ['guest', 302],
]);

it('el histórico y el informe llegan con el contrato de los Resources', function () {
    $active = WeeklyCycle::factory()->active()->create();
    $closed = WeeklyCycle::factory()->create(['report' => ['global_summary' => 'Todo bien', 'team_risks' => [], 'client_updates' => []]]);
    WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $active->id]);
    WeeklySubmission::factory()->create(['weekly_cycle_id' => $active->id]);

    $this->actingAs(userWithRole('employee'))
        ->get('/weeklies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('weeklies/index')
            ->has('cycles', 2)
            ->where('cycles.0.id', $active->id)
            ->where('cycles.0.number', 'W41-26')
            ->where('cycles.0.status', 'active')
            ->where('cycles.0.deadline_date', '2026-10-09')
            ->where('cycles.0.submissions_count', 1)
            ->where('cycles.1.has_report', true)
            ->where('can.manage', false));

    $this->actingAs(userWithRole('department_manager'))
        ->get("/weeklies/{$closed->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('weeklies/show')
            ->where('cycle.report.global_summary', 'Todo bien')
            ->where('cycle.status', 'closed')
            ->has('cycle.audio_sections', 0)
            ->where('can.generate', true)
            ->where('can.close', false));
});

it('Mi espacio trae la semana activa y mi weekly, sin la de los demás', function () {
    $cycle = WeeklyCycle::factory()->active()->create();
    $me = userWithRole('employee');
    $mine = WeeklySubmission::factory()->create(['weekly_cycle_id' => $cycle->id, 'user_id' => $me->id]);
    WeeklyEntry::factory()->general()->create(['weekly_submission_id' => $mine->id, 'body' => 'Interno']);
    WeeklySubmission::factory()->create(['weekly_cycle_id' => $cycle->id]);

    $this->actingAs($me)
        ->get('/mi-espacio')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-space/index')
            ->where('cycle.id', $cycle->id)
            ->where('submission.id', $mine->id)
            ->where('submission.is_submitted', false)
            ->where('submission.entries.0.client_id', null)
            ->where('submission.entries.0.body', 'Interno'));
});

it('las acciones aún sin hacer responden 501 tras autorizar, y 403 a quien no puede', function (string $method, Closure $uri, string $allowed, string $denied) {
    $cycle = WeeklyCycle::factory()->active()->create();
    $path = $uri($cycle);

    $this->actingAs(userWithRole($allowed))->json($method, $path)->assertStatus(501);
    $this->actingAs(userWithRole($denied))->json($method, $path)->assertForbidden();
})->with([
    'contenido de ayuda' => ['POST', fn (WeeklyCycle $c) => '/ayuda/preguntas', 'department_manager', 'employee'],
    'tableros' => ['POST', fn (WeeklyCycle $c) => '/ayuda/sugerencias/tableros', 'admin', 'employee'],
]);

it('lo de 10.2 lo hace solo quien gestiona: 403 a la plantilla', function (string $method, Closure $uri) {
    $cycle = WeeklyCycle::factory()->active()->create();

    $this->actingAs(userWithRole('employee'))->json($method, $uri($cycle))->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())->json($method, $uri($cycle))->assertForbidden();
})->with([
    'abrir semana' => ['POST', fn (WeeklyCycle $c) => '/weeklies'],
    'ampliar plazo' => ['PUT', fn (WeeklyCycle $c) => "/weeklies/{$c->id}/plazo"],
    'borrar' => ['DELETE', fn (WeeklyCycle $c) => "/weeklies/{$c->id}"],
    'eximir' => ['POST', fn (WeeklyCycle $c) => "/weeklies/{$c->id}/exenciones"],
    'cerrar (10.3)' => ['POST', fn (WeeklyCycle $c) => "/weeklies/{$c->id}/cerrar"],
    'generar informe (10.3)' => ['POST', fn (WeeklyCycle $c) => "/weeklies/{$c->id}/informe"],
    'editar informe (10.3)' => ['PUT', fn (WeeklyCycle $c) => "/weeklies/{$c->id}/informe"],
    'generar audio (10.3)' => ['POST', fn (WeeklyCycle $c) => "/weeklies/{$c->id}/audio"],
    'avisos (10.5)' => ['GET', fn (WeeklyCycle $c) => '/weeklies/avisos'],
    'guardar avisos (10.5)' => ['PUT', fn (WeeklyCycle $c) => '/weeklies/avisos'],
    'envío manual (10.5)' => ['POST', fn (WeeklyCycle $c) => '/weeklies/avisos/enviar'],
    'recordar (10.5)' => ['POST', fn (WeeklyCycle $c) => "/weeklies/{$c->id}/recordar"],
]);

it('lo de cada persona responde 501 a cualquiera de la plantilla', function (string $method, string $path) {
    $cycle = WeeklyCycle::factory()->active()->create();

    $this->actingAs(userWithRole('employee'))->json($method, str_replace('{cycle}', (string) $cycle->id, $path))->assertStatus(501);
})->with([
    ['POST', '/ia/preguntas'],
    ['POST', '/ayuda/sugerencias'],
    ['POST', '/ayuda/me-gusta'],
]);

it('con la semana cerrada no se escribe ni se exime', function () {
    $closed = WeeklyCycle::factory()->create();

    $this->actingAs(userWithRole('employee'))->json('PUT', "/mi-espacio/weeklies/{$closed->id}")->assertForbidden();
    $this->actingAs(userWithRole('admin'))->json('POST', "/weeklies/{$closed->id}/exenciones")->assertForbidden();
    $this->actingAs(userWithRole('admin'))->json('POST', "/weeklies/{$closed->id}/cerrar")->assertForbidden();
});

it('una exención de otra semana da 404', function () {
    $cycle = WeeklyCycle::factory()->active()->create();
    $other = WeeklyExemption::factory()->create();

    $this->actingAs(userWithRole('admin'))->json('DELETE', "/weeklies/{$cycle->id}/exenciones/{$other->id}")->assertNotFound();
});

it('los resúmenes IA de una persona: su responsable sí (202), un compañero no (403)', function () {
    Queue::fake();
    $department = Department::factory()->create();
    $person = userWithRole('employee', ['department_id' => $department->id]);
    $boss = userWithRole('department_manager');
    $boss->managedDepartments()->attach($department->id);

    $this->actingAs($boss)->json('POST', "/equipo/{$person->id}/resumen-ia")->assertStatus(202);
    $this->actingAs(userWithRole('employee', ['department_id' => $department->id]))->json('POST', "/equipo/{$person->id}/resumen-ia")->assertForbidden();
    $this->actingAs($person)->json('POST', "/equipo/{$person->id}/resumen-ia")->assertForbidden();
});

it('una sugerencia ajena: votar sí (501), editarla no (403), su estado solo quien gestiona', function () {
    $post = SuggestionPost::factory()->create();
    $employee = userWithRole('employee');

    $this->actingAs($employee)->json('POST', "/ayuda/sugerencias/{$post->id}/voto")->assertStatus(501);
    $this->actingAs($employee)->json('PUT', "/ayuda/sugerencias/{$post->id}")->assertForbidden();
    $this->actingAs($employee)->json('PUT', "/ayuda/sugerencias/{$post->id}/estado")->assertForbidden();
    $this->actingAs(userWithRole('department_manager'))->json('PUT', "/ayuda/sugerencias/{$post->id}/estado")->assertStatus(501);
});

it('un módulo apagado da 404 a todos y vuelve al encenderlo (F-177)', function () {
    $admin = userWithRole('admin');
    Setting::set('modules', ['help' => false, 'assistant' => false]);

    $this->actingAs($admin)->get('/ayuda')->assertNotFound();
    $this->actingAs($admin)->json('POST', '/ayuda/sugerencias')->assertNotFound();
    $this->actingAs($admin)->get('/ia')->assertNotFound();
    $this->actingAs($admin)->get('/weeklies')->assertOk();

    Setting::set('modules', ['weeklies' => false]);
    $this->actingAs($admin)->get('/mi-espacio')->assertNotFound();
    $this->actingAs($admin)->get('/ayuda')->assertOk();
});

it('los ajustes guardan los módulos y el aviso global, y las props compartidas los llevan', function () {
    $this->seed(DefaultSettingsSeeder::class);
    $admin = userWithRole('admin');
    $valid = [
        'company_name' => 'Audax Studio',
        'require_2fa' => false,
        'timer_rounding_minutes' => 1,
        'timer_warning_hours' => 10,
        'hour_bank_alert_thresholds' => [75, 90, 100],
        'allow_hour_bank_overage' => true,
        'require_timesheet_approval' => true,
        'allow_future_time_entries' => false,
        'time_entry_description_required' => false,
        'max_attachment_mb' => 50,
        'default_work_minutes' => [480, 480, 480, 480, 480, 0, 0],
        'weekly_digest_enabled' => true,
        'occupancy_low_threshold' => 70,
        'occupancy_high_threshold' => 110,
    ];

    $this->actingAs($admin)
        ->put('/admin/ajustes', [...$valid, 'modules' => ['assistant' => false], 'global_banner' => ['message' => '  Mantenimiento el viernes  ', 'tone' => 'warning']])
        ->assertSessionHasNoErrors();

    expect(Setting::get('modules'))->toBe(['weeklies' => true, 'project_status' => true, 'help' => true, 'suggestions' => true, 'assistant' => false])
        ->and(Setting::get('global_banner'))->toBe(['message' => 'Mantenimiento el viernes', 'tone' => 'warning']);

    $this->actingAs($admin)
        ->put('/admin/ajustes', [...$valid, 'modules' => ['otro' => true], 'global_banner' => ['message' => 'x', 'tone' => 'rojo']])
        ->assertSessionHasErrors(['modules', 'global_banner.tone']);

    $this->actingAs($admin)->put('/admin/ajustes', [...$valid, 'global_banner' => null])->assertSessionHasNoErrors();
    expect(Setting::get('global_banner'))->toBeNull();

    $this->actingAs(userWithRole('department_manager'))
        ->get('/weeklies')
        ->assertInertia(fn (Assert $page) => $page
            ->where('config.modules.assistant', false)
            ->where('config.modules.weeklies', true)
            ->where('config.global_banner', null)
            ->where('auth.can.useWeeklies', true)
            ->where('auth.can.manageWeeklies', true)
            ->where('auth.can.viewAiUsage', false));
});
