<?php

use App\Domain\Weeklies\Insights\ClientSummaryWeek;
use App\Domain\Weeklies\Insights\InsightPrompts;
use App\Domain\Weeklies\Report\WeeklyClientUpdate;
use App\Enums\WeeklyClientStatus;

/*
| Prompts de las fichas de cliente y de persona (10.4, D-194), portados de las Edge Functions
| generate-client-summary, analyze-team-activity, generate-performance-summary y
| analyze-user-client-activity: recortes, estado de proyectos, tendencia y respuestas por id.
*/

it('recorta como el original: con «…» o «...», normalizando o no los espacios', function () {
    expect(InsightPrompts::truncateEllipsis("  hola\n  mundo  ", 50))->toBe('hola mundo')
        ->and(InsightPrompts::truncateEllipsis('abcdef ghij', 7))->toBe('abcdef…')
        ->and(InsightPrompts::truncateEllipsis(null, 5))->toBe('')
        ->and(InsightPrompts::truncateRaw("a\n b c", 3))->toBe("a\n ...")
        ->and(InsightPrompts::truncateRaw(null, 3))->toBe('')
        ->and(InsightPrompts::truncateDots('uno  dos tres', 7))->toBe('uno dos...');
});

it('quita las vallas de código de la respuesta', function () {
    expect(InsightPrompts::stripCodeFences("```json\n{\"a\":1}\n```"))->toBe('{"a":1}')
        ->and(InsightPrompts::stripCodeFences("```markdown\n## Hola\n```"))->toBe('## Hola')
        ->and(InsightPrompts::stripCodeFences('  ## Hola  '))->toBe('## Hola');
});

it('el estado de proyectos del resumen: consumido, estado y lo esperado de un fee', function () {
    $line = InsightPrompts::clientProjectStatus([
        ['code' => 'ACME-WE1', 'kind_code' => 'WE', 'budget_minutes' => 6000, 'consumed_minutes' => 6160, 'expected_minutes' => null],
        ['code' => 'ACME-FE1', 'kind_code' => 'FE', 'budget_minutes' => 1200, 'consumed_minutes' => 1050, 'expected_minutes' => 343],
        ['code' => 'ACME-SOP', 'kind_code' => 'GE', 'budget_minutes' => null, 'consumed_minutes' => 90, 'expected_minutes' => null],
    ]);

    expect($line)->toBe('ACME-WE1 (Web): 102.67/100h, estado excedido. ACME-FE1 (Fee mensual): 17.5/20h, estado muy consumido. esperado 5.72/20h (28.58% del mes) ACME-SOP (General): 1.5/0h, estado normal.')
        ->and(InsightPrompts::clientProjectStatus([]))->toBe('Sin proyectos activos o relevantes.');
});

it('la tendencia de satisfacción necesita dos semanas y va de la más antigua a la más reciente', function () {
    $week = fn (string $label, ?int $score) => new ClientSummaryWeek(1, $label, '2026-10-02', '', [], new WeeklyClientUpdate(1, 'Acme', WeeklyClientStatus::OnTrack, 'x', satisfactionScore: $score));

    expect(InsightPrompts::satisfactionTrend([$week('S41', 64)]))->toBe('Sin suficiente histórico de satisfacción semanal para inferir una tendencia sólida.')
        ->and(InsightPrompts::satisfactionTrend([$week('S41', 64), $week('S40', null), $week('S39', 60)]))->toBe('S39: 60% | S41: 64%');
});

it('una frase por id: las de la IA si son del input, y las que faltan con su reserva', function () {
    $items = InsightPrompts::summariesById(
        ['summaries' => [['memberId' => 1, 'summary' => ' Bien. '], ['memberId' => '9', 'summary' => 'Inventada'], ['memberId' => '2', 'summary' => '']]],
        'memberId',
        ['1' => true, '2' => true, '3' => true, '4' => false],
    );

    expect($items)->toBe([
        '1' => 'Bien.',
        '2' => InsightPrompts::NO_ACTIVITY,
        '3' => InsightPrompts::ACTIVITY_NOT_SUMMARIZED,
        '4' => InsightPrompts::NO_ACTIVITY,
    ])->and(InsightPrompts::summariesById('no es json', 'clientId', ['5' => false]))->toBe(['5' => InsightPrompts::NO_ACTIVITY]);
});

it('los prompts con INPUT_JSON llevan el JSON sin escapar y las instrucciones del original', function () {
    $team = InsightPrompts::teamActivity('Acme', [['memberId' => '1', 'memberName' => 'Ana Díaz', 'reports' => [['week' => 'Semana 40', 'text' => 'Diseño/UX']]]]);
    $person = InsightPrompts::personClientActivity('Ana', [['clientId' => '5', 'clientName' => 'Acme', 'role' => 'owner', 'reports' => []]]);

    expect($team)->toStartWith("\nGenera un resumen corto de actividad por miembro para el cliente \"Acme\".\n\nINPUT_JSON:\n[{\"memberId\":\"1\",\"memberName\":\"Ana Díaz\",\"reports\":[{\"week\":\"Semana 40\",\"text\":\"Diseño/UX\"}]}]\n")
        ->and($team)->toContain('- Si no hay reportes para un miembro, usa: "Sin actividad reciente detectada en reportes para este cliente."')
        ->and($team)->toEndWith("- Sin markdown, sin texto extra.\n")
        ->and($person)->toContain('- Si la persona lidera el cliente, deja claro su foco o responsabilidad principal.')
        ->and($person)->toEndWith('- Sin markdown, sin texto extra.');
});
