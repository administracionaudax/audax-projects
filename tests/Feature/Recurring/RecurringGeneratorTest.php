<?php

use App\Domain\Recurring\RecurringTaskGenerator;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/*
| Añadidos de la Fase 4 (G3) a RecurringTaskGenerator (D-059): el día de hoy de Madrid cuenta
| (LocalTime::today() es la medianoche de Madrid, que en UTC es el día anterior), generateFor() para
| la generación inmediata y responsables de baja sin perder la tarea.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->admin = User::factory()->admin()->create();
    $this->project = Project::factory()->create();
    $this->rule = RecurringTaskRule::query()->create([
        'project_id' => $this->project->id, 'title' => 'Revisión', 'frequency' => 'weekly', 'weekday' => 1,
        'starts_on' => '2026-09-28', 'last_generated_on' => '2026-10-04', 'created_by' => $this->admin->id,
    ]);
    $this->generator = app(RecurringTaskGenerator::class);
});

it('el comando de las 06:00 de Madrid crea la tarea de ese día', function (string $utc) {
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

    $this->artisan('tasks:generate-recurring')->assertSuccessful();

    expect(Task::query()->where('recurring_task_rule_id', $this->rule->id)->sole()->occurrence_date->toDateString())->toBe('2026-10-05')
        ->and($this->rule->fresh()->last_generated_on->toDateString())->toBe('2026-10-05');
})->with([
    'a las 06:00 de Madrid (04:00 UTC)' => ['2026-10-05 04:00:00'],
    'justo después de medianoche en Madrid (22:30 UTC del domingo)' => ['2026-10-04 22:30:00'],
]);

it('generateFor también toma hoy de Madrid', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 22:30:00', 'UTC'));
    $this->rule->forceFill(['last_generated_on' => null])->save();

    $task = $this->generator->generateFor($this->rule->fresh(), LocalTime::today());

    expect($task?->occurrence_date->toDateString())->toBe('2026-10-05')
        ->and($this->rule->fresh()->last_generated_on->toDateString())->toBe('2026-10-05');
});

it('generateFor no crea nada si hoy no toca o la regla está desactivada', function () {
    expect($this->generator->generateFor($this->rule, CarbonImmutable::parse('2026-10-06')))->toBeNull();

    $this->rule->forceFill(['is_active' => false])->save();
    expect($this->generator->generateFor($this->rule->fresh(), CarbonImmutable::parse('2026-10-12')))->toBeNull()
        ->and(Task::query()->count())->toBe(0);
});

it('al editar una regla activa no toca lo que el comando tenga pendiente', function () {
    $this->rule->forceFill(['last_generated_on' => '2026-09-27'])->save();

    $this->generator->generateFor($this->rule->fresh(), CarbonImmutable::parse('2026-10-05'), startFromToday: false);

    expect(Task::query()->count())->toBe(1)
        ->and($this->rule->fresh()->last_generated_on->toDateString())->toBe('2026-09-27')
        // El comando recupera el lunes anterior y no duplica el de hoy.
        ->and($this->generator->generate(CarbonImmutable::parse('2026-10-05')))->toBe(1)
        ->and(Task::query()->pluck('occurrence_date')->map->toDateString()->sort()->values()->all())->toBe(['2026-09-28', '2026-10-05']);
});

it('si quien creó la regla está de baja, la crea el primer admin activo', function () {
    $creator = User::factory()->employee()->inactive()->create();
    $this->rule->forceFill(['created_by' => $creator->id])->save();

    $this->generator->generate(CarbonImmutable::parse('2026-10-05'));

    expect(Task::query()->sole()->created_by)->toBe($this->admin->id);
});
