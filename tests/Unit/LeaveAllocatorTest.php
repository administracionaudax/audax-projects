<?php

use App\Domain\Absences\LeaveAllocator;

/*
| Reparto de lo que se gasta entre lo que hay (Fase 11, R3; D-363): gasta primero lo que caduca
| antes, solo de lo que vale ese día, las aprobadas antes que las pendientes y lo que no cabe queda al
| descubierto.
*/

function lot(int $id, int $year, int $amount, string $from, ?string $expires): array
{
    return ['id' => $id, 'year' => $year, 'amount' => $amount, 'valid_from' => $from, 'expires_on' => $expires, 'kind' => 'accrual'];
}

it('gasta primero lo arrastrado que caduca antes y después lo del año', function () {
    $lots = [lot(2, 2026, 2200, '2026-01-01', '2027-03-31'), lot(1, 2025, 300, '2025-01-01', '2026-03-31')];
    $debits = [];
    foreach (['2026-03-30', '2026-03-31', '2026-04-01', '2026-04-02'] as $date) {
        $debits[] = ['date' => $date, 'amount' => 100, 'pending' => false];
    }

    $state = LeaveAllocator::allocate($lots, $debits);
    $byId = array_column($state['lots'], null, 'id');

    // Los dos días de marzo, del arrastre (caduca el 31/03); abril ya no puede tirar de él.
    expect($byId[1]['used'])->toBe(200)->and($byId[1]['remaining'])->toBe(100)
        ->and($byId[2]['used'])->toBe(200)->and($byId[2]['remaining'])->toBe(2000)
        ->and($state['uncovered'])->toBe([]);
});

it('no gasta lo que aún no vale ni lo ya caducado y deja al descubierto lo que no cabe', function () {
    $state = LeaveAllocator::allocate(
        [lot(1, 2027, 2200, '2027-01-01', '2028-03-31'), lot(2, 2025, 500, '2025-01-01', '2026-03-31')],
        [['date' => '2026-06-01', 'amount' => 100, 'pending' => false, 'ref' => 'absence:9']],
    );

    expect(array_sum(array_column($state['lots'], 'used')))->toBe(0)
        ->and($state['uncovered'])->toBe([['date' => '2026-06-01', 'amount' => 100, 'pending' => false, 'ref' => 'absence:9']]);
});

it('las aprobadas van antes que las pendientes aunque sean de una fecha posterior', function () {
    $state = LeaveAllocator::allocate(
        [lot(1, 2026, 200, '2026-01-01', '2026-12-31')],
        [
            ['date' => '2026-05-04', 'amount' => 100, 'pending' => true, 'ref' => 'absence:1'],
            ['date' => '2026-05-05', 'amount' => 100, 'pending' => true, 'ref' => 'absence:1'],
            ['date' => '2026-08-03', 'amount' => 100, 'pending' => false, 'ref' => 'absence:2'],
        ],
    );

    expect($state['lots'][0]['used'])->toBe(100)
        ->and($state['lots'][0]['reserved'])->toBe(100)
        ->and($state['uncovered'])->toBe([['date' => '2026-05-05', 'amount' => 100, 'pending' => true, 'ref' => 'absence:1']]);
});

it('un movimiento negativo gasta primero de su mismo año', function () {
    $state = LeaveAllocator::allocate(
        [lot(1, 2025, 300, '2025-01-01', '2026-03-31'), lot(2, 2026, 2200, '2026-01-01', '2027-03-31')],
        [['date' => '2026-01-01', 'amount' => 1100, 'pending' => false, 'year' => 2026, 'ref' => 'movement:3']],
    );
    $byId = array_column($state['lots'], null, 'id');

    expect($byId[2]['used'])->toBe(1100)->and($byId[1]['used'])->toBe(0);
});

it('a igual caducidad gasta primero el abono más antiguo y sin caducidad, el último', function () {
    $state = LeaveAllocator::allocate(
        [lot(3, 2026, 100, '2026-01-01', null), lot(2, 2026, 100, '2026-02-01', '2026-12-31'), lot(1, 2026, 100, '2026-01-01', '2026-12-31')],
        [['date' => '2026-06-01', 'amount' => 150, 'pending' => false]],
    );
    $byId = array_column($state['lots'], null, 'id');

    expect($byId[1]['used'])->toBe(100)->and($byId[2]['used'])->toBe(50)->and($byId[3]['used'])->toBe(0);
});
