<?php

use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\ActiveTimer;
use App\Models\DayPlanComment;
use App\Models\DayPlanItem;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Notifications\DayPlan\DayPlanCommented;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Equipo hoy y la semana (docs/PLAN-CARGAS.md §4.2, §4.3 y §8; P1 a, D-251): toda la plantilla ve los
| textos y los checks; las cifras y los comentarios, solo la persona, su responsable y los admins.
| Hoy, miércoles 07/10/2026 a las 10:00 de Madrid (pasada la hora límite de las 08:30).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->dev = Department::factory()->create(['name' => 'Desarrollo']);
    $this->manager = userWithRole('department_manager', ['name' => 'Raúl', 'department_id' => $this->design->id]);
    $this->design->managers()->attach($this->manager->id);
    $this->ana = userWithRole('employee', ['name' => 'Ana', 'department_id' => $this->design->id]);
    $this->luis = userWithRole('employee', ['name' => 'Luis', 'department_id' => $this->design->id]);
    $this->pablo = userWithRole('employee', ['name' => 'Pablo', 'department_id' => $this->dev->id]);
    $this->admin = userWithRole('admin', ['name' => 'Zoe']);

    $this->line = DayPlanItem::factory()->create(['user_id' => $this->ana->id, 'date' => '2026-10-07', 'text' => 'Banners', 'planned_minutes' => 90]);
    DayPlanItem::factory()->done()->create(['user_id' => $this->ana->id, 'date' => '2026-10-07', 'text' => 'Logo', 'planned_minutes' => 60, 'carry_count' => 1, 'position' => 1]);
    DayPlanComment::query()->create(['day_plan_item_id' => $this->line->id, 'user_id' => $this->manager->id, 'body' => '¿Para cuándo?']);
});

function teamRow(Assert $page, string $name): array
{
    $rows = $page->toArray()['props']['rows'];

    return collect($rows)->firstWhere('user.name', $name);
}

it('un empleado ve los textos y los checks de todos, sin cifras ni comentarios', function () {
    $this->actingAs($this->luis)->get('/dia/equipo')
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('day-plan/team')->where('department', $this->design->id);
            $ana = teamRow($page, 'Ana');

            expect($ana['state'])->toBe('plan')
                ->and(array_column($ana['items'], 'text'))->toBe(['Banners', 'Logo'])
                ->and(array_column($ana['items'], 'status'))->toBe(['pending', 'done'])
                ->and($ana['items'][1]['carry_count'])->toBe(1)
                ->and($ana['figures'])->toBeNull()
                ->and(array_column($ana['items'], 'planned_minutes'))->toBe([null, null])
                ->and(array_column($ana['items'], 'logged_minutes'))->toBe([null, null])
                ->and(array_column($ana['items'], 'comments'))->toBe([null, null])
                ->and($ana['can_comment'])->toBeFalse()
                ->and($ana['can_remind'])->toBeFalse()
                ->and($page->toArray()['props']['summary']['figures'])->toBeNull();

            // Las suyas sí las ve.
            expect(teamRow($page, 'Luis')['figures'])->not->toBeNull();
        });
});

it('su responsable y los admins ven las cifras, los comentarios y el temporizador', function () {
    $project = Project::factory()->withMembers([$this->ana])->create();
    $task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Banners BF']);
    TimeEntry::factory()->create(['user_id' => $this->ana->id, 'task_id' => $task->id, 'project_id' => $project->id, 'date' => '2026-10-07', 'minutes' => 40, 'day_plan_item_id' => $this->line->id]);
    ActiveTimer::query()->create(['user_id' => $this->ana->id, 'task_id' => $task->id, 'started_at' => now()->subMinutes(10), 'day_plan_item_id' => $this->line->id]);

    foreach ([$this->manager, $this->admin] as $viewer) {
        $this->actingAs($viewer)->get('/dia/equipo?departamento='.$this->design->id)
            ->assertInertia(function (Assert $page) {
                $ana = teamRow($page, 'Ana');

                expect($ana['figures']['planned_minutes'])->toBe(150)
                    ->and($ana['figures']['capacity_minutes'])->toBe(480)
                    ->and($ana['figures']['logged_minutes'])->toBe(40)
                    ->and($ana['figures']['done'])->toBe(1)
                    ->and($ana['figures']['total'])->toBe(2)
                    ->and($ana['figures']['carried'])->toBe(1)
                    ->and($ana['figures']['running']['text'])->toBe('Banners')
                    ->and($ana['items'][0]['logged_minutes'])->toBe(40)
                    ->and($ana['items'][0]['running'])->toBeTrue()
                    ->and($ana['items'][0]['comments'][0]['body'])->toBe('¿Para cuándo?')
                    ->and($ana['can_comment'])->toBeTrue();
            });
    }
});

it('un responsable no ve las cifras de otro departamento', function () {
    DayPlanItem::factory()->create(['user_id' => $this->pablo->id, 'date' => '2026-10-07', 'planned_minutes' => 120]);

    $this->actingAs($this->manager)->get('/dia/equipo?departamento=todos')
        ->assertInertia(function (Assert $page) {
            $pablo = teamRow($page, 'Pablo');

            expect($page->toArray()['props']['department'])->toBe('all')
                ->and($pablo['items'][0]['planned_minutes'])->toBeNull()
                ->and($pablo['figures'])->toBeNull()
                ->and($pablo['can_comment'])->toBeFalse()
                ->and(teamRow($page, 'Ana')['figures'])->not->toBeNull();
        });
});

it('marca «Sin plan» pasada la hora límite y nunca en un día sin jornada', function () {
    Absence::factory()->create(['user_id' => $this->pablo->id, 'type' => AbsenceType::Sick, 'status' => AbsenceStatus::Approved, 'start_date' => '2026-10-07', 'end_date' => '2026-10-07']);

    $this->actingAs($this->luis)->get('/dia/equipo?departamento=todos')
        ->assertInertia(function (Assert $page) {
            expect(teamRow($page, 'Luis')['state'])->toBe('no_plan')
                ->and(teamRow($page, 'Pablo')['state'])->toBe('away')
                // El tipo de ausencia es un dato de salud: Luis no lo ve (D-088).
                ->and(teamRow($page, 'Pablo')['reason'])->toBeNull()
                ->and($page->toArray()['props']['summary']['without_plan'])->toBeGreaterThanOrEqual(1);
        });

    $this->actingAs($this->admin)->get('/dia/equipo?departamento=todos')
        ->assertInertia(fn (Assert $page) => expect(teamRow($page, 'Pablo')['reason'])->toBe(AbsenceType::Sick->label()));

    // Antes de la hora límite, «Aún no».
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00:00', 'Europe/Madrid'));
    $this->actingAs($this->luis)->get('/dia/equipo')
        ->assertInertia(fn (Assert $page) => expect(teamRow($page, 'Luis')['state'])->toBe('not_yet'));
});

it('marca las líneas escritas pasada la hora límite', function () {
    // Las de Ana, a las 08:00 de Madrid (antes de la hora límite).
    DayPlanItem::query()->where('user_id', $this->ana->id)->update(['created_at' => '2026-10-07 06:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:40:00', 'Europe/Madrid'));
    DayPlanItem::factory()->create(['user_id' => $this->luis->id, 'date' => '2026-10-07', 'text' => 'Tarde']);

    $this->actingAs($this->ana)->get('/dia/equipo')
        ->assertInertia(function (Assert $page) {
            expect(teamRow($page, 'Luis')['items'][0]['added_late'])->toBeTrue()
                ->and(teamRow($page, 'Ana')['items'][0]['added_late'])->toBeFalse();
        });
});

it('la semana enseña el estado de cada día y las cifras solo a quien puede', function () {
    DayPlanItem::factory()->done()->create(['user_id' => $this->luis->id, 'date' => '2026-10-05']);
    DayPlanItem::factory()->create(['user_id' => $this->luis->id, 'date' => '2026-10-05', 'position' => 1]);

    $this->actingAs($this->ana)->get('/dia/semana')
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];
            $luis = collect($props['rows'])->firstWhere('user.name', 'Luis');

            expect($props['week'])->toBe('2026-W41')
                ->and($props['days'])->toBe(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'])
                ->and(array_column($luis['days'], 'state'))->toBe(['plan', 'no_plan', 'no_plan', 'future', 'future'])
                ->and($luis['days'][0]['figures'])->toBeNull()
                ->and(array_column($luis['days'][0]['items'], 'status'))->toBe(['done', 'pending'])
                ->and($luis['figures'])->toBeNull();
        });

    $this->actingAs($this->manager)->get('/dia/semana?semana=2026-W41')
        ->assertInertia(function (Assert $page) {
            $luis = collect($page->toArray()['props']['rows'])->firstWhere('user.name', 'Luis');

            expect($luis['days'][0]['figures'])->toBe(['done' => 1, 'total' => 2, 'carried' => 0])
                ->and($luis['figures'])->toBe(['done' => 1, 'total' => 2, 'days_with_plan' => 1]);
        });
});

it('comentan su responsable y los admins; avisa a la persona; un compañero no', function () {
    Notification::fake();

    $this->actingAs($this->manager)->post("/dia/lineas/{$this->line->id}/comentarios", ['body' => 'Prioriza esto'])->assertSessionHasNoErrors();
    Notification::assertSentTo($this->ana, DayPlanCommented::class, fn (DayPlanCommented $n) => $n->comment === 'Prioriza esto' && $n->author === 'Raúl');

    // La propia persona contesta (sin avisarse a sí misma).
    $this->actingAs($this->ana)->post("/dia/lineas/{$this->line->id}/comentarios", ['body' => 'Hecho'])->assertSessionHasNoErrors();
    Notification::assertSentToTimes($this->ana, DayPlanCommented::class, 1);

    $this->actingAs($this->luis)->post("/dia/lineas/{$this->line->id}/comentarios", ['body' => 'Hola'])->assertForbidden();
    $this->actingAs(userWithRole('department_manager'))->post("/dia/lineas/{$this->line->id}/comentarios", ['body' => 'De otro'])->assertForbidden();

    // Solo el autor borra su comentario.
    $comment = DayPlanComment::query()->where('body', 'Prioriza esto')->firstOrFail();
    $this->actingAs($this->ana)->delete("/dia/comentarios/{$comment->id}")->assertForbidden();
    $this->actingAs($this->manager)->delete("/dia/comentarios/{$comment->id}")->assertSessionHasNoErrors();
    expect(DayPlanComment::query()->whereKey($comment->id)->exists())->toBeFalse();
});

it('los colaboradores externos no ven el equipo', function () {
    $this->actingAs(userWithRole('collaborator'))->get('/dia/equipo')->assertForbidden();
    $this->actingAs(userWithRole('collaborator'))->get('/dia/semana')->assertForbidden();
});
