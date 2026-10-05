<?php

use App\Domain\Weeklies\Report\ReportClientInput;
use App\Domain\Weeklies\Report\ReportEntryInput;
use App\Domain\Weeklies\Report\ReportPipeline;
use App\Domain\Weeklies\Report\WeeklyProjectSnapshot;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;

/*
| Port de `ws:generate-weekly-report/pipeline.js` (10.3, F-072 a F-076): lotes, reglas de estado por
| consumo, «Sin novedades», resúmenes de reserva, prompts y la detección de inglés.
*/

function pipelineProject(string $kind, ?int $budget, int $consumed, ?int $expected = null, string $code = 'ACME-BH1', int $week = 0): WeeklyProjectSnapshot
{
    return new WeeklyProjectSnapshot(1, $code, 'Proyecto', $kind, $budget, $consumed, $expected, $week);
}

function pipelineEntry(int $sequence, string $text, string $author = 'Ana'): ReportEntryInput
{
    return new ReportEntryInput($sequence, $author, '2026-10-08T10:00:00.000Z', $text);
}

it('el estado de un cliente sin reportes sale del consumo: más del 100 % Blocked, desde el 85 % Risk', function (array $projects, string $status, string $note) {
    $update = ReportPipeline::noReportClientUpdate(new ReportClientInput(7, 'Acme', projects: $projects));

    expect($update['status'])->toBe($status)
        ->and($update['executiveSummary'])->toBe(trim(ReportPipeline::NO_REPORT_SUMMARY.' '.$note))
        ->and($update['nextSteps'])->toBe([])
        ->and($update['clientId'])->toBe(7);
})->with([
    'sin proyectos' => [[], 'On Track', ''],
    'al 50 %' => [[pipelineProject('hour_bank', 600, 300)], 'On Track', ''],
    'al 85 % justo' => [[pipelineProject('hour_bank', 600, 510)], 'Risk', 'Aun sin actividad reportada, el proyecto ACME-BH1 (Bolsa de horas) muestra señales de riesgo y conviene vigilarlo.'],
    'al 100 % justo sigue en Risk' => [[pipelineProject('hour_bank', 600, 600)], 'Risk', 'Aun sin actividad reportada, el proyecto ACME-BH1 (Bolsa de horas) muestra señales de riesgo y conviene vigilarlo.'],
    'pasado del 100 %' => [[pipelineProject('hour_bank', 600, 601)], 'Blocked', 'Aun sin actividad reportada, el proyecto ACME-BH1 (Bolsa de horas) está excedido o claramente por encima de lo esperado y requiere seguimiento inmediato.'],
    'sin presupuesto no cuenta' => [[pipelineProject('time_and_materials', null, 9000)], 'On Track', ''],
]);

it('un fee mensual por encima de lo esperado sube a Risk y, con 4 h o más, a Blocked', function (int $consumed, int $expected, string $status) {
    $update = ReportPipeline::noReportClientUpdate(new ReportClientInput(1, 'Acme', projects: [pipelineProject(WeeklyProjectStatus::KIND_MONTHLY_FEE, 1200, $consumed, $expected, 'ACME-FE')]));

    expect($update['status'])->toBe($status);
})->with([
    'por debajo' => [300, 600, 'On Track'],
    '1 minuto por encima' => [601, 600, 'Risk'],
    '3:59 por encima' => [839, 600, 'Risk'],
    '4 h por encima' => [840, 600, 'Blocked'],
    // Ya pasado del 100 %, el exceso sobre lo esperado no cuenta (pero el 100 % sí).
    'pasado del presupuesto' => [1300, 600, 'Blocked'],
]);

it('el proyecto que manda es el más grave y, a igual gravedad, el más consumido', function () {
    $primary = ReportPipeline::primaryProjectRisk([
        pipelineProject('hour_bank', 600, 520, code: 'A'),
        pipelineProject('hour_bank', 600, 560, code: 'B'),
        pipelineProject('fixed_price', 600, 100, code: 'C'),
    ]);

    expect($primary['project']->code)->toBe('B')
        ->and($primary['severity'])->toBe(1);
});

it('reparte los apuntes en lotes de 9.000 caracteres contados como en JavaScript', function () {
    $long = str_repeat('a', 4000);
    $entries = [pipelineEntry(1, $long), pipelineEntry(2, $long), pipelineEntry(3, $long), pipelineEntry(4, 'corto')];

    $batches = ReportPipeline::splitIntoBatches($entries);

    expect(array_map(fn (array $batch): array => array_map(fn (ReportEntryInput $entry): int => $entry->sequence, $batch), $batches))
        ->toBe([[1, 2], [3, 4]])
        ->and(ReportPipeline::splitIntoBatches([]))->toBe([])
        // Un apunte más largo que el lote va solo.
        ->and(ReportPipeline::splitIntoBatches([pipelineEntry(1, str_repeat('b', 10_000)), pipelineEntry(2, 'x')]))->toHaveCount(2)
        // Un emoji cuenta 2 (UTF-16), como String.length.
        ->and(ReportPipeline::jsLength('😀a'))->toBe(3);
});

it('el bloque de un apunte lleva su número, su autor y cuándo se envió', function () {
    expect(ReportPipeline::formatEntryBlock(pipelineEntry(3, 'Hecho el diseño', 'Elena')))
        ->toBe("Entrada 3 | Autor: Elena | Enviado: 2026-10-08T10:00:00.000Z\nHecho el diseño")
        ->and(ReportPipeline::formatEntryBlock(new ReportEntryInput(1, 'Ana', null, 'Sin fecha')))->toBe("Entrada 1 | Autor: Ana\nSin fecha");
});

it('los prompts son los del original, con el contexto de proyectos de Audax', function () {
    $client = new ReportClientInput(1, 'Acme', projects: [pipelineProject('hour_bank', 1200, 1080, null, 'ACME-BH1', 150)], entries: [pipelineEntry(1, 'Texto')]);

    $batch = ReportPipeline::clientBatchPrompt($client, $client->entries, 0, 1);
    $merge = ReportPipeline::clientMergePrompt($client, [['clientName' => 'Acme']]);

    expect($batch)->toStartWith('Eres un director de proyectos senior. Debes redactar el update semanal de UN SOLO cliente')
        ->toContain("CLIENTE:\nAcme")
        ->toContain('ACME-BH1 (Bolsa de horas): 18/20h, estado EN RIESGO; esta semana 2.5h.')
        ->toContain('Este bloque contiene todo el material disponible para este cliente.')
        ->toContain('"clientName": "Acme",')
        ->toContain('2.b. TODOS los textos redactados por ti deben estar en español de España.')
        ->toEndWith("REPORTES INTERNOS DEL CLIENTE:\nEntrada 1 | Autor: Ana | Enviado: 2026-10-08T10:00:00.000Z\nTexto")
        ->and(ReportPipeline::clientBatchPrompt($client, $client->entries, 1, 3))->toContain('Este es el lote 2 de 3 para este cliente.')
        ->and($merge)->toContain("RESÚMENES PARCIALES JSON:\n[\n  {\n    \"clientName\": \"Acme\"\n  }\n]")
        ->toContain('7. Une "nextSteps", "milestones" y "tags" sin duplicados.');
});

it('el contexto de proyectos dice el estado, lo esperado de un fee y las horas de la semana', function () {
    expect(ReportPipeline::projectStatusContext([]))->toBe('Sin alertas relevantes de estado de proyectos esta semana.')
        ->and(ReportPipeline::projectStatusContext([
            pipelineProject(WeeklyProjectStatus::KIND_MONTHLY_FEE, 1200, 1300, 600, 'ACME-FE'),
            pipelineProject('time_and_materials', null, 90, null, 'ACME-WEB', 90),
        ]))->toBe('ACME-FE (Fee mensual): 21.67/20h, estado EXCEDIDA; esperado 10/20h (50% del mes). ACME-WEB (Por horas): sin presupuesto de horas; esta semana 1.5h.');
});

it('el resumen global resume cada cliente (recortado a 900 caracteres) con sus pasos e hitos', function () {
    $prompt = ReportPipeline::globalSummaryPrompt([
        ['clientId' => 1, 'clientName' => 'Acme', 'executiveSummary' => str_repeat('x', 1000), 'status' => 'Risk', 'nextSteps' => ['Llamar'], 'milestones' => [['date' => '09/10', 'label' => 'Entrega']], 'tags' => []],
    ]);

    expect($prompt)->toContain("Cliente: Acme\nEstado: En riesgo\nResumen: ".str_repeat('x', 899).'…')
        ->toContain('Próximos pasos: Llamar')
        ->toContain('Hitos: 09/10 Entrega')
        ->and(ReportPipeline::globalSummaryPrompt([]))->toContain('No hay clientes con actividad consolidada.');
});

it('normaliza lo que devuelve la IA: estado, duplicados, hitos sin fecha y el resumen vacío', function () {
    $client = new ReportClientInput(1, 'Acme', entries: [pipelineEntry(1, 'Primera frase. Segunda frase!')]);

    $update = ReportPipeline::normalizeClientUpdate([
        'executiveSummary' => "  Todo   bien\n",
        'status' => 'on-track',
        'nextSteps' => ['Llamar', 'llamar ', '', 'Enviar'],
        'milestones' => [['date' => '09/10', 'label' => 'Entrega'], ['date' => '', 'label' => 'Sin fecha'], ['date' => '09/10', 'label' => 'entrega']],
        'tags' => ['Web'],
    ], $client);

    expect($update)->toBe([
        'clientId' => 1,
        'clientName' => 'Acme',
        'executiveSummary' => 'Todo bien',
        'status' => 'On Track',
        'nextSteps' => ['Llamar', 'Enviar'],
        'milestones' => [['date' => '09/10', 'label' => 'Entrega']],
        'tags' => ['Web'],
    ])
        ->and(ReportPipeline::normalizeClientUpdate(['status' => 'weird', 'executiveSummary' => 'Ok'], $client)['status'])->toBe('On Track')
        ->and(ReportPipeline::normalizeClientUpdate(['executiveSummary' => ''], $client)['executiveSummary'])
        ->toBe("Se ha reportado actividad en Acme durante la semana.\n- Primera frase.\n- Segunda frase!");
});

it('el resumen global de reserva cuenta clientes con actividad, bloqueados y en riesgo', function () {
    $update = fn (string $summary, string $status): array => ['clientId' => 1, 'clientName' => 'A', 'executiveSummary' => $summary, 'status' => $status, 'nextSteps' => [], 'milestones' => [], 'tags' => []];

    expect(ReportPipeline::fallbackGlobalSummary([]))->toBe('No hay clientes activos para generar la weekly de esta semana.')
        ->and(ReportPipeline::fallbackGlobalSummary([$update(ReportPipeline::NO_REPORT_SUMMARY, 'On Track')]))->toBe('No se han reportado novedades relevantes en los clientes activos esta semana.')
        ->and(ReportPipeline::fallbackGlobalSummary([$update('Hecho', 'Blocked'), $update('Hecho', 'Risk')]))->toBe('Se ha consolidado actividad en 2 clientes durante la semana. Hay 1 cliente bloqueado que requieren atención inmediata.')
        ->and(ReportPipeline::fallbackGlobalSummary([$update('Hecho', 'Risk')]))->toBe('Se ha consolidado actividad en 1 cliente durante la semana. Se mantienen 1 cliente en riesgo con seguimiento activo.')
        ->and(ReportPipeline::fallbackGlobalSummary([$update('Hecho', 'On Track')]))->toBe('Se ha consolidado actividad en 1 cliente durante la semana. La mayoría de los proyectos siguen su curso sin bloqueos graves.');
});

it('detecta un texto en inglés para reescribirlo en español (F-076)', function () {
    expect(ReportPipeline::needsSpanishRewrite(['executiveSummary' => 'The team delivered the website and the client sent feedback on the design.']))->toBeTrue()
        ->and(ReportPipeline::needsSpanishRewrite(['executiveSummary' => 'El equipo entregó la web y el cliente dio feedback del diseño.']))->toBeFalse()
        ->and(ReportPipeline::needsSpanishRewrite(['executiveSummary' => 'Review and feedback']))->toBeTrue()
        ->and(ReportPipeline::needsSpanishRewrite(['executiveSummary' => 'Review del diseño']))->toBeFalse()
        ->and(ReportPipeline::needsSpanishRewrite(['globalSummary' => '', 'teamRisks' => ['The project is blocked and the client is pending with this issue']]))->toBeTrue()
        ->and(ReportPipeline::needsSpanishRewrite(null))->toBeFalse();
});

it('ordena los clientes como localeCompare y deja «General / Interno» al final', function () {
    $update = fn (?int $id, string $name): array => ['clientId' => $id, 'clientName' => $name, 'executiveSummary' => '', 'status' => 'On Track', 'nextSteps' => [], 'milestones' => [], 'tags' => []];

    $sorted = ReportPipeline::sortByName([$update(null, 'General / Interno'), $update(2, 'Zeta'), $update(3, 'ábaco'), $update(4, 'Beta')]);

    expect(array_column($sorted, 'clientName'))->toBe(['ábaco', 'Beta', 'Zeta', 'General / Interno']);
});

it('las horas en el prompt son un Number de JavaScript', function () {
    expect(ReportPipeline::hours(750))->toBe('12.5')
        ->and(ReportPipeline::hours(600))->toBe('10')
        ->and(ReportPipeline::hours(1300))->toBe('21.67')
        ->and(ReportPipeline::hours(0))->toBe('0');
});
