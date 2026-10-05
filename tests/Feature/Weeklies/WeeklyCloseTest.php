<?php

use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\Satisfaction\SatisfactionUpdater;
use App\Domain\Weeklies\WeeklyCycleCloser;
use App\Enums\AbsenceStatus;
use App\Enums\AiFeature;
use App\Enums\WeeklyCycleStatus;
use App\Enums\WeeklyExemptionReason;
use App\Events\Weeklies\WeeklyCycleClosed;
use App\Jobs\UpdateWeeklySatisfaction;
use App\Models\Absence;
use App\Models\Client;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\User;
use App\Models\WeeklyAudioSection;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Cerrar la semana (10.3, F-035, F-070, F-089, F-092 a F-095, D-191): con el texto y el audio, congela
| la participación, abre la siguiente y, en segundo plano, la satisfacción estabilizada y el evento
| del aviso «weekly cerrada».
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00', 'Europe/Madrid'));
    $this->manager = userWithRole('department_manager');
    $this->cycle = WeeklyCycle::factory()->active()->create();
});

/** Deja la semana lista para cerrar: con informe y con audio. */
function closeReady(WeeklyCycle $cycle, array $updates = []): WeeklyCycle
{
    $cycle->forceFill([
        'report' => ['global_summary' => 'Resumen', 'team_risks' => [], 'client_updates' => $updates],
        'report_text' => 'Texto',
        'audio_disk' => 'local',
        'audio_path' => "weeklies/{$cycle->id}/audio/weekly.mp3",
    ])->save();

    return $cycle;
}

function closeEntry(WeeklyCycle $cycle, Client $client, string $body, ?string $at = null, ?User $user = null): void
{
    $submission = WeeklySubmission::query()->firstOrCreate(
        ['weekly_cycle_id' => $cycle->id, 'user_id' => ($user ?? userWithRole('employee'))->id],
        ['submitted_at' => $at ?? now(), 'draft_saved_at' => now()],
    );
    WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'client_id' => $client->id, 'body' => $body]);
}

it('no se cierra sin el texto y el audio; quien falte por enviar no lo impide (F-089)', function () {
    Queue::fake();
    userWithRole('employee'); // pendiente

    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/cerrar")
        ->assertJsonValidationErrors(['cycle' => ['Falta generar el texto del informe.', 'Falta generar el audio del informe.']]);

    $this->cycle->forceFill(['report' => ['global_summary' => 'X', 'team_risks' => [], 'client_updates' => []]])->save();
    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/cerrar")->assertJsonValidationErrors(['cycle' => ['Falta generar el audio del informe.']]);

    // Basta con una sección locutada.
    WeeklyAudioSection::query()->create(['weekly_cycle_id' => $this->cycle->id, 'key' => 'intro', 'kind' => 'intro', 'position' => 0, 'disk' => 'local', 'path' => 'x.mp3']);
    $this->actingAs($this->manager)->post("/weeklies/{$this->cycle->id}/cerrar")->assertRedirect()->assertSessionHasNoErrors();

    expect($this->cycle->refresh()->status)->toBe(WeeklyCycleStatus::Closed);
});

it('al cerrar: congela quién debía enviar y los exentos, marca quién cerró y abre la siguiente (F-070 y F-092)', function () {
    Queue::fake();
    $elena = userWithRole('employee');
    $away = userWithRole('employee');
    Absence::factory()->create(['user_id' => $away->id, 'status' => AbsenceStatus::Approved, 'start_date' => '2026-10-08', 'end_date' => '2026-10-12', 'partial_minutes' => null]);
    closeReady($this->cycle);

    $this->actingAs($this->manager)->post("/weeklies/{$this->cycle->id}/cerrar")
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', fn (array $toast) => str_contains($toast['message'], 'Se ha abierto la siguiente: Semana 42'));

    $cycle = $this->cycle->refresh();
    $next = WeeklyCycle::query()->active()->sole();

    expect($cycle->status)->toBe(WeeklyCycleStatus::Closed)
        ->and($cycle->closed_by)->toBe($this->manager->id)
        ->and($cycle->closed_at)->not->toBeNull()
        ->and($cycle->expected_user_ids)->toContain($elena->id)
        ->and($cycle->expected_user_ids)->not->toContain($away->id)
        ->and($cycle->exemptions()->where('user_id', $away->id)->sole()->reason)->toBe(WeeklyExemptionReason::Absence)
        ->and($next->number)->toBe('W42-26')
        ->and($next->start_date->toDateString())->toBe('2026-10-12')
        ->and(MyWeeklyStatus::activeSnapshot()['id'])->toBe($next->id);

    Queue::assertPushedOn('ai', UpdateWeeklySatisfaction::class, fn (UpdateWeeklySatisfaction $job) => $job->cycleId === $cycle->id && $job->userId === $this->manager->id && $job->nextCycleId === $next->id);

    // Ya cerrada, no se vuelve a cerrar.
    $this->actingAs($this->manager)->postJson("/weeklies/{$cycle->id}/cerrar")->assertForbidden();
});

it('solo cierra quien gestiona la weekly', function () {
    closeReady($this->cycle);

    $this->actingAs(userWithRole('employee'))->postJson("/weeklies/{$this->cycle->id}/cerrar")->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())->postJson("/weeklies/{$this->cycle->id}/cerrar")->assertForbidden();
    expect($this->cycle->refresh()->isActive())->toBeTrue();
});

it('la página del informe dice qué falta para cerrar y cuántos faltan por enviar', function () {
    userWithRole('employee');

    $this->actingAs($this->manager)->get("/weeklies/{$this->cycle->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('weeklies/show')
            ->where('close.blockers', ['report', 'audio'])
            ->where('close.pending', fn (int $pending) => $pending >= 1)
            ->where('can.close', true)
            ->where('can.edit', true)
            ->where('report_request.kind', 'weekly')
            ->where('report_request.route_params.cycle', $this->cycle->id)
            ->has('team.members')
            ->has('my_client_ids'));
});

it('satisfacción: Gemini propone, el estabilizador amortigua y queda la foto en el cliente, el histórico y el informe', function () {
    $acme = Client::factory()->create(['name' => 'Acme', 'satisfaction_score' => 70]);
    $beta = Client::factory()->create(['name' => 'Beta', 'satisfaction_score' => 40]);
    $quiet = Client::factory()->create(['name' => 'Silencio', 'satisfaction_score' => 55]);
    $text = 'El cliente nos ha felicitado por la entrega y está muy contento con el resultado. Quiere ampliar el proyecto el mes que viene porque valora mucho el trabajo del equipo.';
    closeEntry($this->cycle, $acme, $text);
    closeEntry($this->cycle, $beta, 'Seguimos con las tareas de la semana.');
    $previous = WeeklyCycle::factory()->create(['label' => 'Semana 40']);
    closeEntry($previous, $acme, 'Semana anterior tranquila.', '2026-10-02 10:00:00');
    closeReady($this->cycle, [
        ['client_id' => $acme->id, 'client_name' => 'Acme', 'status' => 'on_track', 'executive_summary' => 'x'],
        ['client_id' => $quiet->id, 'client_name' => 'Silencio', 'status' => 'on_track', 'executive_summary' => 'y'],
    ]);
    $this->cycle->forceFill(['status' => WeeklyCycleStatus::Closed])->save();

    $llm = FakeLlm::bind()->respondUsing(fn (LlmRequest $r): array => str_contains($r->prompt, 'CLIENTE: Acme')
        ? ['recommendedDelta' => 6, 'sentiment' => 'MUY_POSITIVO', 'evidenceLevel' => 'HIGH', 'explicitClientImpact' => true, 'confidence' => 0.9, 'reasoning' => 'Felicitación explícita.']
        : ['recommendedDelta' => 3, 'sentiment' => 'NEUTRAL', 'evidenceLevel' => 'NONE', 'explicitClientImpact' => false, 'confidence' => 0.4, 'reasoning' => 'Rutina.']);

    $scores = app(SatisfactionUpdater::class)->update($this->cycle);

    $snapshot = ClientSatisfactionSnapshot::query()->where('client_id', $acme->id)->sole();
    expect($scores)->toHaveKeys([$acme->id, $beta->id])
        ->and($snapshot->previous_score)->toBe(70)
        ->and($snapshot->requested_delta)->toBe(6)
        ->and($snapshot->score)->toBe(70 + $snapshot->delta)
        ->and($snapshot->delta)->toBeGreaterThan(0)
        ->and($snapshot->evidence_level)->toBe('HIGH')
        ->and($snapshot->explicit_client_impact)->toBeTrue()
        ->and($snapshot->model)->toBe('fake-gemini')
        ->and($snapshot->metrics)->toHaveKey('wordCount')
        ->and($acme->refresh()->satisfaction_score)->toBe($snapshot->score)
        // Beta: sin impacto explícito, no se mueve.
        ->and(ClientSatisfactionSnapshot::query()->where('client_id', $beta->id)->sole()->delta)->toBe(0)
        ->and($beta->refresh()->satisfaction_score)->toBe(40)
        ->and(ClientSatisfactionSnapshot::query()->where('client_id', $quiet->id)->exists())->toBeFalse();

    $report = $this->cycle->refresh()->reportData();
    expect($report->clientUpdate($acme->id)->satisfactionScore)->toBe($snapshot->score)
        ->and($report->clientUpdate($quiet->id)->satisfactionScore)->toBeNull();

    $llm->assertSent(fn (LlmRequest $r) => $r->feature === AiFeature::Satisfaction
        && str_contains($r->prompt, "CLIENTE: Acme\nSATISFACCIÓN ACTUAL: 70% (escala 0-100)")
        && str_contains($r->prompt, "REPORTE CONSOLIDADO DE ESTA SEMANA:\n{$text}")
        && str_contains($r->prompt, "HISTORIAL RECIENTE (últimas 5 semanas):\n[Semana 40]: Semana anterior tranquila.")
        && $r->responseSchema !== null && $r->subject?->is($acme));
    $llm->assertSent(fn (LlmRequest $r) => str_contains($r->prompt, 'CLIENTE: Beta') && str_contains($r->prompt, "HISTORIAL RECIENTE (últimas 5 semanas):\nSin historial previo"));

    // Idempotente: otra pasada no vuelve a mover a nadie ni llama a la IA.
    $count = count($llm->requests());
    app(SatisfactionUpdater::class)->update($this->cycle);
    expect(count($llm->requests()))->toBe($count)
        ->and($acme->refresh()->satisfaction_score)->toBe($snapshot->score);
});

it('la satisfacción da lo mismo que el original en los casos del estabilizador', function (array $case) {
    $input = $case['input'];
    $client = Client::factory()->create(['satisfaction_score' => $input['currentSatisfaction']]);
    closeEntry($this->cycle, $client, $input['reportText']);
    $this->cycle->forceFill(['status' => WeeklyCycleStatus::Closed])->save();
    FakeLlm::bind()->push([
        'recommendedDelta' => $input['requestedDelta'],
        'sentiment' => 'NEUTRAL',
        'evidenceLevel' => $input['evidenceLevel'],
        'explicitClientImpact' => $input['explicitClientImpact'],
        'confidence' => $input['confidence'],
        'reasoning' => 'Motivo.',
    ]);

    app(SatisfactionUpdater::class)->update($this->cycle);
    $snapshot = ClientSatisfactionSnapshot::query()->where('client_id', $client->id)->sole();

    expect($snapshot->delta)->toBe($case['expected']['finalDelta'], $case['name'])
        ->and($snapshot->rule)->toBe($case['expected']['rule'])
        ->and($snapshot->score)->toBe(max(0, min(100, $input['currentSatisfaction'] + $case['expected']['finalDelta'])));
})->with(function (): array {
    $cases = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/weeklies/satisfaction-cases.json'), true)['stabilize'];

    return collect($cases)
        ->filter(fn (array $case): bool => is_int($case['input']['currentSatisfaction'] ?? null) && $case['input']['currentSatisfaction'] > 0
            && is_numeric($case['input']['requestedDelta'] ?? null) && is_string($case['input']['reportText'] ?? null) && trim($case['input']['reportText']) !== ''
            && in_array($case['input']['evidenceLevel'] ?? null, ['NONE', 'LOW', 'MEDIUM', 'HIGH'], true)
            && is_bool($case['input']['explicitClientImpact'] ?? null) && is_numeric($case['input']['confidence'] ?? null))
        ->mapWithKeys(fn (array $case): array => [$case['name'] => [$case]])
        ->all();
});

it('si la IA falla con un cliente, ese no cambia y los demás sí', function () {
    $acme = Client::factory()->create(['name' => 'Acme', 'satisfaction_score' => 60]);
    $beta = Client::factory()->create(['name' => 'Beta', 'satisfaction_score' => 60]);
    closeEntry($this->cycle, $acme, 'Texto de Acme.');
    closeEntry($this->cycle, $beta, 'Texto de Beta.');
    $this->cycle->forceFill(['status' => WeeklyCycleStatus::Closed])->save();
    FakeLlm::bind()->push(new LlmUnavailable('caída'), ['recommendedDelta' => 0, 'sentiment' => 'NEUTRAL', 'evidenceLevel' => 'NONE', 'explicitClientImpact' => false, 'confidence' => 0.5, 'reasoning' => '']);

    app(SatisfactionUpdater::class)->update($this->cycle);

    expect(ClientSatisfactionSnapshot::query()->pluck('client_id')->all())->toBe([$beta->id])
        ->and(ClientSatisfactionSnapshot::query()->sole()->reasoning)->toBe('La información disponible no justifica cambiar el índice de satisfacción esta semana.');
});

it('el Job de después del cierre calcula la satisfacción y lanza el evento del aviso, aunque la IA falle', function () {
    Event::fake([WeeklyCycleClosed::class]);
    $acme = Client::factory()->create();
    closeEntry($this->cycle, $acme, 'Texto.');
    $this->cycle->forceFill(['status' => WeeklyCycleStatus::Closed])->save();
    FakeLlm::bind()->push(new LlmUnavailable('caída'));

    (new UpdateWeeklySatisfaction($this->cycle->id, $this->manager->id, 99))->handle(app(SatisfactionUpdater::class));

    Event::assertDispatched(WeeklyCycleClosed::class, fn (WeeklyCycleClosed $event) => $event->cycleId === $this->cycle->id && $event->closedBy === $this->manager->id && $event->nextCycleId === 99);

    // Con la semana aún activa no hace nada.
    Event::fake([WeeklyCycleClosed::class]);
    $active = WeeklyCycle::factory()->active('2026-10-12')->create();
    (new UpdateWeeklySatisfaction($active->id))->handle(app(SatisfactionUpdater::class));
    Event::assertNotDispatched(WeeklyCycleClosed::class);
});

it('cerrar de punta a punta: el Job va por la cola y el evento sale al terminar', function () {
    Event::fake([WeeklyCycleClosed::class]);
    $acme = Client::factory()->create(['satisfaction_score' => 50]);
    closeEntry($this->cycle, $acme, 'Rutina de la semana.');
    closeReady($this->cycle, [['client_id' => $acme->id, 'client_name' => $acme->name, 'status' => 'on_track', 'executive_summary' => 'x']]);
    FakeLlm::bind()->push(['recommendedDelta' => 0, 'sentiment' => 'NEUTRAL', 'evidenceLevel' => 'NONE', 'explicitClientImpact' => false, 'confidence' => 0.5, 'reasoning' => 'Rutina.']);

    $next = app(WeeklyCycleCloser::class)->close($this->cycle, $this->manager);

    expect($next->isActive())->toBeTrue()
        ->and(ClientSatisfactionSnapshot::query()->where('client_id', $acme->id)->sole()->score)->toBe(50)
        ->and($this->cycle->refresh()->reportData()->clientUpdate($acme->id)->satisfactionScore)->toBe(50);
    Event::assertDispatched(WeeklyCycleClosed::class, fn (WeeklyCycleClosed $event) => $event->nextCycleId === $next->id);
});
