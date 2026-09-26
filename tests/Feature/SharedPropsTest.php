<?php

use App\Models\User;
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
    $client = userWithRole('client');

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

test('las secciones pendientes se sirven con la página placeholder y su sección', function (string $path, string $section) {
    $this->actingAs(userWithRole('admin'))
        ->get($path)
        ->assertInertia(fn (Assert $page) => $page
            ->component('placeholder', false)
            ->where('section', $section));
})->with([
    ['/mis-tareas', 'my-tasks'],
    ['/proyectos', 'projects'],
    ['/clientes', 'clients'],
    ['/bolsas', 'hour-banks'],
    ['/horas', 'time'],
    ['/carga', 'workload'],
    ['/informes', 'reports'],
    ['/chat', 'chat'],
]);
