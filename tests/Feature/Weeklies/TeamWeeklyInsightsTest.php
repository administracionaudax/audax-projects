<?php

use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Insights\AiSummaries;
use App\Domain\Weeklies\Insights\InsightPrompts;
use App\Domain\Weeklies\Insights\PersonInsights;
use App\Enums\AbsenceType;
use App\Enums\AiFeature;
use App\Enums\AiSummaryKind;
use App\Enums\WeeklyJobState;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\GenerateAiSummary;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Equipo de la Weekly (10.4, F-134 a F-145, D-147 y D-194): la lista con el estado del reporte, la
| ficha de persona (racha, hábitos, clientes e historial) y los resúmenes con IA de una persona, que
| solo ven el admin y sus responsables.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Madrid'));
    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->boss = userWithRole('department_manager', ['name' => 'Raúl', 'created_at' => '2026-08-01']);
    $this->boss->managedDepartments()->attach($this->design->id);
    $this->ana = userWithRole('employee', ['name' => 'Ana', 'email' => 'ana@audax.test', 'job_title' => 'Diseñadora', 'department_id' => $this->design->id, 'created_at' => '2026-08-01']);
    $this->pablo = userWithRole('employee', ['name' => 'Pablo', 'department_id' => $this->design->id, 'created_at' => '2026-08-01']);
    $this->acme = Client::factory()->create(['name' => 'Acme', 'icon' => '🍷']);
    $this->beta = Client::factory()->create(['name' => 'Beta']);
    Project::factory()->create(['client_id' => $this->acme->id, 'code' => 'ACME-WE1', 'owner_user_id' => $this->ana->id]);
    Project::factory()->hourBank()->withMembers([$this->ana])->create(['client_id' => $this->beta->id, 'code' => 'BETA-BH']);
    $this->closed = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create(['expected_user_ids' => [$this->boss->id, $this->ana->id, $this->pablo->id]]);
    $this->active = WeeklyCycle::factory()->active('2026-10-05')->create();
});

function teamEntry(WeeklyCycle $cycle, User $user, ?Client $client, string $body, string $at): void
{
    $submission = WeeklySubmission::query()->firstOrCreate(
        ['weekly_cycle_id' => $cycle->id, 'user_id' => $user->id],
        ['submitted_at' => CarbonImmutable::parse($at, 'Europe/Madrid')->utc(), 'draft_saved_at' => now()],
    );
    WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'client_id' => $client?->id, 'body' => $body]);
}

// --- Lista ------------------------------------------------------------------------------------

it('la lista trae a la plantilla con su estado de la semana activa, sus clientes y si hoy está ausente', function () {
    teamEntry($this->active, $this->ana, $this->acme, 'Hecho', '2026-10-06 10:00');
    Absence::factory()->approved()->between('2026-10-07', '2026-10-09')->create(['user_id' => $this->pablo->id, 'type' => AbsenceType::Sick]);
    User::factory()->collaborator()->create();

    $rows = fn (User $viewer) => collect($this->actingAs($viewer)->get('/equipo')->viewData('page')['props']['members'])->keyBy('user.name');

    $asBoss = $rows($this->boss);
    expect($asBoss->keys()->all())->toBe(['Ana', 'Pablo', 'Raúl'])
        ->and($asBoss['Ana']['report_status'])->toBe('submitted')
        ->and($asBoss['Ana']['email'])->toBe('ana@audax.test')
        ->and($asBoss['Ana']['job_title'])->toBe('Diseñadora')
        ->and($asBoss['Ana']['department']['name'])->toBe('Diseño')
        ->and($asBoss['Ana']['role'])->toBe('employee')
        ->and($asBoss['Ana']['client_ids'])->toEqualCanonicalizing([$this->acme->id, $this->beta->id])
        ->and($asBoss['Raúl']['report_status'])->toBe('pending')
        // La baja de Pablo cubre el plazo: está exento y su responsable ve el tipo.
        ->and($asBoss['Pablo']['report_status'])->toBe('exempt')
        ->and($asBoss['Pablo']['absence'])->toBe(['type' => 'sick', 'until' => '2026-10-09']);

    // Un compañero ve que no está, pero no por qué (D-088).
    expect($rows($this->ana)['Pablo']['absence'])->toBe(['type' => null, 'until' => '2026-10-09']);
});

it('la lista y la ficha son de la plantilla: un colaborador externo no entra; sin el módulo, 404', function () {
    $this->actingAs(User::factory()->collaborator()->create())->get('/equipo')->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())->get("/equipo/{$this->ana->id}")->assertForbidden();

    Setting::set('modules', ['weeklies' => false]);
    $this->actingAs(userWithRole('admin'))->get('/equipo')->assertNotFound();
});

it('la ficha de alguien que no escribe la weekly (colaborador o cliente) da 404', function () {
    $this->actingAs($this->ana)->get('/equipo/'.User::factory()->collaborator()->create()->id)->assertNotFound();
    $this->actingAs($this->ana)->get('/equipo/'.User::factory()->client()->create()->id)->assertNotFound();
});

// --- Ficha de persona -------------------------------------------------------------------------

it('la ficha: estado, racha, hábitos, clientes, último reporte por cliente e historial por semanas', function () {
    teamEntry($this->closed, $this->ana, $this->acme, 'Diseño de la home', '2026-10-03 19:30'); // sábado: con retraso
    teamEntry($this->closed, $this->ana, null, 'Formación interna', '2026-10-03 19:30');
    teamEntry($this->active, $this->ana, $this->beta, 'Bolsa de soporte', '2026-10-06 10:00');

    $this->actingAs($this->pablo)->get("/equipo/{$this->ana->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('team/show')
            ->where('person.name', 'Ana')
            ->where('person.email', 'ana@audax.test')
            ->where('person.role', 'employee')
            ->where('status', 'submitted')
            ->where('streak.submitted', 2)
            ->where('habits.total', 2)
            ->where('habits.average_time', '14:45')
            ->where('habits.time_of_day', 'afternoon')
            ->where('habits.most_common_day', 'saturday')
            ->where('habits.distribution', ['friday' => 0, 'saturday' => 1, 'sunday' => 0, 'other' => 1])
            ->where('clients.owned.0.name', 'Acme')
            ->where('clients.owned.0.badges', [['tag' => 'web', 'count' => 1]])
            ->where('clients.member.0.name', 'Beta')
            ->where('clients.member.0.badges', [['tag' => 'hour_bank', 'count' => 1]])
            ->has('last_reports', 3)
            ->where('last_reports.0.client.name', 'Beta')
            ->has('weeks', 2)
            ->where('weeks.0.cycle.id', $this->active->id)
            ->where('weeks.0.status', 'submitted')
            ->where('weeks.1.status', 'submitted_late')
            ->has('weeks.1.entries', 2)
            // Un compañero no ve los resúmenes con IA (D-147).
            ->where('ai', null)
            ->where('can.viewAi', false));
});

it('la ficha trae el mapa de constancia de las últimas 12 semanas, solo desde el alta (D-233)', function () {
    teamEntry($this->closed, $this->ana, $this->acme, 'A tiempo', '2026-10-02 10:00');
    $older = WeeklyCycle::factory()->forWeekOf('2026-09-21')->create(['expected_user_ids' => [$this->ana->id]]);
    teamEntry($older, $this->ana, $this->acme, 'Tarde', '2026-09-28 10:00');
    $missed = WeeklyCycle::factory()->forWeekOf('2026-09-14')->create(['expected_user_ids' => [$this->ana->id]]);
    $exempt = WeeklyCycle::factory()->forWeekOf('2026-09-07')->create(['expected_user_ids' => []]);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $exempt->id, 'user_id' => $this->ana->id]);
    // Antes de su alta (01/08): no sale.
    WeeklyCycle::factory()->forWeekOf('2026-07-20')->create(['expected_user_ids' => []]);

    $this->actingAs($this->pablo)->get("/equipo/{$this->ana->id}")->assertInertia(fn (Assert $page) => $page
        ->where('consistency', fn ($cells) => collect($cells)->pluck('state')->all() === ['exempt', 'missed', 'late', 'on_time', 'pending']
            && collect($cells)->last()['cycle_id'] === $this->active->id));

    $this->actingAs($this->ana)->get('/ajustes/perfil')->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload->where('weeklyStats.consistency.4.state', 'pending')->where('weeklyStats.on_time', 1)));
});

it('«Ver histórico» de una persona en un cliente: todos sus reportes, de 25 en 25 (D-233)', function () {
    foreach (range(1, 27) as $i) {
        $cycle = WeeklyCycle::factory()->forWeekOf(CarbonImmutable::parse('2026-01-05')->addWeeks($i)->toDateString())->create();
        teamEntry($cycle, $this->ana, $this->acme, "Reporte {$i}", $cycle->deadline_date->toDateString().' 10:00');
    }
    teamEntry($this->closed, $this->ana, null, 'General', '2026-10-02 10:00');

    $first = $this->actingAs($this->pablo)->getJson("/equipo/{$this->ana->id}/clientes/{$this->acme->id}/historial")->assertOk();
    expect($first->json('reports'))->toHaveCount(25)
        ->and($first->json('reports.0.body'))->toBe('Reporte 27')
        ->and($first->json('next_page'))->toBe(2);

    $second = $this->actingAs($this->pablo)->getJson("/equipo/{$this->ana->id}/clientes/{$this->acme->id}/historial?pagina=2")->assertOk();
    expect(collect($second->json('reports'))->pluck('body')->all())->toBe(['Reporte 2', 'Reporte 1'])
        ->and($second->json('next_page'))->toBeNull();

    $this->actingAs($this->pablo)->getJson("/equipo/{$this->ana->id}/clientes/general/historial")->assertJsonPath('reports.0.body', 'General');
    $this->actingAs(User::factory()->collaborator()->create())->getJson("/equipo/{$this->ana->id}/clientes/{$this->acme->id}/historial")->assertForbidden();
});

it('el historial de la ficha ya no se corta en un año: va por páginas (10.9b)', function () {
    foreach (range(1, 54) as $i) {
        $cycle = WeeklyCycle::factory()->forWeekOf(CarbonImmutable::parse('2025-01-06')->addWeeks($i)->toDateString())->create();
        teamEntry($cycle, $this->ana, $this->acme, "Semana {$i}", $cycle->deadline_date->toDateString().' 10:00');
    }

    $this->actingAs($this->pablo)->get("/equipo/{$this->ana->id}")->assertInertia(fn (Assert $page) => $page
        ->where('weeks_page', 1)
        ->where('weeks_more', true));
    $this->actingAs($this->pablo)->get("/equipo/{$this->ana->id}?historial=2")->assertInertia(fn (Assert $page) => $page
        ->where('weeks_page', 2)
        ->where('weeks_more', false)
        ->where('weeks', fn ($weeks) => collect($weeks)->pluck('entries.0.body')->all() === ['Semana 4', 'Semana 3', 'Semana 2', 'Semana 1'])
        // El último reporte por cliente sigue siendo el más reciente.
        ->where('last_reports.0.body', 'Semana 54'));
});

it('quien gestiona asigna varios clientes a una persona de golpe y se los quita (D-233)', function () {
    $gamma = Client::factory()->create(['name' => 'Gamma']);
    $delta = Client::factory()->create(['name' => 'Delta']);

    $this->actingAs($this->boss)->get("/equipo/{$this->pablo->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.assignClients', true)
        ->missing('assignable_clients'));
    $this->actingAs($this->boss)->get("/equipo/{$this->pablo->id}", [
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'team/show', 'X-Inertia-Partial-Data' => 'assignable_clients',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
    ])->assertJsonPath('props.assignable_clients', fn ($clients) => collect($clients)->pluck('name')->all() === ['Acme', 'Beta', 'Delta', 'Gamma']);

    $this->actingAs($this->boss)
        ->post("/equipo/{$this->pablo->id}/clientes", ['client_ids' => [$gamma->id, $delta->id]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.flash.assigned', 2, ['count' => 2, 'name' => 'Pablo']));

    $this->actingAs($this->pablo)->get("/equipo/{$this->pablo->id}")->assertInertia(fn (Assert $page) => $page
        ->where('clients.member', fn ($clients) => collect($clients)->where('subscribed', true)->pluck('name')->sort()->values()->all() === ['Delta', 'Gamma'])
        ->where('can.assignClients', false));

    // No da acceso a ningún proyecto (D-221).
    expect($this->pablo->projects()->count())->toBe(0);

    $this->actingAs($this->ana)->post("/equipo/{$this->pablo->id}/clientes", ['client_ids' => [$gamma->id]])->assertForbidden();
    $this->actingAs($this->boss)->delete("/equipo/{$this->pablo->id}/clientes/{$gamma->id}")->assertSessionHasNoErrors();
    $this->actingAs($this->boss)->delete("/equipo/{$this->pablo->id}/clientes/{$gamma->id}")->assertNotFound();
    $this->actingAs($this->ana)->delete("/equipo/{$this->pablo->id}/clientes/{$delta->id}")->assertForbidden();
});

it('los hábitos de envío, en la hora de Madrid (calculateSubmissionStats)', function () {
    $habits = PersonInsights::habits([
        CarbonImmutable::parse('2026-10-02 10:30', 'Europe/Madrid'), // viernes
        CarbonImmutable::parse('2026-09-25 13:00', 'Europe/Madrid'), // viernes
        CarbonImmutable::parse('2026-09-19 19:00', 'Europe/Madrid'), // sábado
    ]);

    expect($habits)->toBe([
        'total' => 3,
        'average_time' => '14:10',
        'time_of_day' => 'afternoon',
        'most_common_day' => 'friday',
        'distribution' => ['friday' => 2, 'saturday' => 1, 'sunday' => 0, 'other' => 0],
    ])->and(PersonInsights::habits([]))->toBeNull()
        ->and(PersonInsights::habits([CarbonImmutable::parse('2026-10-05 08:00', 'Europe/Madrid')])['time_of_day'])->toBe('morning')
        ->and(PersonInsights::habits([CarbonImmutable::parse('2026-10-04 21:00', 'Europe/Madrid')])['most_common_day'])->toBe('sunday');
});

// --- Resúmenes con IA de una persona (D-147) -----------------------------------------------------

it('los resúmenes de una persona los ven y piden el admin y sus responsables; ni un compañero, ni ella, ni otro responsable', function () {
    Queue::fake();
    $otherBoss = userWithRole('department_manager');
    $otherBoss->managedDepartments()->attach(Department::factory()->create()->id);

    $viewers = [[$this->boss, true], [userWithRole('admin'), true], [$this->pablo, false], [$this->ana, false], [$otherBoss, false]];

    foreach ($viewers as [$viewer, $allowed]) {
        $this->actingAs($viewer)->get("/equipo/{$this->ana->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.viewAi', $allowed)->where('ai', $allowed ? ['performance' => null, 'client_activity' => null] : null));
    }

    foreach ($viewers as [$viewer, $allowed]) {
        $this->actingAs($viewer)->postJson("/equipo/{$this->ana->id}/resumen-ia?tipo=desempeno")->assertStatus($allowed ? 202 : 403);
    }

    $this->actingAs(User::factory()->collaborator()->create())->postJson("/equipo/{$this->ana->id}/resumen-ia")->assertForbidden();
    Queue::assertPushed(GenerateAiSummary::class, 1);
});

it('el resumen de desempeño lleva el prompt de WeeklySync con sus últimos envíos', function () {
    $llm = FakeLlm::bind()->push("## Enfoque actual\nDiseño de Acme.\n");
    teamEntry($this->closed, $this->ana, $this->acme, 'Diseño de la home', '2026-10-02 18:00');
    teamEntry($this->closed, $this->ana, null, 'Formación interna', '2026-10-02 18:00');

    $this->actingAs($this->boss)->post("/equipo/{$this->ana->id}/resumen-ia", ['tipo' => 'desempeno'])->assertRedirect();

    $summary = app(AiSummaries::class)->find(AiSummaryKind::PersonPerformance, $this->ana);
    expect($summary?->state)->toBe(WeeklyJobState::Done)
        ->and($summary?->content)->toBe("## Enfoque actual\nDiseño de Acme.")
        ->and($summary?->requested_by)->toBe($this->boss->id);

    $llm->assertSent(fn (LlmRequest $request): bool => $request->feature === AiFeature::PersonPerformance
        && $request->subject?->is($this->ana)
        && str_contains($request->prompt, 'Genera un resumen profesional de desempeño para Ana basándote en sus reportes semanales recientes.')
        && str_contains($request->prompt, "**{$this->closed->label}** (2/10/2026)\nReporte general: Formación interna\nReportes por cliente:\n  - Acme: Diseño de la home\n"));

    $this->actingAs($this->boss)->get("/equipo/{$this->ana->id}")
        ->assertInertia(fn (Assert $page) => $page->where('ai.performance.state', 'done')->where('ai.performance.requested_by.id', $this->boss->id));
});

it('sin envíos, el desempeño no llama a la IA', function () {
    $llm = FakeLlm::bind();

    $summary = app(AiSummaries::class)->request(AiSummaryKind::PersonPerformance, $this->ana, $this->boss)->refresh();

    expect($summary->content)->toBe(InsightPrompts::INSUFFICIENT_PERFORMANCE_HISTORY);
    $llm->assertNothingSent();
});

it('la actividad por cliente da una frase por cliente que lidera o en el que colabora', function () {
    $llm = FakeLlm::bind()->push(['summaries' => [['clientId' => (string) $this->acme->id, 'summary' => 'Lidera el diseño de la web.']]]);
    teamEntry($this->closed, $this->ana, $this->acme, 'Diseño de la home', '2026-10-02 18:00');

    $this->actingAs($this->boss)->post("/equipo/{$this->ana->id}/resumen-ia", ['tipo' => 'clientes'])->assertRedirect();

    $summary = app(AiSummaries::class)->find(AiSummaryKind::PersonClientActivity, $this->ana);
    expect($summary?->items)->toBe([
        (string) $this->acme->id => 'Lidera el diseño de la web.',
        (string) $this->beta->id => InsightPrompts::NO_ACTIVITY,
    ]);
    $llm->assertSent(fn (LlmRequest $request): bool => $request->feature === AiFeature::PersonClientActivity
        && str_contains($request->prompt, 'Genera un resumen corto y concreto por cliente para la persona "Ana".')
        && str_contains($request->prompt, '{"clientId":"'.$this->acme->id.'","clientName":"Acme","role":"owner","reports":[{"weekLabel":"'.$this->closed->label.'"')
        && str_contains($request->prompt, '{"clientId":"'.$this->beta->id.'","clientName":"Beta","role":"collaborator","reports":[]}'));
});

// --- Rendimiento ------------------------------------------------------------------------------

it('la lista y la ficha no crecen con la plantilla ni con el histórico', function () {
    $grow = function (int $people): void {
        foreach (range(1, $people) as $i) {
            $user = userWithRole('employee', ['department_id' => $this->design->id, 'created_at' => '2026-08-01']);
            $client = Client::factory()->create();
            Project::factory()->withMembers([$user, $this->ana])->create(['client_id' => $client->id]);
            teamEntry($this->active, $user, $client, "Apunte {$i}", '2026-10-06 10:00');
            teamEntry(WeeklyCycle::factory()->create(), $this->ana, $client, "Semana {$i}", '2026-09-01 10:00');
            Absence::factory()->approved()->between('2026-10-08', '2026-10-08')->create(['user_id' => $user->id]);
        }
    };
    $measure = function (string $uri): int {
        $this->actingAs($this->boss)->get($uri)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->boss)->get($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    // La ficha, +1 con D-221: los clientes a los que se ha unido en la Weekly.
    $pages = ['equipo' => ['/equipo', 24], 'ficha' => ["/equipo/{$this->ana->id}", 31]];

    $grow(3);
    $small = array_map(fn (array $page): int => $measure($page[0]), $pages);
    $grow(12);

    foreach ($pages as $label => [$uri, $budget]) {
        $large = $measure($uri);

        expect($large)->toBeLessThanOrEqual($budget, "{$label}: {$large} consultas")
            ->and($large - $small[$label])->toBeLessThanOrEqual(0, "{$label} crece");
    }
});
