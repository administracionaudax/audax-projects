<?php

use App\Models\ActiveTimer;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('las props compartidas incluyen el usuario, su tema, sus roles y sus permisos', function () {
    $admin = userWithRole('admin', ['theme_preference' => 'dark']);

    $this->actingAs($admin)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home', false)
            ->where('name', config('app.name'))
            ->where('auth.user.id', $admin->id)
            ->where('auth.user.email', $admin->email)
            ->where('auth.user.theme_preference', 'dark')
            ->where('auth.user.two_factor_enabled', false)
            ->where('auth.user.roles', ['admin'])
            ->where('auth.user.is_client', false)
            ->where('auth.user.avatar', null)
            ->where('auth.can.viewHourBanks', true)
            ->where('auth.can.viewAdmin', true)
            ->where('auth.can.viewFinancials', true)
            ->missing('auth.user.hourly_cost')
            ->missing('auth.user.password')
            ->has('sidebarOpen'));
});

test('un empleado no tiene permisos de bolsas, administración ni datos económicos', function () {
    $employee = User::factory()->withTwoFactor()->employee()->create();

    $this->actingAs($employee)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.roles', ['employee'])
            ->where('auth.user.two_factor_enabled', true)
            ->where('auth.can.viewHourBanks', false)
            ->where('auth.can.viewAdmin', false)
            ->where('auth.can.viewFinancials', false));
});

test('un cliente se identifica como tal en su portal', function () {
    // Un usuario del portal es de un cliente activo (D-063); sin cliente, el portal da 403.
    $client = User::factory()->portalOf(Client::factory()->create())->create();

    $this->actingAs($client)
        ->get('/portal')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/home', false)
            ->where('auth.user.is_client', true)
            ->where('auth.user.roles', ['client']));
});

test('un invitado recibe auth.user nulo y ningún permiso', function () {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', null)
            ->where('auth.can.viewHourBanks', false)
            ->where('auth.can.viewAdmin', false)
            ->where('auth.can.viewFinancials', false));
});

test('las secciones de la barra lateral ya son páginas reales, ninguna es el placeholder', function (string $path, string $component) {
    // Tras unir las Fases 2 a 5 con la 6, ya no queda ninguna sección pendiente: Carga, Informes y
    // Chat tienen su página.
    $this->actingAs(userWithRole('admin'))
        ->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component, false));
})->with([
    ['/carga', 'workload/index'],
    ['/informes', 'reports/index'],
    ['/chat', 'chat/index'],
]);

test('los internos reciben el temporizador activo, las notificaciones sin leer y la configuración (Fase 1)', function () {
    $employee = User::factory()->employee()->create();
    $task = Task::factory()->create(['title' => 'Maquetar home']);
    ActiveTimer::query()->create([
        'user_id' => $employee->id,
        'task_id' => $task->id,
        'started_at' => '2026-09-25 08:00:00',
    ]);

    $this->actingAs($employee)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('timer.task_id', $task->id)
            ->where('timer.task_title', 'Maquetar home')
            ->where('timer.project_code', $task->project->code)
            ->where('timer.started_at', '2026-09-25T08:00:00Z')
            ->where('notifications.unread', 0)
            ->where('config.hour_bank_thresholds', [75, 90, 100])
            ->where('config.timer_warning_hours', 10)
            ->where('config.description_required', false)
            ->where('auth.can.createProjects', false)
            ->where('auth.can.approveTime', false)
            ->where('auth.can.viewTeamAbsences', false));
});

test('sin temporizador, timer es nulo; los responsables pueden crear y aprobar', function () {
    $manager = userWithRole('department_manager');

    $this->actingAs($manager)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('timer', null)
            ->where('auth.can.createClients', true)
            ->where('auth.can.createProjects', true)
            ->where('auth.can.approveTime', true)
            ->where('auth.can.lockTime', false)
            ->where('auth.can.viewTeamAbsences', true));
});

test('el portal de cliente no recibe temporizador ni configuración interna', function () {
    $this->actingAs(User::factory()->portalOf(Client::factory()->create())->create())
        ->get('/portal')
        ->assertInertia(fn (Assert $page) => $page
            ->missing('timer')
            ->missing('config'));
});

test('los endpoints JSON y las acciones con redirección no calculan las props compartidas (PERF-03)', function () {
    $employee = User::factory()->employee()->create();
    $this->actingAs($employee)->getJson('/notificaciones/recientes')->assertOk(); // Calienta las cachés.

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($employee)->getJson('/notificaciones/recientes')->assertOk()->assertJsonPath('unread', 0);

    $unreadCounts = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'count(*)') && str_contains($sql, '"notifications"'));
    $timers = array_filter($queries, fn (string $sql): bool => str_contains($sql, '"active_timers"'));

    expect($unreadCounts)->toHaveCount(1)
        ->and($timers)->toBeEmpty();

    // Y una página Inertia sí las lleva.
    $this->actingAs($employee)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->has('timer')->where('notifications.unread', 0)->has('config')->has('auth.can'));
});
