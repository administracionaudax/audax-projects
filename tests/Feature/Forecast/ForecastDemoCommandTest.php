<?php

use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\TimeEntry;
use Database\Seeders\DefaultSettingsSeeder;

/*
| app:forecast-demo (D-262): datos de ejemplo de la Previsión con la gente y los proyectos reales,
| todo marcado con «[Ejemplo]», sin duplicar y borrables enteros con --borrar.
*/

beforeEach(function () {
    $this->seed(DefaultSettingsSeeder::class);
    userWithRole('admin');
    $design = Department::factory()->create(['name' => 'Diseño']);
    $dev = Department::factory()->create(['name' => 'Desarrollo']);
    foreach ([$design, $design, $dev] as $department) {
        userWithRole('employee', ['department_id' => $department->id]);
    }
    foreach (Project::factory()->count(2)->create() as $project) {
        TimeEntry::factory()->create(['project_id' => $project->id, 'date' => now()->subDays(3)->toDateString()]);
    }
});

it('crea previstos y asignaciones marcados, no duplica y los borra todos', function () {
    $this->artisan('app:forecast-demo')->assertSuccessful();

    expect(ForecastProject::query()->where('name', 'like', '[Ejemplo]%')->count())->toBe(3)
        ->and(Allocation::query()->where('note', 'like', '[Ejemplo]%')->count())->toBeGreaterThan(5)
        ->and(ForecastProject::query()->where('name', 'not like', '[Ejemplo]%')->count())->toBe(0);

    $this->artisan('app:forecast-demo')->assertFailed();

    $this->artisan('app:forecast-demo', ['--borrar' => true])->assertSuccessful();

    expect(ForecastProject::withTrashed()->count())->toBe(0)
        ->and(Allocation::withTrashed()->count())->toBe(0);
});
