<?php

use App\Domain\Weeklies\Ai\AiUsageRecorder;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\GeminiClient;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\Insights\AiSummaries;
use App\Domain\Weeklies\Insights\ClientInsights;
use App\Domain\Weeklies\Insights\InsightPrompts;
use App\Enums\AiFeature;
use App\Enums\AiSummaryKind;
use App\Enums\BillingType;
use App\Enums\WeeklyJobState;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\GenerateAiSummary;
use App\Models\AiSummary;
use App\Models\AiUsage;
use App\Models\Client;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

/*
| La Weekly en los clientes (10.4, F-096 y F-123 a F-133, D-194): la cartera con el último reporte,
| la satisfacción, las insignias, el orden y los filtros; la ficha con sus pestañas; y el resumen del
| cliente y el análisis del equipo con IA (cola `ai`, guardados hasta regenerarlos).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Madrid'));
    $this->owner = userWithRole('department_manager', ['name' => 'Raúl Gestor', 'job_title' => 'Director de cuentas', 'created_at' => '2026-08-01']);
    $this->ana = userWithRole('employee', ['name' => 'Ana Díaz', 'created_at' => '2026-08-01']);
    $this->acme = Client::factory()->create(['name' => 'Acme', 'icon' => '🍷', 'satisfaction_score' => 64]);
    $this->web = Project::factory()->fixedPrice()->withMembers([$this->ana])->create([
        'client_id' => $this->acme->id, 'code' => 'ACME-WE1', 'name' => 'Web', 'budget_minutes' => 6000, 'owner_user_id' => $this->owner->id,
    ]);
    $this->closed = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create();
    $this->older = WeeklyCycle::factory()->forWeekOf('2026-09-21')->create();
    $this->active = WeeklyCycle::factory()->active('2026-10-05')->create();
});

/** Un apunte enviado de $user sobre $client en la semana. */
function insightEntry(WeeklyCycle $cycle, User $user, ?Client $client, string $body, string $at): WeeklyEntry
{
    $submission = WeeklySubmission::query()->firstOrCreate(
        ['weekly_cycle_id' => $cycle->id, 'user_id' => $user->id],
        ['submitted_at' => CarbonImmutable::parse($at, 'Europe/Madrid')->utc(), 'draft_saved_at' => now()],
    );

    return WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'client_id' => $client?->id, 'body' => $body]);
}

/** El informe de una semana con la actualización de un cliente. */
function insightReport(WeeklyCycle $cycle, Client $client, string $summary, ?int $satisfaction = null, string $status = 'on_track'): void
{
    $cycle->forceFill(['report' => ['global_summary' => 'Global', 'team_risks' => [], 'client_updates' => [[
        'client_id' => $client->id,
        'client_name' => $client->name,
        'status' => $status,
        'executive_summary' => $summary,
        'next_steps' => ['Revisar la home'],
        'milestones' => [['date' => '2026-10-15', 'label' => 'Lanzamiento']],
        'tags' => ['web'],
        'satisfaction_score' => $satisfaction,
        'has_reports' => true,
        'projects' => [],
    ]]]])->save();
}

// --- Cartera de clientes ---------------------------------------------------------------------

it('la cartera trae el icono, el último reporte, la satisfacción con su tendencia y las insignias', function () {
    insightEntry($this->closed, $this->ana, $this->acme, 'Maquetación de la home', '2026-10-02 17:00');
    ClientSatisfactionSnapshot::factory()->create(['client_id' => $this->acme->id, 'weekly_cycle_id' => $this->closed->id, 'score' => 64, 'previous_score' => 60]);
    Project::factory()->hourBank()->create(['client_id' => $this->acme->id, 'code' => 'ACME-BH']);

    $this->actingAs($this->ana)->get('/clientes')
        ->assertInertia(fn (Assert $page) => $page
            ->component('clients/index')
            ->where('weekly', true)
            ->where('clients.data.0.icon', '🍷')
            ->where('clients.data.0.satisfaction_score', 64)
            ->where('clients.data.0.satisfaction_trend', 4)
            ->where('clients.data.0.last_report_at', '2026-10-02T15:00:00+00:00')
            ->where('clients.data.0.kind_badges', [['tag' => 'web', 'count' => 1], ['tag' => 'hour_bank', 'count' => 1]])
            ->missing('people')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('people.0.name', 'Ana Díaz')));
});

it('ordena por último reporte (sin reportes, al final) y por satisfacción', function () {
    $beta = Client::factory()->create(['name' => 'Beta', 'satisfaction_score' => 80]);
    $gamma = Client::factory()->create(['name' => 'Gamma', 'satisfaction_score' => 30]);
    insightEntry($this->closed, $this->ana, $this->acme, 'A', '2026-10-02 17:00');
    insightEntry($this->older, $this->ana, $beta, 'B', '2026-09-25 17:00');

    $names = fn (string $query): array => collect($this->actingAs($this->ana)->get("/clientes?{$query}")->viewData('page')['props']['clients']['data'])->pluck('name')->all();

    expect($names('orden=ultimo_reporte&dir=desc'))->toBe(['Acme', 'Beta', 'Gamma'])
        ->and($names('orden=ultimo_reporte&dir=asc'))->toBe(['Gamma', 'Beta', 'Acme'])
        ->and($names('orden=satisfaccion&dir=desc'))->toBe(['Beta', 'Acme', 'Gamma'])
        ->and($names('orden=nombre&dir=desc'))->toBe(['Gamma', 'Beta', 'Acme']);
});

it('filtra por tipo de proyecto, por persona y por «Mis proyectos»', function () {
    $beta = Client::factory()->create(['name' => 'Beta']);
    Project::factory()->hourBank()->create(['client_id' => $beta->id, 'code' => 'BETA-BH']);
    Project::factory()->create(['client_id' => $beta->id, 'code' => 'BETA-OLD', 'status' => 'completed', 'owner_user_id' => $this->ana->id]);

    $names = fn (User $as, string $query): array => collect($this->actingAs($as)->get("/clientes?{$query}")->viewData('page')['props']['clients']['data'])->pluck('name')->all();

    expect($names($this->ana, 'tipo=hour_bank'))->toBe(['Beta'])
        ->and($names($this->ana, 'tipo=web'))->toBe(['Acme'])
        ->and($names($this->ana, "persona={$this->owner->id}"))->toBe(['Acme'])
        // Un proyecto terminado no cuenta: Ana solo está en los proyectos abiertos de Acme.
        ->and($names($this->ana, 'mios=1'))->toBe(['Acme'])
        ->and($names(userWithRole('employee'), 'mios=1'))->toBe([]);
});

it('sin el módulo de la Weekly no hay columnas de la Weekly ni se ordena por ellas', function () {
    Setting::set('modules', ['weeklies' => false]);

    $this->actingAs($this->ana)->get('/clientes?orden=satisfaccion')
        ->assertInertia(fn (Assert $page) => $page->where('weekly', false)->where('filters.orden', 'nombre')->missing('clients.data.0.last_report_at_raw'));
});

it('el icono es un solo emoji', function (string $icon, bool $valid) {
    $response = $this->actingAs(userWithRole('admin'))->put("/clientes/{$this->acme->id}", ['name' => 'Acme', 'icon' => $icon]);

    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('icon');
})->with([
    'copa' => ['🥂', true],
    'bandera' => ['🇪🇸', true],
    'familia' => ['👨‍👩‍👧', true],
    'letras' => ['AB', false],
    'dos emojis' => ['🍷🍷', false],
    'una letra' => ['A', false],
]);

// --- Ficha de cliente ------------------------------------------------------------------------

it('la ficha trae el responsable, las insignias y la pestaña pedida; la Weekly llega diferida', function () {
    insightReport($this->closed, $this->acme, 'Avanza la web', 64);

    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('clients/show')
            ->where('tab', 'resumen')
            ->where('owner.id', $this->owner->id)
            ->where('owner.job_title', 'Director de cuentas')
            ->where('kindBadges', [['tag' => 'web', 'count' => 1]])
            ->where('can.useWeeklies', true)
            ->missing('weekly')
            ->loadDeferredProps('weekly', fn (Assert $reload) => $reload
                ->where('weekly.tab', 'resumen')
                ->where('weekly.latest.cycle.id', $this->closed->id)
                ->where('weekly.latest.update.executive_summary', 'Avanza la web')
                ->where('weekly.satisfaction.score', 64)
                ->where('weekly.ai', null)));
});

it('el historial junta lo que dijo el informe y los reportes de cada semana', function () {
    insightReport($this->closed, $this->acme, 'Avanza la web', 64, 'risk');
    insightEntry($this->closed, $this->ana, $this->acme, 'Maquetación de la home', '2026-10-02 17:00');
    insightEntry($this->older, $this->owner, $this->acme, 'Kick-off', '2026-09-25 12:00');
    insightEntry($this->older, $this->ana, Client::factory()->create(), 'Otro cliente', '2026-09-25 12:00');

    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}?pestana=historial")
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'historial')
            ->loadDeferredProps('weekly', fn (Assert $reload) => $reload
                ->has('weekly.weeks', 2)
                ->where('weekly.weeks.0.cycle.id', $this->closed->id)
                ->where('weekly.weeks.0.update.status', 'risk')
                ->where('weekly.weeks.0.entries.0.body', 'Maquetación de la home')
                ->where('weekly.weeks.0.entries.0.author.name', 'Ana Díaz')
                ->where('weekly.weeks.1.update', null)
                ->has('weekly.weeks.1.entries', 1)));
});

it('el historial ya no se corta en medio año: va por páginas (10.9b)', function () {
    foreach (range(1, 26) as $i) {
        $cycle = WeeklyCycle::factory()->forWeekOf(CarbonImmutable::parse('2026-01-05')->subWeeks($i)->toDateString())->create();
        insightEntry($cycle, $this->ana, $this->acme, "Antiguo {$i}", $cycle->deadline_date->toDateString().' 10:00');
    }

    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}?pestana=historial")
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('weekly', fn (Assert $reload) => $reload
            ->where('weekly.page', 1)
            ->where('weekly.more', true)));

    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}?pestana=historial&historial=2")
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('weekly', fn (Assert $reload) => $reload
            ->where('weekly.page', 2)
            ->where('weekly.more', false)
            ->where('weekly.weeks', fn ($weeks) => collect($weeks)->pluck('entries.0.body')->last() === 'Antiguo 26')));
});

it('el equipo: el responsable primero, los miembros con sus proyectos y su histórico, y mis proyectos', function () {
    insightEntry($this->closed, $this->ana, $this->acme, 'Maquetación', '2026-10-02 17:00');
    userWithRole('employee', ['name' => 'Inactiva'])->forceFill(['is_active' => false])->save();

    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}?pestana=equipo")
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('weekly', fn (Assert $reload) => $reload
                ->where('weekly.owner_id', $this->owner->id)
                ->has('weekly.members', 2)
                ->where('weekly.members.0.user.id', $this->owner->id)
                ->where('weekly.members.0.role', 'owner')
                ->where('weekly.members.1.user.id', $this->ana->id)
                ->where('weekly.members.1.projects.0.code', 'ACME-WE1')
                ->where('weekly.members.1.reports.0.body', 'Maquetación')
                ->where('weekly.my_projects.0.code', 'ACME-WE1')
                ->where('weekly.subscription', ['subscribed' => false, 'can_join' => true])));
});

it('la satisfacción: la serie de cada cierre y las tendencias semanal, mensual y trimestral', function () {
    ClientSatisfactionSnapshot::factory()->create(['client_id' => $this->acme->id, 'weekly_cycle_id' => $this->older->id, 'score' => 58, 'delta' => 0]);
    ClientSatisfactionSnapshot::factory()->create(['client_id' => $this->acme->id, 'weekly_cycle_id' => $this->closed->id, 'score' => 64, 'delta' => 6]);

    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}?pestana=satisfaccion")
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('weekly', fn (Assert $reload) => $reload
                ->where('weekly.score', 64)
                ->where('weekly.points.0.cycle_id', $this->older->id)
                ->where('weekly.points.1.score', 64)
                ->where('weekly.deltas', ['weekly' => 6, 'monthly' => null, 'quarterly' => null])));

    expect(ClientInsights::deltas([50, 52, 51, 55, 60, 58, 62, 61, 63, 64, 66, 65, 67, 70]))
        ->toBe(['weekly' => 3, 'monthly' => 6, 'quarterly' => 20]);
});

it('sin la Weekly, la ficha es la de siempre: sin pestañas ni datos de la Weekly', function () {
    Setting::set('modules', ['weeklies' => false]);

    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}?pestana=equipo")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'resumen')->where('weekly', null)->where('can.useWeeklies', false));
});

it('el responsable es quien gestiona más proyectos abiertos; a igualdad, el del más antiguo', function () {
    $other = userWithRole('employee');
    Project::factory()->create(['client_id' => $this->acme->id, 'owner_user_id' => $other->id]);
    Project::factory()->create(['client_id' => $this->acme->id, 'owner_user_id' => $other->id, 'status' => 'completed']);

    expect(ClientInsights::mainOwner($this->acme->projects()->with('owner')->get())?->id)->toBe($this->owner->id);

    Project::factory()->create(['client_id' => $this->acme->id, 'owner_user_id' => $other->id]);

    expect(ClientInsights::mainOwner($this->acme->projects()->with('owner')->get())?->id)->toBe($other->id);
});

it('la cartera trae el responsable y hasta tres personas del equipo con el total (D-232)', function () {
    $people = collect(range(1, 4))->map(fn (int $i) => userWithRole('employee', ['name' => "Persona {$i}", 'created_at' => '2026-08-01']));
    $people->each(fn (User $user) => $this->web->addMember($user->id));
    $subscriber = userWithRole('employee', ['name' => 'Zoe Suscrita']);
    DB::table('weekly_client_subscriptions')->insert(['client_id' => $this->acme->id, 'user_id' => $subscriber->id, 'created_at' => now(), 'updated_at' => now()]);
    userWithRole('employee', ['name' => 'De baja', 'is_active' => false])->id;

    $this->actingAs($this->ana)->get('/clientes')
        ->assertInertia(fn (Assert $page) => $page
            ->where('clients.data.0.portfolio_team.owner.name', 'Raúl Gestor')
            ->where('clients.data.0.portfolio_team.team.0.name', 'Raúl Gestor')
            ->has('clients.data.0.portfolio_team.team', 3)
            // Raúl, Ana, las cuatro personas y Zoe.
            ->where('clients.data.0.portfolio_team.team_count', 7));
});

it('se puede elegir el responsable del cliente; sin elegir, se deduce de los proyectos (D-232)', function () {
    $chosen = userWithRole('employee', ['name' => 'Elena Elegida', 'created_at' => '2026-08-01']);

    $this->actingAs($this->owner)
        ->put("/clientes/{$this->acme->id}", ['name' => 'Acme', 'owner_user_id' => $chosen->id])
        ->assertSessionHasNoErrors();

    expect($this->acme->refresh()->owner_user_id)->toBe($chosen->id);

    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}")->assertInertia(fn (Assert $page) => $page
        ->where('owner.id', $chosen->id)
        ->where('client.owner_user_id', $chosen->id));
    $this->actingAs($this->ana)->get('/clientes')->assertInertia(fn (Assert $page) => $page
        ->where('clients.data.0.portfolio_team.owner.id', $chosen->id));

    // De baja, vuelve a mandar el de los proyectos.
    $chosen->forceFill(['is_active' => false])->save();
    expect(ClientInsights::ownerOf($this->acme->refresh(), $this->acme->projects()->with('owner')->get())?->id)->toBe($this->owner->id);

    // Solo la plantilla activa; vacío, automático.
    $this->actingAs($this->owner)
        ->putJson("/clientes/{$this->acme->id}", ['name' => 'Acme', 'owner_user_id' => User::factory()->collaborator()->create()->id])
        ->assertJsonValidationErrors(['owner_user_id' => __('clients.errors.owner')]);
    $this->actingAs($this->owner)->put("/clientes/{$this->acme->id}", ['name' => 'Acme', 'owner_user_id' => null])->assertSessionHasNoErrors();
    expect($this->acme->refresh()->owner_user_id)->toBeNull();

    // La ficha ofrece la plantilla para elegirlo a quien edita.
    $this->actingAs($this->owner)->get("/clientes/{$this->acme->id}")->assertInertia(fn (Assert $page) => $page
        ->missing('people')
        ->loadDeferredProps('people', fn (Assert $reload) => $reload->where('people.0.name', 'Ana Díaz')));
    $this->actingAs($this->ana)->get("/clientes/{$this->acme->id}")->assertInertia(fn (Assert $page) => $page->where('people', null));
});

// --- Resúmenes con IA del cliente ------------------------------------------------------------

it('pedir el resumen lo encola en la cola ai una sola vez mientras se genera; atascado, se puede volver a pedir', function () {
    Queue::fake();

    $this->actingAs($this->ana)->post("/clientes/{$this->acme->id}/resumen-ia")->assertRedirect();
    $this->actingAs($this->owner)->post("/clientes/{$this->acme->id}/resumen-ia")->assertRedirect();

    Queue::assertPushedOn('ai', GenerateAiSummary::class);
    Queue::assertPushed(GenerateAiSummary::class, 1);
    $summary = AiSummary::query()->sole();
    expect($summary->state)->toBe(WeeklyJobState::Queued)
        ->and($summary->requested_by)->toBe($this->ana->id);

    $this->travel(13)->minutes();
    $this->actingAs($this->owner)->postJson("/clientes/{$this->acme->id}/resumen-ia")
        ->assertStatus(202)
        ->assertJsonPath('summary.state', 'queued');

    Queue::assertPushed(GenerateAiSummary::class, 2);
    expect($summary->fresh()->requested_by)->toBe($this->owner->id);
});

it('el resumen del cliente lleva el prompt de WeeklySync con sus weeklies, su satisfacción, su equipo y sus proyectos', function () {
    $llm = FakeLlm::bind()->push("```markdown\n## Estado actual\nTodo en orden.\n```");
    insightReport($this->closed, $this->acme, 'Avanza la web', 64);
    insightReport($this->older, $this->acme, 'Arranque', 60);
    insightEntry($this->closed, $this->ana, $this->acme, "Maquetación   de la home\ncon el equipo", '2026-10-02 17:00');
    insightEntry($this->older, $this->owner, $this->acme, 'Kick-off con el cliente', '2026-09-25 12:00');

    $summary = app(AiSummaries::class)->request(AiSummaryKind::ClientSummary, $this->acme, $this->ana);

    $summary->refresh();
    expect($summary->state)->toBe(WeeklyJobState::Done)
        ->and($summary->content)->toBe("## Estado actual\nTodo en orden.")
        ->and($summary->model)->toBe('fake-gemini')
        ->and($summary->generated_at)->not->toBeNull();

    $llm->assertSent(function (LlmRequest $request): bool {
        return $request->feature === AiFeature::ClientSummary
            && $request->user?->is($this->ana)
            && $request->subject?->is($this->acme)
            && str_contains($request->prompt, "CLIENTE:\nAcme")
            && str_contains($request->prompt, '- Satisfacción actual: 64%')
            && str_contains($request->prompt, '- Responsable actual: Raúl Gestor.')
            && str_contains($request->prompt, '- Equipo actual: Ana Díaz.')
            && str_contains($request->prompt, 'ACME-WE1 (Web): 0/100h, estado normal.')
            && str_contains($request->prompt, '- Tendencia reciente de satisfacción: '.$this->older->label.': 60% | '.$this->closed->label.': 64%')
            && str_contains($request->prompt, "SEMANA 1: {$this->closed->label} (2026-10-02)")
            && str_contains($request->prompt, 'Estado consolidado: On Track. Resumen ejecutivo: Avanza la web Snapshot de satisfacción semanal: 64%. Próximos pasos: Revisar la home. Hitos: 2026-10-15 Lanzamiento. Etiquetas: web.')
            && str_contains($request->prompt, 'Estado consolidado: On Track. Resumen ejecutivo: Avanza la web')
            && str_contains($request->prompt, '- Ana Díaz: Maquetación de la home con el equipo')
            && str_contains($request->prompt, 'HISTÓRICO RELEVANTE (últimas 2 weeklys con actividad):');
    });
});

it('sin weeklies del cliente no llama a la IA y lo dice', function () {
    $llm = FakeLlm::bind();

    $summary = app(AiSummaries::class)->request(AiSummaryKind::ClientSummary, $this->acme, $this->ana)->refresh();

    expect($summary->state)->toBe(WeeklyJobState::Done)
        ->and($summary->content)->toBe(InsightPrompts::INSUFFICIENT_CLIENT_HISTORY);
    $llm->assertNothingSent();
});

it('el análisis del equipo da una frase por persona y rellena las que falten', function () {
    $llm = FakeLlm::bind()->push(['summaries' => [
        ['memberId' => (string) $this->ana->id, 'summary' => 'Ana maqueta la home.'],
        ['memberId' => '99999', 'summary' => 'Inventada'],
    ]]);
    $carlos = userWithRole('employee', ['name' => 'Carlos']);
    $this->web->addMember($carlos->id);
    insightEntry($this->closed, $this->ana, $this->acme, 'Maquetación', '2026-10-02 17:00');
    insightEntry($this->closed, $this->owner, $this->acme, 'Reunión de seguimiento', '2026-10-02 18:00');

    $summary = app(AiSummaries::class)->request(AiSummaryKind::ClientTeamActivity, $this->acme, $this->owner)->refresh();

    expect($summary->items)->toBe([
        (string) $this->ana->id => 'Ana maqueta la home.',
        (string) $carlos->id => InsightPrompts::NO_ACTIVITY,
        (string) $this->owner->id => InsightPrompts::ACTIVITY_NOT_SUMMARIZED,
    ]);
    $llm->assertSent(fn (LlmRequest $request): bool => $request->feature === AiFeature::TeamActivity
        && $request->responseSchema !== null
        && str_contains($request->prompt, 'Genera un resumen corto de actividad por miembro para el cliente "Acme".')
        && str_contains($request->prompt, '{"memberId":"'.$this->ana->id.'","memberName":"Ana Díaz","reports":[{"week":"'.$this->closed->label.'","text":"Maquetación"}]}'));
});

it('si la IA falla, el resumen queda con el error y se puede volver a pedir', function () {
    FakeLlm::bind()->push(new LlmUnavailable('caída'));
    insightEntry($this->closed, $this->ana, $this->acme, 'Maquetación', '2026-10-02 17:00');

    $summary = app(AiSummaries::class)->request(AiSummaryKind::ClientSummary, $this->acme, $this->ana)->refresh();

    expect($summary->state)->toBe(WeeklyJobState::Failed)
        ->and($summary->error)->toBe(__('weeklies.errors.llm_unavailable'))
        ->and(AiSummaries::isBusy($summary))->toBeFalse();
});

it('cada llamada queda en ai_usage con quien la pidió y el cliente (GeminiClient)', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '## Estado actual']], 'role' => 'model'], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 100, 'totalTokenCount' => 1000],
    ])]);
    app()->instance(LlmClient::class, new GeminiClient(app(AiUsageRecorder::class), 'clave-de-prueba', 'gemini-2.5-flash', 'https://generativelanguage.googleapis.com/v1beta', 30, 3));
    insightEntry($this->closed, $this->ana, $this->acme, 'Maquetación', '2026-10-02 17:00');

    app(AiSummaries::class)->request(AiSummaryKind::ClientSummary, $this->acme, $this->ana);

    $usage = AiUsage::query()->sole();
    expect($usage->feature)->toBe(AiFeature::ClientSummary)
        ->and($usage->user_id)->toBe($this->ana->id)
        ->and($usage->subject_id)->toBe($this->acme->id)
        ->and($usage->operation)->toBe('client_summary')
        ->and($usage->status)->toBe(AiUsage::STATUS_SUCCESS);
});

it('la IA de prueba (GEMINI_DRIVER=fake) responde a los resúmenes con una frase por id', function () {
    $response = FakeLlm::demo()->generate(new LlmRequest(
        feature: AiFeature::TeamActivity,
        prompt: InsightPrompts::teamActivity('Acme', [['memberId' => '7', 'memberName' => 'Ana', 'reports' => []]]),
        responseSchema: InsightPrompts::summariesSchema('memberId'),
    ));

    expect($response->json['summaries'][0]['memberId'])->toBe('7')
        ->and($response->json['summaries'][0]['summary'])->toContain('GEMINI_DRIVER=fake');
});

it('el resumen y el análisis los pide la plantilla; un colaborador externo no; sin el módulo, 404', function () {
    Queue::fake();

    $this->actingAs(userWithRole('employee'))->postJson("/clientes/{$this->acme->id}/actividad-ia")->assertStatus(202);
    $this->actingAs(User::factory()->collaborator()->create())->postJson("/clientes/{$this->acme->id}/resumen-ia")->assertForbidden();

    Setting::set('modules', ['weeklies' => false]);
    $this->actingAs(userWithRole('admin'))->postJson("/clientes/{$this->acme->id}/resumen-ia")->assertNotFound();
});

// --- Rendimiento ------------------------------------------------------------------------------

it('la cartera y las pestañas de la ficha no crecen con el histórico ni con el equipo', function () {
    $grow = function (int $people): void {
        foreach (range(1, $people) as $i) {
            $user = userWithRole('employee', ['created_at' => '2026-08-01']);
            $this->web->addMember($user->id);
            $client = Client::factory()->create();
            Project::factory()->withMembers([$user])->create(['client_id' => $client->id, 'billing_type' => BillingType::HourBank]);
            insightEntry($this->closed, $user, $this->acme, "Apunte {$i}", '2026-10-02 10:00');
            insightEntry($this->older, $user, $client, "Otro {$i}", '2026-09-25 10:00');
            ClientSatisfactionSnapshot::factory()->create(['client_id' => $client->id, 'weekly_cycle_id' => $this->closed->id]);
        }

        insightReport($this->closed, $this->acme, 'Avanza', 64);
    };
    $measure = function (string $uri, ?string $deferred = null): int {
        $headers = $deferred === null ? [] : ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'clients/show', 'X-Inertia-Partial-Data' => $deferred, 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request())];
        $this->actingAs($this->owner)->get($uri, $headers)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get($uri, $headers)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $pages = [
        // El responsable y el equipo de cada fila (D-232) caben en el margen que había: +1.
        'cartera' => ['/clientes?orden=ultimo_reporte', null, 13],
        'ficha' => ["/clientes/{$this->acme->id}", null, 18],
        'resumen' => ["/clientes/{$this->acme->id}", 'weekly', 14],
        'historial' => ["/clientes/{$this->acme->id}?pestana=historial", 'weekly', 14],
        // +2 con D-221: quién se ha unido al cliente en la Weekly y si me he unido yo.
        'equipo' => ["/clientes/{$this->acme->id}?pestana=equipo", 'weekly', 20],
        'satisfacción' => ["/clientes/{$this->acme->id}?pestana=satisfaccion", 'weekly', 12],
    ];

    $grow(3);
    $small = array_map(fn (array $page): int => $measure($page[0], $page[1]), $pages);
    $grow(12);

    foreach ($pages as $label => [$uri, $deferred, $budget]) {
        $large = $measure($uri, $deferred);

        expect($large)->toBeLessThanOrEqual($budget, "{$label}: {$large} consultas")
            ->and($large - $small[$label])->toBeLessThanOrEqual(0, "{$label} crece");
    }
});
