<?php

use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmNotConfigured;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\LlmWeeklyReportGenerator;
use App\Domain\Weeklies\Report\ReportPipeline;
use App\Domain\Weeklies\Report\ReportSchemas;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Domain\Weeklies\WeeklyJobProgress;
use App\Domain\Weeklies\WeeklyReportGenerator;
use App\Enums\AiFeature;
use App\Enums\BillingType;
use App\Enums\WeeklyClientStatus;
use App\Enums\WeeklyJobState;
use App\Events\Weeklies\WeeklyGenerationUpdated;
use App\Jobs\GenerateWeeklyReport;
use App\Models\Client;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
| El informe con Gemini (10.3, F-072 a F-077, D-188 a D-190): el pipeline portado con FakeLlm, el
| estado de los proyectos con los datos de Audax, el Job de la cola `ai` con su progreso, el
| «desactualizado», la edición a mano y quién puede hacer qué.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active()->create();
    $this->manager = userWithRole('department_manager');
});

/** Un proyecto de bolsa del cliente con su bolsa y el consumo indicado (por HourBankLedger). */
function reportBankProject(Client $client, int $totalMinutes, int $consumedMinutes, string $code = 'ACME-BH1', string $date = '2026-10-06'): Project
{
    $project = Project::factory()->hourBank()->create(['client_id' => $client->id, 'code' => $code, 'name' => 'Bolsa web']);
    $bank = HourBank::factory()->create(['project_id' => $project->id, 'total_minutes' => $totalMinutes, 'name' => 'BH1', 'start_date' => '2026-09-01']);
    $task = Task::factory()->create(['project_id' => $project->id, 'hour_bank_id' => $bank->id]);

    if ($consumedMinutes > 0) {
        TimeEntry::factory()->forTask($task)->minutes($consumedMinutes)->on($date)->create();
    }

    app(HourBankLedger::class)->recalculate($bank);

    return $project;
}

/** Una weekly enviada con un apunte por cliente (null = General). */
function reportSubmission(WeeklyCycle $cycle, array $entries, string $at = '2026-10-07 10:00', ?User $user = null): WeeklySubmission
{
    $submission = WeeklySubmission::factory()->submitted(CarbonImmutable::parse($at, 'Europe/Madrid')->utc()->toDateTimeString())->create([
        'weekly_cycle_id' => $cycle->id,
        'user_id' => ($user ?? userWithRole('employee'))->id,
    ]);

    foreach (array_values($entries) as $position => [$clientId, $body]) {
        WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'client_id' => $clientId, 'body' => $body, 'position' => $position]);
    }

    return $submission;
}

/** Respuestas de la IA según la operación: el resumen de cada cliente lleva su nombre. */
function reportFakeLlm(): FakeLlm
{
    return FakeLlm::bind()->respondUsing(function (LlmRequest $request): array {
        if ($request->operation === 'global_summary') {
            return ['globalSummary' => 'Semana con avances y una bolsa en riesgo.', 'teamRisks' => ['Bolsa de Acme casi agotada', 'bolsa de acme casi agotada']];
        }

        preg_match('/CLIENTE:\n(.+)\n/', $request->prompt, $match);

        return [
            'clientName' => $match[1] ?? '¿?',
            'executiveSummary' => 'Resumen de '.($match[1] ?? '¿?').'.',
            'status' => 'Risk',
            'nextSteps' => ['Revisar con el cliente'],
            'milestones' => [['date' => '12/10', 'label' => 'Entrega']],
            'tags' => ['Web'],
        ];
    });
}

it('genera el informe por cliente: los activos, los que tienen apuntes y «General / Interno» al final', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    $beta = Client::factory()->create(['name' => 'Beta']);
    $old = Client::factory()->inactive()->create(['name' => 'Antiguo']);
    Client::factory()->inactive()->create(['name' => 'Olvidado']);
    reportBankProject($acme, 1200, 1080);
    reportSubmission($this->cycle, [[$acme->id, 'Hemos entregado la home.'], [null, 'Formación interna.']]);
    reportSubmission($this->cycle, [[$old->id, 'Cierre de la cuenta.']], '2026-10-07 11:00');
    WeeklySubmission::factory()->create(['weekly_cycle_id' => $this->cycle->id]); // borrador: no cuenta
    $llm = reportFakeLlm();

    $result = app(WeeklyReportGenerator::class)->generate($this->cycle, $this->manager);
    $report = $result->report;

    expect(array_map(fn ($update) => $update->clientName, $report->clientUpdates))->toBe(['Acme', 'Antiguo', 'Beta', 'General / Interno'])
        ->and($report->globalSummary)->toBe('Semana con avances y una bolsa en riesgo.')
        ->and($report->teamRisks)->toBe(['Bolsa de Acme casi agotada'])
        ->and($result->submissionCount)->toBe(2)
        ->and($result->model)->toBe('fake-gemini');

    [$acmeUpdate, , $betaUpdate, $general] = $report->clientUpdates;
    expect($acmeUpdate->status)->toBe(WeeklyClientStatus::Risk)
        ->and($acmeUpdate->executiveSummary)->toBe('Resumen de Acme.')
        ->and($acmeUpdate->hasReports)->toBeTrue()
        ->and($acmeUpdate->projects)->toHaveCount(1)
        ->and($acmeUpdate->projects[0]->budgetMinutes)->toBe(1200)
        ->and($acmeUpdate->projects[0]->consumedMinutes)->toBe(1080)
        ->and($acmeUpdate->projects[0]->weekMinutes)->toBe(1080)
        ->and($betaUpdate->executiveSummary)->toBe(ReportPipeline::NO_REPORT_SUMMARY)
        ->and($betaUpdate->hasReports)->toBeFalse()
        ->and($general->clientId)->toBeNull()
        ->and($general->executiveSummary)->toBe('Resumen de General / Interno.');

    // Una llamada por cliente con apuntes (Acme, Antiguo y General) y el resumen global.
    $llm->assertSentCount(4);
    $llm->assertSent(fn (LlmRequest $r) => $r->feature === AiFeature::WeeklyReport && $r->operation === 'client_batch'
        && str_contains($r->prompt, 'ACME-BH1 (Bolsa de horas): 18/20h, estado EN RIESGO; esta semana 18h.')
        && str_contains($r->prompt, 'Autor: ') && $r->responseSchema !== null && $r->subject?->is($this->cycle) && $r->user?->is($this->manager));
    $llm->assertSent(fn (LlmRequest $r) => $r->operation === 'global_summary' && str_contains($r->prompt, 'Cliente: Beta') && str_contains($r->prompt, 'Resumen: Sin novedades reportadas esta semana.'));

    expect($result->text)->toContain("# {$this->cycle->label}")
        ->toContain('## Resumen Global')
        ->toContain('### Acme - En riesgo')
        ->toContain('**Siguientes pasos:**')
        ->toContain('- 12/10: Entrega');
});

it('un cliente sin reportes no pide su resumen a la IA y su estado sale de su bolsa (F-075)', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    $beta = Client::factory()->create(['name' => 'Beta']);
    reportBankProject($acme, 600, 660);
    $llm = FakeLlm::bind()->push(['globalSummary' => 'Acme está excedida.', 'teamRisks' => []]);

    $report = app(WeeklyReportGenerator::class)->generate($this->cycle)->report;

    expect($report->clientUpdates[0]->status)->toBe(WeeklyClientStatus::Blocked)
        ->and($report->clientUpdates[0]->executiveSummary)->toBe(ReportPipeline::NO_REPORT_SUMMARY.' Aun sin actividad reportada, el proyecto ACME-BH1 (Bolsa de horas) está excedido o claramente por encima de lo esperado y requiere seguimiento inmediato.')
        ->and($report->clientUpdates[1]->status)->toBe(WeeklyClientStatus::OnTrack)
        ->and($report->globalSummary)->toBe('Acme está excedida.');

    // Como en el original, la nota de la bolsa cuenta como «actividad»: solo se pide el resumen global.
    $llm->assertSentCount(1);
    $llm->assertSent(fn (LlmRequest $r) => $r->operation === 'global_summary');
});

it('si nadie ha escrito y no hay alertas, el resumen global sale sin IA', function () {
    Client::factory()->create(['name' => 'Acme']);
    $llm = FakeLlm::bind();

    $report = app(WeeklyReportGenerator::class)->generate($this->cycle)->report;

    $llm->assertNothingSent();
    expect($report->globalSummary)->toBe('No se han reportado novedades relevantes en los clientes activos esta semana.')
        ->and($report->clientUpdates[0]->executiveSummary)->toBe(ReportPipeline::NO_REPORT_SUMMARY);
});

it('reparte en lotes de 9.000 caracteres, los fusiona y reescribe en español lo que llega en inglés', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    foreach (range(1, 3) as $i) {
        reportSubmission($this->cycle, [[$acme->id, str_repeat("Avance {$i}. ", 400)]], "2026-10-0{$i} 10:00");
    }

    $llm = FakeLlm::bind()->respondUsing(fn (LlmRequest $r): array => match ($r->operation) {
        'client_batch' => ['clientName' => 'Acme', 'executiveSummary' => 'Parcial', 'status' => 'On Track', 'nextSteps' => [], 'milestones' => [], 'tags' => []],
        'client_merge' => ['clientName' => 'Acme', 'executiveSummary' => 'The team delivered the website and the client sent feedback on this design.', 'status' => 'Blocked', 'nextSteps' => ['Send the review'], 'milestones' => [], 'tags' => []],
        'translate_client' => ['clientName' => 'Acme', 'executiveSummary' => 'El equipo entregó la web y el cliente envió feedback del diseño.', 'status' => 'Blocked', 'nextSteps' => ['Enviar la revisión'], 'milestones' => [], 'tags' => []],
        'global_summary' => ['globalSummary' => 'Semana difícil.', 'teamRisks' => []],
        default => throw new RuntimeException($r->operation),
    });

    $update = app(WeeklyReportGenerator::class)->generate($this->cycle)->report->clientUpdates[0];

    expect($update->executiveSummary)->toBe('El equipo entregó la web y el cliente envió feedback del diseño.')
        ->and($update->status)->toBe(WeeklyClientStatus::Blocked)
        ->and($update->nextSteps)->toBe(['Enviar la revisión']);

    $operations = array_map(fn (LlmRequest $r): string => $r->operation, $llm->requests());
    expect($operations)->toBe(['client_batch', 'client_batch', 'client_merge', 'translate_client', 'global_summary']);
    $llm->assertSent(fn (LlmRequest $r) => $r->operation === 'client_batch' && str_contains($r->prompt, 'Este es el lote 2 de 2 para este cliente.'));
});

it('si la IA falla con un cliente, su resumen sale de los apuntes y el informe sigue', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    $beta = Client::factory()->create(['name' => 'Beta']);
    reportSubmission($this->cycle, [[$acme->id, 'Primera frase. Segunda frase.'], [$beta->id, 'Todo en orden.']]);
    FakeLlm::bind()->respondUsing(function (LlmRequest $r): array {
        if (str_contains($r->prompt, "CLIENTE:\nAcme")) {
            throw new LlmUnavailable('caída');
        }

        return $r->operation === 'global_summary'
            ? ['globalSummary' => 'Resumen.', 'teamRisks' => []]
            : ['clientName' => 'Beta', 'executiveSummary' => 'Beta bien.', 'status' => 'On Track', 'nextSteps' => [], 'milestones' => [], 'tags' => []];
    });

    $report = app(WeeklyReportGenerator::class)->generate($this->cycle)->report;

    expect($report->clientUpdates[0]->executiveSummary)->toBe("Se ha reportado actividad en Acme durante la semana.\n- Primera frase.\n- Segunda frase.")
        ->and($report->clientUpdates[1]->executiveSummary)->toBe('Beta bien.');
});

it('sin clave de Gemini no sigue: el error es de configuración', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    reportSubmission($this->cycle, [[$acme->id, 'Texto.']]);
    FakeLlm::bind()->push(new LlmNotConfigured('sin clave'));

    expect(fn () => app(WeeklyReportGenerator::class)->generate($this->cycle))->toThrow(LlmNotConfigured::class);
});

it('el Job guarda el informe, el texto y cuántos envíos había, y emite el progreso', function () {
    Event::fake([WeeklyGenerationUpdated::class]);
    $acme = Client::factory()->create(['name' => 'Acme']);
    reportSubmission($this->cycle, [[$acme->id, 'Texto.']]);
    reportFakeLlm();
    $this->cycle->forceFill(['report_edited_at' => now(), 'report_edited_by' => $this->manager->id])->save();

    (new GenerateWeeklyReport($this->cycle->id, $this->manager->id))->handle(app(WeeklyReportGenerator::class));
    $cycle = $this->cycle->refresh();

    expect($cycle->report_state)->toBe(WeeklyJobState::Done)
        ->and($cycle->reportData()->clientUpdates[0]->clientName)->toBe('Acme')
        ->and($cycle->report_text)->toContain('### Acme')
        ->and($cycle->submission_count_at_generation)->toBe(1)
        ->and($cycle->report_generated_by)->toBe($this->manager->id)
        ->and($cycle->report_edited_at)->toBeNull()
        ->and(WeeklyJobProgress::detail($cycle->id, 'report'))->toBeNull();

    Event::assertDispatched(WeeklyGenerationUpdated::class, fn (WeeklyGenerationUpdated $e) => $e->state === 'running' && $e->step === 'clients' && $e->done === 1 && $e->total === 1
        && $e->broadcastOn()[0]->name === "private-weeklies.{$cycle->id}" && $e->broadcastAs() === 'weekly.progress');
    Event::assertDispatched(WeeklyGenerationUpdated::class, fn (WeeklyGenerationUpdated $e) => $e->state === 'done' && $e->kind === 'report');
});

it('si el Job falla, la semana queda con el error y el informe anterior no se toca', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    reportSubmission($this->cycle, [[$acme->id, 'Texto.']]);
    $this->cycle->forceFill(['report' => ['global_summary' => 'Anterior', 'team_risks' => [], 'client_updates' => []]])->save();
    FakeLlm::bind()->push(['clientName' => 'Acme', 'executiveSummary' => 'Ok', 'status' => 'On Track', 'nextSteps' => [], 'milestones' => [], 'tags' => []], new LlmUnavailable('caída'));

    (new GenerateWeeklyReport($this->cycle->id))->handle(app(WeeklyReportGenerator::class));
    $cycle = $this->cycle->refresh();

    expect($cycle->report_state)->toBe(WeeklyJobState::Failed)
        ->and($cycle->report_error)->toBe('La IA no responde ahora mismo. Inténtalo más tarde.')
        ->and($cycle->reportData()->globalSummary)->toBe('Anterior');

    // Si el worker lo corta (tiempo agotado), tampoco se queda «generando».
    $cycle->forceFill(['report_state' => WeeklyJobState::Running])->save();
    (new GenerateWeeklyReport($cycle->id))->failed(new RuntimeException('timeout'));
    expect($cycle->refresh()->report_state)->toBe(WeeklyJobState::Failed)
        ->and($cycle->report_error)->toBe('No se ha podido generar el informe. Inténtalo de nuevo.');
});

it('generar encola el Job en la cola ai y responde enseguida; no con la semana cerrada ni dos a la vez', function () {
    Queue::fake();

    $this->actingAs($this->manager)->post("/weeklies/{$this->cycle->id}/informe")->assertRedirect();
    Queue::assertPushedOn('ai', GenerateWeeklyReport::class, fn (GenerateWeeklyReport $job) => $job->cycleId === $this->cycle->id && $job->userId === $this->manager->id);
    expect($this->cycle->refresh()->report_state)->toBe(WeeklyJobState::Queued);

    // Ya en marcha: no se encola otro.
    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/informe")->assertJsonValidationErrors(['report' => 'El informe ya se está generando.']);

    // Atascado (más de 12 minutos sin cambios): se puede volver a pedir.
    $this->travel(13)->minutes();
    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/informe")->assertStatus(202)->assertJsonPath('report_state', 'queued');
    Queue::assertPushed(GenerateWeeklyReport::class, 2);

    $closed = WeeklyCycle::factory()->create();
    $this->actingAs($this->manager)->postJson("/weeklies/{$closed->id}/informe")->assertJsonValidationErrors(['report' => 'La semana está cerrada: su texto ya no se regenera.']);
    $this->actingAs(userWithRole('employee'))->postJson("/weeklies/{$this->cycle->id}/informe")->assertForbidden();
});

it('el estado del informe dice si está desactualizado: hay más envíos que al generarlo (F-072)', function () {
    $acme = Client::factory()->create();
    reportSubmission($this->cycle, [[$acme->id, 'Uno.']]);
    $this->cycle->forceFill(['report' => ['global_summary' => 'X', 'team_risks' => [], 'client_updates' => []], 'submission_count_at_generation' => 1, 'report_state' => WeeklyJobState::Done])->save();
    $employee = userWithRole('employee');

    $this->actingAs($employee)->getJson("/weeklies/{$this->cycle->id}/informe/estado")
        ->assertOk()
        ->assertJson(['report_state' => 'done', 'has_report' => true, 'has_audio' => false, 'submitted_count' => 1, 'stale' => false]);

    reportSubmission($this->cycle, [[$acme->id, 'Dos.']]);
    $this->actingAs($employee)->getJson("/weeklies/{$this->cycle->id}/informe/estado")->assertJson(['stale' => true, 'submitted_count' => 2]);

    $this->actingAs($employee)->get("/weeklies/{$this->cycle->id}")
        ->assertInertia(fn (Assert $page) => $page->where('stale', true)->where('submitted_count', 2));

    $this->actingAs(User::factory()->collaborator()->create())->getJson("/weeklies/{$this->cycle->id}/informe/estado")->assertForbidden();
});

it('quien gestiona edita el informe a mano y el texto se vuelve a escribir (F-077)', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    $this->cycle->forceFill(['report' => [
        'global_summary' => 'Original',
        'team_risks' => ['Riesgo'],
        'client_updates' => [
            ['client_id' => $acme->id, 'client_name' => 'Acme', 'status' => 'on_track', 'executive_summary' => 'Antes', 'next_steps' => [], 'milestones' => [], 'tags' => ['Web'], 'satisfaction_score' => 61, 'has_reports' => true,
                'projects' => [['project_id' => 1, 'code' => 'ACME-BH1', 'name' => 'Bolsa', 'billing_type' => 'hour_bank', 'budget_minutes' => 600, 'consumed_minutes' => 60, 'expected_minutes' => null, 'week_minutes' => 60]]],
            ['client_id' => null, 'client_name' => 'General / Interno', 'status' => 'on_track', 'executive_summary' => 'Interno', 'next_steps' => [], 'milestones' => [], 'tags' => [], 'has_reports' => true, 'projects' => []],
        ],
    ]])->save();

    $this->actingAs($this->manager)->put("/weeklies/{$this->cycle->id}/informe", [
        'global_summary' => 'Editado',
        'client_updates' => [
            ['client_id' => $acme->id, 'client_name' => 'Acme', 'status' => 'blocked', 'executive_summary' => ' Después ', 'next_steps' => ['Llamar', ''], 'milestones' => [['date' => '15/10', 'label' => 'Entrega'], ['date' => '', 'label' => '']]],
            ['client_id' => null, 'client_name' => 'General / Interno', 'status' => 'risk', 'executive_summary' => 'Interno editado', 'next_steps' => [], 'milestones' => []],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $cycle = $this->cycle->refresh();
    $report = $cycle->reportData();
    expect($report->globalSummary)->toBe('Editado')
        ->and($report->teamRisks)->toBe(['Riesgo'])
        ->and($report->clientUpdates[0]->status)->toBe(WeeklyClientStatus::Blocked)
        ->and($report->clientUpdates[0]->executiveSummary)->toBe('Después')
        ->and($report->clientUpdates[0]->nextSteps)->toBe(['Llamar'])
        ->and($report->clientUpdates[0]->milestones[0]->label)->toBe('Entrega')
        ->and($report->clientUpdates[0]->milestones)->toHaveCount(1)
        // Lo que no se edita se conserva: etiquetas, satisfacción y proyectos.
        ->and($report->clientUpdates[0]->tags)->toBe(['Web'])
        ->and($report->clientUpdates[0]->satisfactionScore)->toBe(61)
        ->and($report->clientUpdates[0]->projects[0]->code)->toBe('ACME-BH1')
        ->and($report->clientUpdates[1]->executiveSummary)->toBe('Interno editado')
        ->and($cycle->report_text)->toContain('### Acme - Bloqueado')->toContain('- 15/10: Entrega')
        ->and($cycle->report_edited_by)->toBe($this->manager->id);

    $this->actingAs(userWithRole('employee'))->putJson("/weeklies/{$this->cycle->id}/informe", ['client_updates' => []])->assertForbidden();
    $this->actingAs($this->manager)->putJson("/weeklies/{$this->cycle->id}/informe", ['client_updates' => [['client_name' => 'X', 'status' => 'raro']]])->assertJsonValidationErrors(['client_updates.0.status']);
});

it('sin informe no se edita', function () {
    $this->actingAs($this->manager)->putJson("/weeklies/{$this->cycle->id}/informe", ['client_updates' => [['client_name' => 'X', 'status' => 'risk', 'executive_summary' => '', 'next_steps' => [], 'milestones' => []]]])
        ->assertJsonValidationErrors(['report' => 'Primero genera el informe.']);
});

it('el estado de los proyectos: bolsa en curso, fee mensual con lo esperado por días laborables y precio cerrado (D-188)', function () {
    $acme = Client::factory()->create();
    reportBankProject($acme, 1200, 300, 'ACME-BH2');
    // Fee mensual de 20 h (así llegan de ClickUp): octubre tiene 22 días laborables; con el 12 festivo, 21.
    Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional']);
    $fee = Project::factory()->create(['client_id' => $acme->id, 'code' => 'ACME-FE', 'billing_type' => BillingType::TimeAndMaterials, 'description' => 'Fee mensual de 20 h.']);
    $feeTask = Task::factory()->create(['project_id' => $fee->id]);
    TimeEntry::factory()->forTask($feeTask)->minutes(300)->on('2026-10-02')->create();
    TimeEntry::factory()->forTask($feeTask)->minutes(120)->on('2026-09-30')->create(); // otro mes
    $fixed = Project::factory()->fixedPrice()->create(['client_id' => $acme->id, 'code' => 'ACME-WE', 'budget_minutes' => 6000]);
    $fixedTask = Task::factory()->create(['project_id' => $fixed->id]);
    TimeEntry::factory()->forTask($fixedTask)->minutes(4000)->on('2026-08-10')->create();
    TimeEntry::factory()->forTask($fixedTask)->minutes(60)->on('2026-10-11')->create(); // domingo de la semana
    // Por horas sin presupuesto: solo si tiene horas esa semana.
    $hourly = Project::factory()->create(['client_id' => $acme->id, 'code' => 'ACME-SOP']);
    Project::factory()->create(['client_id' => $acme->id, 'code' => 'ACME-OLD']);
    TimeEntry::factory()->forTask(Task::factory()->create(['project_id' => $hourly->id]))->minutes(45)->on('2026-10-05')->create();
    Project::factory()->archived()->create(['client_id' => $acme->id, 'code' => 'ACME-ARC', 'budget_minutes' => 600]);

    $snapshots = collect(app(WeeklyProjectStatus::class)->forCycle($this->cycle, [$acme->id])[$acme->id])->keyBy('code');

    expect($snapshots->keys()->all())->toBe(['ACME-BH2', 'ACME-FE', 'ACME-SOP', 'ACME-WE'])
        ->and($snapshots['ACME-BH2']->billingType)->toBe('hour_bank')
        ->and($snapshots['ACME-BH2']->budgetMinutes)->toBe(1200)
        ->and($snapshots['ACME-BH2']->consumedMinutes)->toBe(300)
        ->and($snapshots['ACME-FE']->billingType)->toBe('monthly_fee')
        ->and($snapshots['ACME-FE']->budgetMinutes)->toBe(1200)
        ->and($snapshots['ACME-FE']->consumedMinutes)->toBe(300)
        // Referencia: hoy, jueves 08/10 → 6 laborables de 21: 1200 × 6 / 21 = 342,86.
        ->and($snapshots['ACME-FE']->expectedMinutes)->toBe(343)
        ->and($snapshots['ACME-WE']->consumedMinutes)->toBe(4060)
        ->and($snapshots['ACME-WE']->weekMinutes)->toBe(60)
        ->and($snapshots['ACME-SOP']->budgetMinutes)->toBeNull()
        ->and($snapshots['ACME-SOP']->weekMinutes)->toBe(45);
});

it('cuenta los días laborables sin fines de semana ni festivos', function () {
    $count = WeeklyProjectStatus::countWorkingDays(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), CarbonImmutable::parse('2026-10-09'), ['2026-10-12' => true]);

    expect($count)->toBe(['total' => 21, 'elapsed' => 7]);
});

it('la generación se para si pasa del límite, como los 10 minutos del original', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    $beta = Client::factory()->create(['name' => 'Beta']);
    reportSubmission($this->cycle, [[$acme->id, 'Texto.'], [$beta->id, 'Otro.']]);
    FakeLlm::bind()->respondUsing(function (): array {
        test()->travel(LlmWeeklyReportGenerator::DEADLINE_SECONDS + 1)->seconds();

        return ['clientName' => 'Acme', 'executiveSummary' => 'x', 'status' => 'On Track', 'nextSteps' => [], 'milestones' => [], 'tags' => []];
    });

    expect(fn () => app(WeeklyReportGenerator::class)->generate($this->cycle))->toThrow(LlmUnavailable::class, 'La generación de la weekly tardó más de 10 minutos.');
});

it('sin clave, la IA de prueba (local y E2E) responde con la forma de cada esquema', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    reportSubmission($this->cycle, [[$acme->id, 'Texto.']]);
    app()->instance(LlmClient::class, FakeLlm::demo());

    $report = app(WeeklyReportGenerator::class)->generate($this->cycle)->report;

    expect($report->clientUpdates[0]->executiveSummary)->toBe('Resumen de prueba de Acme generado sin IA (GEMINI_DRIVER=fake).')
        ->and($report->clientUpdates[0]->status)->toBe(WeeklyClientStatus::OnTrack)
        ->and($report->globalSummary)->toBe('Resumen global de prueba generado sin IA (GEMINI_DRIVER=fake).')
        ->and(FakeLlm::demo()->generate(new LlmRequest(AiFeature::TranscriptCleanup, "TRANSCRIPCIÓN BRUTA:\nhola qué tal\n\nINSTRUCCIONES:\n1."))->text)->toBe('hola qué tal')
        ->and(FakeLlm::demo()->generate(new LlmRequest(AiFeature::AudioScript, 'x "clientKey": "client-3"', responseSchema: ReportSchemas::narration()))->json['clients'])->toBe([['clientKey' => 'client-3', 'script' => 'Bloque de prueba.']]);
});
