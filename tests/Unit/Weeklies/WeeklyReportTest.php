<?php

use App\Domain\Weeklies\Report\WeeklyReport;
use App\Domain\Weeklies\WeeklyDraftData;
use App\Domain\Weeklies\WeeklyReportState;
use App\Enums\WeeklyClientStatus;
use App\Enums\WeeklyCycleStatus;
use App\Enums\WeeklyEntrySource;
use App\Models\WeeklyCycle;

/*
| Contrato del informe estructurado (F-073 a F-077) y del contenido de una weekly (F-044 a F-052).
*/

$full = [
    'global_summary' => 'Semana tranquila.',
    'team_risks' => ['Vacaciones de Ana', ' ', 7],
    'client_updates' => [[
        'client_id' => 4,
        'client_name' => 'Acme',
        'status' => 'risk',
        'executive_summary' => 'Bolsa al 90 %.',
        'next_steps' => ['Renovar la bolsa', ''],
        'milestones' => [['date' => '2026-10-20', 'label' => 'Entrega'], ['label' => 'Sin fecha'], 'basura'],
        'tags' => ['bolsa'],
        'satisfaction_score' => 61,
        'has_reports' => false,
        'projects' => [['project_id' => 9, 'code' => 'ACME-BH1', 'name' => 'Bolsa', 'billing_type' => 'hour_bank', 'budget_minutes' => 1200, 'consumed_minutes' => 1080, 'expected_minutes' => 1000]],
    ], 'no es un cliente'],
];

it('lee y escribe el informe sin perder nada y descarta lo que no tiene forma', function () use ($full) {
    $report = WeeklyReport::fromArray($full);
    $update = $report->clientUpdates[0];

    expect($report->teamRisks)->toBe(['Vacaciones de Ana', '7'])
        ->and($report->clientUpdates)->toHaveCount(1)
        ->and($update->status)->toBe(WeeklyClientStatus::Risk)
        ->and($update->nextSteps)->toBe(['Renovar la bolsa'])
        ->and($update->milestones)->toHaveCount(2)
        ->and($update->milestones[1]->date)->toBeNull()
        ->and($update->hasReports)->toBeFalse()
        ->and($update->projects[0]->deviationMinutes())->toBe(80)
        ->and($report->clientUpdate(4))->toBe($update)
        ->and($report->clientUpdate(5))->toBeNull();

    expect(WeeklyReport::fromArray($report->toArray())->toArray())->toBe($report->toArray())
        ->and($report->toArray()['client_updates'][0]['projects'][0]['deviation_minutes'])->toBe(80);
});

it('un informe vacío o incompleto toma valores por defecto', function () {
    $report = WeeklyReport::fromArray(['client_updates' => [['client_name' => 'Sin estado', 'status' => 'raro']]]);

    expect($report->globalSummary)->toBe('')
        ->and($report->teamRisks)->toBe([])
        ->and($report->clientUpdates[0]->status)->toBe(WeeklyClientStatus::OnTrack)
        ->and($report->clientUpdates[0]->clientId)->toBeNull()
        ->and($report->clientUpdates[0]->hasReports)->toBeTrue();
});

it('convierte el structured_report de WeeklySync con los ids de Audax (10.8)', function () {
    $ws = [
        'globalSummary' => 'Resumen',
        'teamRisks' => ['Riesgo'],
        'clientUpdates' => [
            ['clientId' => 'uuid-1', 'clientName' => 'Acme', 'status' => 'Blocked', 'executiveSummary' => 'Parado', 'nextSteps' => ['Llamar'], 'milestones' => [['date' => '2026-04-01', 'label' => 'Hito']], 'tags' => ['web'], 'satisfactionScore' => 44],
            ['clientName' => 'Beta', 'status' => 'On Track', 'executiveSummary' => 'Bien', 'nextSteps' => [], 'milestones' => [], 'tags' => []],
        ],
        'audioSections' => [['key' => 'intro', 'kind' => 'INTRO']],
    ];
    $ids = ['uuid-1' => 10];

    $report = WeeklyReport::fromWeeklySync($ws, fn (?string $id, string $name): ?int => $ids[$id] ?? ($name === 'Beta' ? 11 : null));

    expect($report->globalSummary)->toBe('Resumen')
        ->and($report->teamRisks)->toBe(['Riesgo'])
        ->and($report->clientUpdates[0]->clientId)->toBe(10)
        ->and($report->clientUpdates[0]->status)->toBe(WeeklyClientStatus::Blocked)
        ->and($report->clientUpdates[0]->satisfactionScore)->toBe(44)
        ->and($report->clientUpdates[0]->milestones[0]->label)->toBe('Hito')
        ->and($report->clientUpdates[1]->clientId)->toBe(11)
        ->and($report->clientUpdates[1]->status)->toBe(WeeklyClientStatus::OnTrack)
        ->and(WeeklyClientStatus::fromWeeklySync('Risk'))->toBe(WeeklyClientStatus::Risk)
        ->and($report->clientUpdates[0]->withSatisfaction(50)->satisfactionScore)->toBe(50);
});

it('la gravedad del estado ordena on_track < risk < blocked', function () {
    expect(WeeklyClientStatus::OnTrack->severity())->toBeLessThan(WeeklyClientStatus::Risk->severity())
        ->and(WeeklyClientStatus::Risk->severity())->toBeLessThan(WeeklyClientStatus::Blocked->severity());
});

it('el contenido de una weekly: un apunte por cliente, sin vacíos y con «General»', function () {
    $draft = WeeklyDraftData::fromArray([
        ['client_id' => 3, 'body' => 'Primero', 'project_id' => '7'],
        ['client_id' => null, 'body' => 'General', 'source' => 'dictation'],
        ['client_id' => 4, 'body' => '   '],
        ['client_id' => '3', 'body' => 'Corregido'],
        'basura',
    ]);

    $filled = $draft->filled();

    expect($draft->entries)->toHaveCount(4)
        ->and($filled)->toHaveCount(2)
        ->and($filled[0]->clientId)->toBe(3)
        ->and($filled[0]->body)->toBe('Corregido')
        ->and($filled[0]->projectId)->toBeNull()
        ->and($filled[1]->clientId)->toBeNull()
        ->and($filled[1]->source)->toBe(WeeklyEntrySource::Dictation)
        ->and($draft->isEmpty())->toBeFalse()
        ->and(WeeklyDraftData::fromArray([['client_id' => 1, 'body' => '']])->isEmpty())->toBeTrue();
});

it('el informe está desactualizado si hay más envíos que al generarlo', function () {
    $cycle = new WeeklyCycle(['report' => ['global_summary' => 'x'], 'submission_count_at_generation' => 5]);

    expect(WeeklyReportState::isStale($cycle, 5))->toBeFalse()
        ->and(WeeklyReportState::isStale($cycle, 6))->toBeTrue()
        ->and(WeeklyReportState::isStale(new WeeklyCycle, 6))->toBeFalse();
});

it('para cerrar hacen falta el texto y el audio y que la semana esté activa (F-089)', function () {
    $active = new WeeklyCycle(['status' => WeeklyCycleStatus::Active]);
    $ready = new WeeklyCycle(['status' => WeeklyCycleStatus::Active, 'report' => ['global_summary' => 'x']]);
    $closed = new WeeklyCycle(['status' => WeeklyCycleStatus::Closed, 'report' => ['global_summary' => 'x']]);

    expect(WeeklyReportState::closeBlockers($active, false))->toBe(['report', 'audio'])
        ->and(WeeklyReportState::closeBlockers($ready, false))->toBe(['audio'])
        ->and(WeeklyReportState::closeBlockers($ready, true))->toBe([])
        ->and(WeeklyReportState::closeBlockers($closed, true))->toBe(['not_active']);
});
