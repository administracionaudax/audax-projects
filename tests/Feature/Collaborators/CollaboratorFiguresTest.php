<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Time\TimeEntryData;
use App\Domain\Time\TimeEntryWriter;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| Cifras que un colaborador externo no ve (D-134): ni el saldo de una bolsa en los errores y avisos
| al imputar, ni las horas de todos en el proyecto y sus tareas, ni el presupuesto. Y sus
| comentarios de un proyecto del que ha salido ya no los edita ni los borra.
| "Hoy" es el viernes 25/09/2026 en Madrid.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->sara = User::factory()->collaborator()->create(['name' => 'Sara Colaboradora']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana Plantilla']);

    $this->log = function (User $user, Task $task, int $minutes) {
        return app(TimeEntryWriter::class)->create($user, new TimeEntryData(
            userId: $user->id,
            taskId: $task->id,
            date: CarbonImmutable::parse('2026-09-24'),
            minutes: $minutes,
        ));
    };
    $this->bankTask = function (HourBank $bank): Task {
        $bank->project->addMember($this->sara);
        $bank->project->addMember($this->ana);

        return Task::factory()->inBank($bank)->create();
    };
});

describe('imputar en una bolsa', function () {
    it('si la bolsa no admite exceso, el error no le dice el saldo (a la plantilla, sí)', function () {
        $task = ($this->bankTask)(HourBank::factory()->hours(1)->blockOverage()->create(['name' => 'Bolsa Faro']));

        try {
            ($this->log)($this->sara, $task, 90);
            $this->fail('Debería rechazar la imputación.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['minutes'])->toBe([__('time.errors.bank_blocked_collaborator', ['bank' => 'Bolsa Faro'])])
                ->and($exception->errors()['minutes'][0])->not->toContain('1:00');
        }

        try {
            ($this->log)($this->ana, $task, 90);
            $this->fail('Debería rechazar la imputación.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['minutes'][0])->toContain('1:00');
        }
    });

    it('si parte va como exceso, el aviso no le dice cuánto (a la plantilla, sí)', function () {
        $task = ($this->bankTask)(HourBank::factory()->hours(1)->allowOverage()->create());

        $result = ($this->log)($this->sara, $task, 90);
        expect($result->warningsArray()[0]['message'])->toBe(__('time.warnings.overage_partial_collaborator'))
            ->and($result->warningsArray()[0]['message'])->not->toContain('0:30');

        TimeEntry::query()->delete();
        $result = ($this->log)($this->ana, $task, 90);
        expect($result->warningsArray()[0]['message'])->toContain('0:30');
    });
});

describe('horas de todos y presupuesto', function () {
    beforeEach(function () {
        $this->project = Project::factory()->withMembers([$this->sara, $this->ana])->create(['budget_minutes' => 600]);
        $this->task = Task::factory()->create(['project_id' => $this->project->id]);
        $this->subtask = Task::factory()->create(['project_id' => $this->project->id, 'parent_task_id' => $this->task->id]);
        TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->minutes(120)->create(['user_id' => $this->ana->id]);
        TimeEntry::factory()->forTask($this->subtask)->on('2026-09-24')->minutes(30)->create(['user_id' => $this->ana->id]);
        TimeEntry::factory()->forTask($this->task)->on('2026-09-23')->minutes(45)->create(['user_id' => $this->sara->id]);

        $this->props = fn (User $user, string $uri): array => $this->actingAs($user)->get($uri)->assertOk()->viewData('page')['props'];
    });

    it('el resumen del proyecto no le enseña las horas reales ni el presupuesto', function () {
        $sara = ($this->props)($this->sara, "/proyectos/{$this->project->id}");
        $ana = ($this->props)($this->ana, "/proyectos/{$this->project->id}");

        expect($sara['summary']['logged_minutes'])->toBeNull()
            ->and($sara['summary']['budget_minutes'])->toBeNull()
            ->and($sara['project']['budget_minutes'])->toBeNull()
            ->and($sara['summary']['total_tasks'])->toBe(2)
            ->and($ana['summary']['logged_minutes'])->toBe(195)
            ->and($ana['summary']['budget_minutes'])->toBe(600);
    });

    it('la lista de tareas, el panel y el Gantt del proyecto no le enseñan las horas de todos', function () {
        $list = ($this->props)($this->sara, "/proyectos/{$this->project->id}/tareas");
        $row = collect($list['tasks'])->firstWhere('id', $this->task->id);
        expect($row['logged_minutes'])->toBeNull()
            ->and($row['subtasks'][0]['logged_minutes'])->toBeNull();

        $panel = ($this->props)($this->sara, "/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}")['panel'];
        expect($panel['task']['logged_minutes'])->toBeNull()
            ->and($panel['subtasks'][0]['logged_minutes'])->toBeNull()
            // Las suyas, sí.
            ->and($panel['time_visible_minutes'])->toBe(45);

        $gantt = ($this->props)($this->sara, "/proyectos/{$this->project->id}/gantt");
        expect(collect($gantt['tasks'])->pluck('logged_minutes')->unique()->all())->toBe([null]);

        // La plantilla las ve.
        $row = collect(($this->props)($this->ana, "/proyectos/{$this->project->id}/tareas")['tasks'])->firstWhere('id', $this->task->id);
        expect($row['logged_minutes'])->toBe(165);
        expect(collect(($this->props)($this->ana, "/proyectos/{$this->project->id}/gantt")['tasks'])->firstWhere('id', $this->task->id)['logged_minutes'])->toBe(195);
    });
});
