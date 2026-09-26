<?php

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('view-hour-banks: admin y responsables sí; empleados y clientes no', function (string $role, bool $allowed) {
    $user = userWithRole($role);

    expect(Gate::forUser($user)->allows('view-hour-banks'))->toBe($allowed);
})->with([
    'admin' => ['admin', true],
    'responsable' => ['department_manager', true],
    'empleado' => ['employee', false],
    'cliente' => ['client', false],
]);

test('view-financials: solo con el permiso, que el admin tiene por defecto', function (string $role, bool $allowed) {
    $user = userWithRole($role);

    expect(Gate::forUser($user)->allows('view-financials'))->toBe($allowed);
})->with([
    'admin' => ['admin', true],
    'responsable' => ['department_manager', false],
    'empleado' => ['employee', false],
    'cliente' => ['client', false],
]);

test('view-financials se puede conceder expresamente a un responsable', function () {
    $manager = userWithRole('department_manager');

    $manager->givePermissionTo(Permission::ViewFinancials->value);

    expect(Gate::forUser($manager->fresh())->allows('view-financials'))->toBeTrue();
});

test('un admin desactivado pierde los permisos', function () {
    $admin = userWithRole('admin', ['is_active' => false]);

    expect(Gate::forUser($admin)->allows('view-financials'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view-hour-banks'))->toBeFalse();
});

test('el coste por hora y la tarifa no se serializan por defecto', function () {
    $user = userWithRole('employee', ['hourly_cost' => '25.50', 'default_hourly_rate' => '60.00']);

    $array = User::query()->findOrFail($user->id)->toArray();

    expect($array)->not->toHaveKey('hourly_cost')
        ->and($array)->not->toHaveKey('default_hourly_rate')
        ->and($array)->not->toHaveKey('password')
        ->and(json_encode($user))->not->toContain('25.50');
});

test('los datos económicos solo se muestran a quien tiene view-financials', function () {
    $employee = userWithRole('employee', ['hourly_cost' => '25.50']);
    $admin = userWithRole('admin');
    $manager = userWithRole('department_manager');

    expect(User::query()->findOrFail($employee->id)->revealFinancialsTo($manager)->toArray())->not->toHaveKey('hourly_cost')
        ->and(User::query()->findOrFail($employee->id)->revealFinancialsTo(null)->toArray())->not->toHaveKey('hourly_cost')
        ->and(User::query()->findOrFail($employee->id)->revealFinancialsTo($admin)->toArray())->toHaveKey('hourly_cost', '25.50');
});

test('las props compartidas no llevan datos económicos del usuario', function () {
    $admin = userWithRole('admin', ['hourly_cost' => '99.99']);

    $this->actingAs($admin)->get('/')->assertOk()->assertDontSee('99.99');
});
