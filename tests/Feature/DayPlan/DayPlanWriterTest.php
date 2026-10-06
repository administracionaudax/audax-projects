<?php

use App\Domain\DayPlan\DayPlanCalendar;
use App\Domain\DayPlan\DayPlanWriter;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\DayPlanItemOrigin;
use App\Enums\DayPlanItemStatus;
use App\Models\Absence;
use App\Models\Client;
use App\Models\DayPlan;
use App\Models\DayPlanItem;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
| Plan del día, C1 (docs/PLAN-CARGAS.md §4.4 y §6.1, D-250 y D-253): DayPlanWriter es el único punto de
| escritura. Hoy, miércoles 07/10/2026 a las 10:00 de Madrid; jornada por defecto de lunes a viernes.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->me = userWithRole('employee');
    $this->writer = app(DayPlanWriter::class);
});

it('añade líneas al final, fija la hora del plan con la primera y respeta «después de»', function () {
    $first = $this->writer->add($this->me, '2026-10-07', ['text' => '  Creatividades   campaña otoño ']);
    $this->travel(5)->minutes();
    $second = $this->writer->add($this->me, '2026-10-07', ['text' => 'JS del configurador', 'planned_minutes' => 90]);
    $between = $this->writer->add($this->me, '2026-10-07', ['text' => 'Reunión'], $first->id);

    $plan = DayPlan::query()->where('user_id', $this->me->id)->firstOrFail();

    expect($first->text)->toBe('Creatividades campaña otoño')
        ->and($first->origin)->toBe(DayPlanItemOrigin::Manual)
        ->and($plan->published_at?->toIso8601ZuluString())->toBe('2026-10-07T08:00:00Z')
        ->and(DayPlanItem::query()->orderBy('position')->pluck('id')->all())->toBe([$first->id, $between->id, $second->id])
        ->and($second->planned_minutes)->toBe(90);
});

it('una tarea fija su proyecto y su cliente; un proyecto, su cliente', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $byTask = $this->writer->add($this->me, '2026-10-07', ['text' => 'Con tarea', 'task_id' => $task->id, 'client_id' => Client::factory()->create()->id]);
    $byProject = $this->writer->add($this->me, '2026-10-07', ['text' => 'Con proyecto', 'project_id' => $project->id]);
    $general = $this->writer->add($this->me, '2026-10-07', ['text' => 'General']);

    expect([$byTask->client_id, $byTask->project_id, $byTask->task_id])->toBe([$client->id, $project->id, $task->id])
        ->and([$byProject->client_id, $byProject->project_id, $byProject->task_id])->toBe([$client->id, $project->id, null])
        ->and([$general->client_id, $general->project_id, $general->task_id])->toBe([null, null, null]);

    // Cambiar a un proyecto de otro cliente quita la tarea del proyecto anterior.
    $other = Project::factory()->create();
    $this->writer->update($this->me, $byTask, ['project_id' => $other->id]);
    expect([$byTask->fresh()->project_id, $byTask->fresh()->task_id, $byTask->fresh()->client_id])->toBe([$other->id, null, $other->client_id]);
});

it('valida el texto, las horas y lo que se puede escribir', function (array $data, string $date, string $field) {
    expect(fn () => $this->writer->add($this->me, $date, $data))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
})->with([
    'sin texto' => [['text' => '   '], '2026-10-07', 'text'],
    'texto largo' => [['text' => str_repeat('a', 201)], '2026-10-07', 'text'],
    'horas de más' => [['text' => 'x', 'planned_minutes' => 1441], '2026-10-07', 'planned_minutes'],
    'ayer (no se reescribe el plan)' => [['text' => 'x'], '2026-10-06', 'date'],
    'más allá de la semana que viene' => [['text' => 'x'], '2026-10-19', 'date'],
    'tarea inexistente' => [['text' => 'x', 'task_id' => 999], '2026-10-07', 'task_id'],
]);

it('planifica con antelación hasta el domingo de la semana que viene', function () {
    expect(DayPlanCalendar::horizonEnd()->toDateString())->toBe('2026-10-18');

    $item = $this->writer->add($this->me, '2026-10-18', ['text' => 'Preparar la presentación']);

    expect($item->date->toDateString())->toBe('2026-10-18');
});

it('un colaborador externo no puede poner en una línea un proyecto del que no es miembro', function () {
    $collaborator = userWithRole('collaborator');
    $project = Project::factory()->create();

    expect(fn () => $this->writer->add($collaborator, '2026-10-07', ['text' => 'x', 'project_id' => $project->id]))
        ->toThrow(ValidationException::class);
});

it('cierra líneas de hoy y del último día con jornada; las anteriores son de solo lectura', function () {
    $today = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07']);
    $yesterday = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-06']);
    $old = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-05']);

    $this->writer->setStatus($this->me, $today, DayPlanItemStatus::Done);
    $this->writer->setStatus($this->me, $yesterday, DayPlanItemStatus::NotDone, '  Sin respuesta del cliente ');

    expect($today->fresh()->status)->toBe(DayPlanItemStatus::Done)
        ->and($today->fresh()->status_changed_at)->not->toBeNull()
        ->and($yesterday->fresh()->not_done_reason)->toBe('Sin respuesta del cliente')
        ->and(fn () => $this->writer->setStatus($this->me, $old, DayPlanItemStatus::Done))->toThrow(ValidationException::class);

    // Ni texto ni borrar en un día pasado.
    expect(fn () => $this->writer->update($this->me, $yesterday, ['text' => 'otra cosa']))->toThrow(ValidationException::class)
        ->and(fn () => $this->writer->delete($this->me, $yesterday))->toThrow(ValidationException::class);
});

it('el lunes aún cierra el viernes: los fines de semana, festivos y ausencias no cuentan', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'Europe/Madrid'));
    $friday = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-09']);
    $thursday = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-08']);

    expect(app(DayPlanCalendar::class)->closableFrom($this->me)->toDateString())->toBe('2026-10-09');

    $this->writer->setStatus($this->me, $friday, DayPlanItemStatus::Done);
    expect(fn () => $this->writer->setStatus($this->me, $thursday, DayPlanItemStatus::Done))->toThrow(ValidationException::class);

    // Con el viernes de vacaciones (aprobadas), el último día con jornada es el jueves.
    Absence::factory()->create(['user_id' => $this->me->id, 'type' => AbsenceType::Vacation, 'status' => AbsenceStatus::Approved, 'start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'partial_minutes' => null]);
    expect(app(DayPlanCalendar::class)->closableFrom($this->me)->toDateString())->toBe('2026-10-08');

    // Con dos días de margen en los ajustes, también el miércoles.
    Setting::set('day_plan_editable_days', 2);
    expect(app(DayPlanCalendar::class)->closableFrom($this->me)->toDateString())->toBe('2026-10-07');
});

it('pasar una línea crea la copia en el destino con «↻ ×N» y deja la original como pasada', function () {
    $original = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-06', 'text' => 'JS del configurador', 'planned_minutes' => 180]);

    $copy = $this->writer->carry($this->me, $original, '2026-10-07');
    $again = $this->writer->carry($this->me, $copy, '2026-10-08');

    expect($original->fresh()->status)->toBe(DayPlanItemStatus::Carried)
        ->and($copy->fresh()->status)->toBe(DayPlanItemStatus::Carried)
        ->and($again->carry_count)->toBe(2)
        ->and($again->carried_from_id)->toBe($copy->id)
        ->and($again->origin)->toBe(DayPlanItemOrigin::Carried)
        ->and($again->planned_minutes)->toBe(180)
        ->and($again->text)->toBe('JS del configurador');

    // Una pasada ya no se cambia; una hecha no se pasa.
    expect(fn () => $this->writer->setStatus($this->me, $original->fresh(), DayPlanItemStatus::Done))->toThrow(ValidationException::class);
    $done = DayPlanItem::factory()->done()->create(['user_id' => $this->me->id, 'date' => '2026-10-07']);
    expect(fn () => $this->writer->carry($this->me, $done, '2026-10-08'))->toThrow(ValidationException::class);
});

it('«Pasar a hoy» todas las pendientes de un clic y borrar la copia lo deshace', function () {
    $a = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-06', 'position' => 0]);
    $b = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-06', 'position' => 1]);

    $copies = $this->writer->carryMany($this->me, [$b->id, $a->id], '2026-10-07');

    expect(array_map(fn (DayPlanItem $copy): int => (int) $copy->carried_from_id, $copies))->toBe([$a->id, $b->id])
        ->and(DayPlanItem::query()->where('date', '2026-10-07')->count())->toBe(2);

    $this->writer->delete($this->me, $copies[0]);
    expect($a->fresh()->status)->toBe(DayPlanItemStatus::Pending);
});

it('nadie cambia las líneas de otro, tampoco un admin', function () {
    $item = DayPlanItem::factory()->create(['date' => '2026-10-07']);
    $admin = userWithRole('admin');

    expect(fn () => $this->writer->setStatus($admin, $item, DayPlanItemStatus::Done))->toThrow(ValidationException::class)
        ->and(fn () => $this->writer->carryMany($this->me, [$item->id], '2026-10-08'))->toThrow(ValidationException::class);
});

it('reordena las líneas de un día', function () {
    $items = DayPlanItem::factory()->count(3)->sequence(fn ($sequence) => ['position' => $sequence->index])
        ->create(['user_id' => $this->me->id, 'date' => '2026-10-07']);

    $this->writer->reorder($this->me, '2026-10-07', [$items[2]->id, $items[0]->id]);

    expect(DayPlanItem::query()->orderBy('position')->pluck('id')->all())->toBe([$items[2]->id, $items[0]->id, $items[1]->id]);
});
