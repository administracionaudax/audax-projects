<?php

use App\Domain\Weeklies\WeeklyStreaks;
use App\Domain\Weeklies\WeeklyTeamStatus;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Estado del equipo, rachas, «Mis weeklies», «Mi weekly», la tarjeta de Inicio, el perfil y el
| contador de la barra lateral (10.2; F-003, F-028, F-030 a F-036, F-039, F-042 a F-048, F-065 a
| F-067 y F-099). Hoy es el miércoles 07/10/2026; la semana activa, la del 05/10 (plazo el 09/10).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->old = ['created_at' => '2026-08-01 08:00:00'];
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
});

function submitWeekly(WeeklyCycle $cycle, User $user, string $at): WeeklySubmission
{
    return WeeklySubmission::factory()->create([
        'weekly_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'submitted_at' => CarbonImmutable::parse($at, 'Europe/Madrid')->utc(),
    ]);
}

// --- Estado del equipo (F-036, F-039 y F-067) --------------------------------------------------

it('el estado del equipo: enviadas, pendientes y exentas, en ese orden y con sus recuentos', function () {
    $ana = userWithRole('employee', [...$this->old, 'name' => 'Ana']);
    $bea = userWithRole('employee', [...$this->old, 'name' => 'Bea']);
    $carla = userWithRole('admin', [...$this->old, 'name' => 'Carla']);
    $dani = userWithRole('department_manager', [...$this->old, 'name' => 'Dani']);
    User::factory()->collaborator()->create([...$this->old, 'name' => 'Externo']);
    submitWeekly($this->cycle, $bea, '2026-10-06 18:00');
    WeeklySubmission::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $carla->id]);
    $manual = WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $dani->id]);

    $team = app(WeeklyTeamStatus::class)->for($this->cycle);

    expect(array_map(fn (array $m): string => $m['user']['name'], $team['members']))->toBe(['Bea', 'Ana', 'Carla', 'Dani'])
        ->and(array_column($team['members'], 'status'))->toBe(['submitted', 'upcoming', 'upcoming', 'exempt'])
        ->and($team['members'][3]['exemption_reason'])->toBe('manual')
        ->and($team['members'][3]['exemption_id'])->toBe($manual->id)
        ->and($team['counts'])->toBe(['submitted' => 1, 'expected' => 3, 'exempt' => 1, 'pending' => 2]);
});

it('quien envió y después se desactivó sigue saliendo; las ausencias no muestran el tipo', function () {
    $gone = userWithRole('employee', [...$this->old, 'name' => 'Baja']);
    submitWeekly($this->cycle, $gone, '2026-10-06 09:00');
    $gone->forceFill(['is_active' => false])->save();
    $sick = userWithRole('employee', [...$this->old, 'name' => 'Enferma']);
    Absence::factory()->approved()->between('2026-10-05', '2026-10-16')->create(['user_id' => $sick->id]);

    $team = app(WeeklyTeamStatus::class)->for($this->cycle);
    $byName = collect($team['members'])->keyBy(fn (array $m) => $m['user']['name']);

    expect($byName['Baja']['status'])->toBe('submitted')
        ->and($byName['Enferma']['status'])->toBe('exempt')
        ->and($byName['Enferma']['exemption_reason'])->toBe('absence')
        ->and($byName['Enferma']['exemption_id'])->toBeNull()
        ->and($byName['Enferma'])->not->toHaveKey('absence_type');
});

it('pasado el plazo, quien falta sale con retraso y quien envió tarde, enviada con retraso', function () {
    $late = userWithRole('employee', [...$this->old, 'name' => 'Tarde']);
    $missing = userWithRole('employee', [...$this->old, 'name' => 'Falta']);
    submitWeekly($this->cycle, $late, '2026-10-10 09:00');
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'Europe/Madrid'));

    $byName = collect(app(WeeklyTeamStatus::class)->for($this->cycle)['members'])->keyBy(fn (array $m) => $m['user']['name']);

    expect($byName['Tarde']['status'])->toBe('submitted_late')
        ->and($byName['Falta']['status'])->toBe('overdue');
});

// --- Rachas (F-031 y F-099) --------------------------------------------------------------------

it('la racha cuenta semanas seguidas a tiempo; la exenta no la rompe y la activa tampoco antes del plazo', function () {
    $me = userWithRole('employee', $this->old);
    $w38 = WeeklyCycle::factory()->forWeekOf('2026-09-14')->create(['expected_user_ids' => [$me->id]]);
    $w39 = WeeklyCycle::factory()->forWeekOf('2026-09-21')->create(['expected_user_ids' => []]);
    $w40 = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create(['expected_user_ids' => [$me->id]]);
    submitWeekly($w38, $me, '2026-09-18 12:00');
    WeeklyExemption::factory()->absence()->create(['weekly_cycle_id' => $w39->id, 'user_id' => $me->id]);
    submitWeekly($w40, $me, '2026-10-02 23:30');

    expect(app(WeeklyStreaks::class)->summary($me))->toBe(['submitted' => 2, 'on_time' => 2, 'streak' => 2]);

    $this->travelTo(CarbonImmutable::parse('2026-10-10 00:30:00', 'Europe/Madrid'));

    expect(app(WeeklyStreaks::class)->summary($me)['streak'])->toBe(0);
});

it('un envío con retraso corta la racha pero cuenta como enviado', function () {
    $me = userWithRole('employee', $this->old);
    $w39 = WeeklyCycle::factory()->forWeekOf('2026-09-21')->create(['expected_user_ids' => [$me->id]]);
    $w40 = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create(['expected_user_ids' => [$me->id]]);
    submitWeekly($w39, $me, '2026-09-25 10:00');
    submitWeekly($w40, $me, '2026-10-03 10:00');
    submitWeekly($this->cycle, $me, '2026-10-06 10:00');

    expect(app(WeeklyStreaks::class)->summary($me))->toBe(['submitted' => 3, 'on_time' => 2, 'streak' => 1]);
});

it('las semanas anteriores al alta no cuentan', function () {
    $me = userWithRole('employee', ['created_at' => '2026-10-01 08:00:00']);
    WeeklyCycle::factory()->forWeekOf('2026-09-21')->create();
    $w40 = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create(['expected_user_ids' => [$me->id]]);
    submitWeekly($w40, $me, '2026-10-02 10:00');

    expect(app(WeeklyStreaks::class)->summary($me))->toBe(['submitted' => 1, 'on_time' => 1, 'streak' => 1]);
});

// --- Mi espacio: mis weeklies y mi weekly (F-041 a F-048) --------------------------------------

it('«Mis weeklies» lista mis semanas con su estado, sin las anteriores a mi alta', function () {
    $me = userWithRole('employee', ['created_at' => '2026-09-25 08:00:00']);
    WeeklyCycle::factory()->forWeekOf('2026-09-14')->create();
    $w40 = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create(['expected_user_ids' => [$me->id]]);
    $w39 = WeeklyCycle::factory()->forWeekOf('2026-09-21')->create(['expected_user_ids' => []]);
    WeeklyExemption::factory()->absence()->create(['weekly_cycle_id' => $w39->id, 'user_id' => $me->id]);
    $draft = WeeklySubmission::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $me->id]);
    WeeklyEntry::factory()->general()->create(['weekly_submission_id' => $draft->id]);

    $this->actingAs($me)->get('/mi-espacio')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('my-space/index')
        ->where('tab', 'reportes')
        ->has('weeks', 3)
        ->where('weeks.0.cycle.id', $this->cycle->id)
        ->where('weeks.0.status', 'upcoming')
        ->where('weeks.0.is_upcoming', true)
        ->where('weeks.0.has_draft', true)
        ->where('weeks.0.entries_count', 1)
        ->where('weeks.1.cycle.id', $w40->id)
        ->where('weeks.1.status', 'missed')
        ->where('weeks.2.cycle.id', $w39->id)
        ->where('weeks.2.status', 'exempt')
        ->where('weeks.2.exemption_reason', 'absence')
        ->where('streak.streak', 0)
        ->where('editor', null)
        ->where('weeklies.pending', 1));
});

it('«Mi weekly» propone los clientes de mis proyectos y de mis horas de la semana (D-150)', function () {
    $me = userWithRole('employee', $this->old);
    $member = Client::factory()->create(['name' => 'Miembro']);
    $logged = Client::factory()->create(['name' => 'Horas']);
    $other = Client::factory()->create(['name' => 'Otro']);
    $inactive = Client::factory()->create(['name' => 'Inactivo', 'is_active' => false]);
    $memberProject = Project::factory()->withMembers([$me])->create(['client_id' => $member->id, 'code' => 'BH01']);
    $loggedProject = Project::factory()->create(['client_id' => $logged->id]);
    Project::factory()->create(['client_id' => $other->id]);
    $task = Task::factory()->create(['project_id' => $loggedProject->id, 'title' => 'Maquetar home']);
    TimeEntry::factory()->forTask($task)->on('2026-10-06')->minutes(90)->create(['user_id' => $me->id]);
    TimeEntry::factory()->forTask($task)->on('2026-09-30')->minutes(60)->create(['user_id' => $me->id]);

    $this->actingAs($me)->get("/mi-espacio?semana={$this->cycle->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('editor.cycle.id', $this->cycle->id)
        ->where('editor.me.status', 'upcoming')
        ->where('editor.me.must_submit', true)
        ->where('editor.read_only', false)
        ->where('editor.can.write', true)
        ->where('editor.clients.proposed', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$member->id, $logged->id])->sort()->values()->all())
        ->where('editor.clients.catalog', fn ($catalog) => collect($catalog)->pluck('name')->all() === ['Horas', 'Miembro', 'Otro'])
        ->where('editor.clients.catalog.1.projects.0.id', $memberProject->id)
        ->where('editor.clients.catalog.1.projects.0.is_mine', true)
        ->where("editor.autofill.{$logged->id}", __('weeklies.autofill.pending', ['task' => 'Maquetar home']).' '.__('weeklies.autofill.time', ['time' => '1 h 30 min'])));

    expect($inactive->id)->toBeInt();
});

it('«Mi weekly» de una semana cerrada es de solo lectura; otra semana inexistente da 404', function () {
    $me = userWithRole('employee', $this->old);
    $closed = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create(['expected_user_ids' => [$me->id]]);
    $sent = submitWeekly($closed, $me, '2026-10-01 10:00');
    WeeklyEntry::factory()->general()->create(['weekly_submission_id' => $sent->id, 'body' => 'Lo de la semana pasada']);

    $this->actingAs($me)->get("/mi-espacio?semana={$closed->id}")->assertInertia(fn (Assert $page) => $page
        ->where('editor.read_only', true)
        ->where('editor.can.write', false)
        ->where('editor.me.status', 'submitted')
        ->where('editor.submission.entries.0.body', 'Lo de la semana pasada')
        ->where('editor.autofill', []));

    $this->actingAs($me)->get('/mi-espacio?semana=999999')->assertNotFound();
});

it('exento por ausencia: solo lectura, con la opción de renunciar (F-053 y F-054)', function () {
    $me = userWithRole('employee', $this->old);
    Absence::factory()->approved()->between('2026-10-05', '2026-10-09')->create(['user_id' => $me->id]);

    $this->actingAs($me)->get("/mi-espacio?semana={$this->cycle->id}")->assertInertia(fn (Assert $page) => $page
        ->where('editor.me.status', 'exempt')
        ->where('editor.me.exemption_reason', 'absence')
        ->where('editor.read_only', true)
        ->where('editor.can.write', false)
        ->where('editor.can.waive', true)
        ->where('editor.can.undo_waiver', false)
        ->where('weeklies.pending', 0));
});

// --- Inicio, perfil y barra lateral ------------------------------------------------------------

it('la tarjeta de Inicio: mi weekly y mi racha; quien gestiona ve además el equipo y quién falta', function () {
    $me = userWithRole('employee', [...$this->old, 'name' => 'Yo']);
    $manager = userWithRole('department_manager', [...$this->old, 'name' => 'Jefa']);

    $this->actingAs($me)->get('/')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
        ->where('weekly.cycle.id', $this->cycle->id)
        ->where('weekly.me.status', 'upcoming')
        ->where('weekly.streak.streak', 0)
        ->where('weekly.team', null)
        ->where('weekly.can.manage', false)));

    $this->actingAs($manager)->get('/')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
        ->where('weekly.can.manage', true)
        ->where('weekly.team.counts.expected', 2)
        ->where('weekly.team.pending', fn ($pending) => collect($pending)->pluck('name')->sort()->values()->all() === ['Jefa', 'Yo'])));
});

it('sin semana activa, la tarjeta ofrece «Iniciar la semana» a quien gestiona', function () {
    $this->cycle->delete();

    $this->actingAs(userWithRole('admin', $this->old))->get('/')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
        ->where('weekly.cycle', null)
        ->where('weekly.me', null)
        ->where('weekly.can.open', true)));
});

it('un colaborador externo o el módulo apagado: sin tarjeta, sin contador y sin estadísticas', function () {
    $collaborator = User::factory()->collaborator()->create($this->old);

    $this->actingAs($collaborator)->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('weeklies.pending', 0)
        ->loadDeferredProps(fn (Assert $reload) => $reload->missing('weekly')));

    Setting::set('modules', ['weeklies' => false]);
    $me = userWithRole('employee', $this->old);

    $this->actingAs($me)->get('/ajustes/perfil')->assertInertia(fn (Assert $page) => $page
        ->where('weeklyStats', null)
        ->where('weeklies.pending', 0));
});

it('el perfil enseña mis estadísticas de envío (F-028) y el puesto se guarda (F-026)', function () {
    $me = userWithRole('employee', $this->old);
    $w40 = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create(['expected_user_ids' => [$me->id]]);
    submitWeekly($w40, $me, '2026-10-02 10:00');

    $this->actingAs($me)->get('/ajustes/perfil')->assertInertia(fn (Assert $page) => $page
        ->where('jobTitle', null)
        ->loadDeferredProps(fn (Assert $reload) => $reload->where('weeklyStats', ['submitted' => 1, 'on_time' => 1, 'streak' => 1])));

    $this->actingAs($me)
        ->patch('/ajustes/perfil', ['name' => $me->name, 'email' => $me->email, 'job_title' => '  Diseñadora  '])
        ->assertSessionHasNoErrors();

    expect($me->fresh()?->job_title)->toBe('Diseñadora');
});
