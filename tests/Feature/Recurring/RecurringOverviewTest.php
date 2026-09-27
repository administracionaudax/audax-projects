<?php

use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Vista global de las tareas recurrentes en /admin/tareas-recurrentes (D-059): todas las reglas con
| su proyecto, frase, próxima fecha y avisos, filtros por estado y proyecto. Solo admin.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
    $this->alpha = Project::factory()->create(['name' => 'Alfa', 'code' => 'ALFA']);
    $this->beta = Project::factory()->create(['name' => 'Beta', 'code' => 'BETA']);
    $this->make = fn (Project $project, string $title, array $attributes = []) => RecurringTaskRule::query()->create([
        'project_id' => $project->id, 'title' => $title, 'frequency' => 'weekly', 'weekday' => 3,
        'starts_on' => '2026-09-01', ...$attributes,
    ]);
    ($this->make)($this->alpha, 'Informe');
    ($this->make)($this->alpha, 'Parada', ['is_active' => false]);
    ($this->make)($this->beta, 'Mensual', ['frequency' => 'monthly', 'weekday' => null, 'month_day' => 31]);
    $this->actingAs($this->admin);
});

it('lista las reglas activas de todos los proyectos con su frase y próxima fecha', function () {
    $this->get('/admin/tareas-recurrentes')->assertInertia(fn (Assert $page) => $page
        ->component('admin/recurring/index')
        ->has('rules.data', 2)
        ->where('rules.data.0.title', 'Informe')
        ->where('rules.data.0.project', ['id' => $this->alpha->id, 'name' => 'Alfa', 'code' => 'ALFA', 'archived' => false])
        ->where('rules.data.0.summary', 'Cada semana, los miércoles')
        ->where('rules.data.0.next_date', '2026-10-07')
        ->where('rules.data.1.title', 'Mensual')
        ->where('rules.data.1.summary', 'Cada mes, el día 31 (o el último)')
        ->where('rules.data.1.next_date', '2026-10-31')
        ->where('filters', ['estado' => 'activas', 'proyecto' => null])
        ->where('projects', [
            ['id' => $this->alpha->id, 'name' => 'Alfa', 'code' => 'ALFA'],
            ['id' => $this->beta->id, 'name' => 'Beta', 'code' => 'BETA'],
        ]));
});

it('filtra por estado y por proyecto', function () {
    $this->get('/admin/tareas-recurrentes?estado=inactivas')->assertInertia(fn (Assert $page) => $page
        ->has('rules.data', 1)
        ->where('rules.data.0.title', 'Parada')
        ->where('rules.data.0.next_date', null));

    $this->get('/admin/tareas-recurrentes?estado=todas')->assertInertia(fn (Assert $page) => $page->has('rules.data', 3));

    $this->get("/admin/tareas-recurrentes?estado=todas&proyecto={$this->beta->id}")->assertInertia(fn (Assert $page) => $page
        ->has('rules.data', 1)
        ->where('rules.data.0.title', 'Mensual')
        ->where('filters.proyecto', $this->beta->id));

    $this->get('/admin/tareas-recurrentes?estado=raras')->assertSessionHasErrors('estado');
});

it('no enseña las reglas de proyectos borrados y marca las de los archivados', function () {
    $archived = Project::factory()->archived()->create(['name' => 'Archivado']);
    ($this->make)($archived, 'Olvidada');
    $deleted = Project::factory()->create(['name' => 'Borrado']);
    ($this->make)($deleted, 'Fantasma');
    $deleted->delete();

    $this->get('/admin/tareas-recurrentes')->assertInertia(fn (Assert $page) => $page
        ->has('rules.data', 3)
        ->where('rules.data.1.title', 'Olvidada')
        ->where('rules.data.1.project.archived', true)
        ->where('rules.data.1.next_date', null)
        ->where('rules.data.1.warnings', ['El proyecto está archivado: no se crean tareas.']));
});
