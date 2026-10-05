<?php

use App\Domain\Weeklies\WeeklyTiming;
use App\Enums\WeeklyCycleProgress;
use App\Enums\WeeklyCycleStatus;
use App\Enums\WeeklyPersonStatus;
use Carbon\CarbonImmutable;

/*
| Plazos y estados (F-042, F-066, F-100 y F-136), portados de ws:src/lib/weekTiming.ts. El plazo es
| un día de Madrid: se cumple hasta las 23:59:59 de ese día.
*/

beforeEach(fn () => $this->timing = new WeeklyTiming);

$madrid = fn (string $at): CarbonImmutable => CarbonImmutable::parse($at, 'Europe/Madrid');

it('el plazo acaba al final del día en Madrid', function () use ($madrid) {
    expect($this->timing->deadlineEnd('2026-10-09')->format('Y-m-d H:i:s'))->toBe('2026-10-09 23:59:59')
        ->and($this->timing->deadlineEnd('2026-10-09')->utc()->format('Y-m-d H:i'))->toBe('2026-10-09 21:59')
        ->and($this->timing->isOnTime($madrid('2026-10-09 23:59:59'), '2026-10-09'))->toBeTrue()
        ->and($this->timing->isOnTime($madrid('2026-10-10 00:00:00'), '2026-10-09'))->toBeFalse()
        // 22:30 UTC del viernes ya es sábado en Madrid (horario de verano).
        ->and($this->timing->isOnTime(CarbonImmutable::parse('2026-10-09 22:30:00', 'UTC'), '2026-10-09'))->toBeFalse()
        ->and($this->timing->isOnTime(CarbonImmutable::parse('2026-10-09 21:30:00', 'UTC'), '2026-10-09'))->toBeTrue()
        ->and($this->timing->isOverdue('2026-10-09', $madrid('2026-10-10 00:00:01')))->toBeTrue()
        ->and($this->timing->isOverdue('2026-10-09', $madrid('2026-10-09 23:00:00')))->toBeFalse();
});

it('la ventana de pendiente empieza el día laborable anterior al plazo', function (string $deadline, string $start) {
    expect($this->timing->pendingStart($deadline)->format('Y-m-d H:i'))->toBe($start);
})->with([
    'viernes' => ['2026-10-09', '2026-10-08 00:00'],
    'lunes (salta el fin de semana)' => ['2026-10-12', '2026-10-09 00:00'],
    'domingo' => ['2026-10-11', '2026-10-09 00:00'],
]);

it('calcula el estado de la weekly de una persona con la precedencia de WeeklySync', function (array $args, WeeklyPersonStatus $expected) use ($madrid) {
    [$status, $submittedAt, $exempt, $required, $now] = $args;

    expect($this->timing->personStatus(
        $status,
        '2026-10-09',
        $submittedAt === null ? null : $madrid($submittedAt),
        $exempt,
        $required,
        $madrid($now),
    ))->toBe($expected);
})->with([
    'exenta aunque haya enviado' => [[WeeklyCycleStatus::Active, '2026-10-08 10:00', true, true, '2026-10-08 12:00'], WeeklyPersonStatus::Exempt],
    'enviada a tiempo' => [[WeeklyCycleStatus::Active, '2026-10-09 18:00', false, true, '2026-10-12 09:00'], WeeklyPersonStatus::Submitted],
    'enviada con retraso' => [[WeeklyCycleStatus::Active, '2026-10-10 09:00', false, true, '2026-10-12 09:00'], WeeklyPersonStatus::SubmittedLate],
    'enviada con retraso y semana cerrada' => [[WeeklyCycleStatus::Closed, '2026-10-12 09:00', false, true, '2026-10-13 09:00'], WeeklyPersonStatus::SubmittedLate],
    'próximamente (lunes)' => [[WeeklyCycleStatus::Active, null, false, true, '2026-10-05 10:00'], WeeklyPersonStatus::Upcoming],
    'pendiente (jueves)' => [[WeeklyCycleStatus::Active, null, false, true, '2026-10-08 10:00'], WeeklyPersonStatus::Pending],
    'pendiente (viernes por la noche)' => [[WeeklyCycleStatus::Active, null, false, true, '2026-10-09 23:59'], WeeklyPersonStatus::Pending],
    'con retraso (sábado)' => [[WeeklyCycleStatus::Active, null, false, true, '2026-10-10 00:01'], WeeklyPersonStatus::Overdue],
    'no enviada (cerrada)' => [[WeeklyCycleStatus::Closed, null, false, true, '2026-10-12 09:00'], WeeklyPersonStatus::Missed],
    'cerrada antes de tiempo: no enviada' => [[WeeklyCycleStatus::Closed, null, false, true, '2026-10-06 09:00'], WeeklyPersonStatus::Missed],
    'no le tocaba' => [[WeeklyCycleStatus::Active, null, false, false, '2026-10-08 10:00'], WeeklyPersonStatus::NotRequired],
    'no le tocaba pero envió' => [[WeeklyCycleStatus::Active, '2026-10-08 10:00', false, false, '2026-10-08 12:00'], WeeklyPersonStatus::Submitted],
]);

it('calcula el estado de la semana en el histórico', function (WeeklyCycleStatus $status, int $submitted, int $expected, string $now, WeeklyCycleProgress $progress) use ($madrid) {
    expect($this->timing->cycleProgress($status, '2026-10-09', $submitted, $expected, $madrid($now)))->toBe($progress);
})->with([
    'cerrada' => [WeeklyCycleStatus::Closed, 1, 5, '2026-10-12 09:00', WeeklyCycleProgress::Finished],
    'próximamente' => [WeeklyCycleStatus::Active, 0, 5, '2026-10-06 09:00', WeeklyCycleProgress::Upcoming],
    'con retraso' => [WeeklyCycleStatus::Active, 5, 5, '2026-10-10 09:00', WeeklyCycleProgress::Overdue],
    'completada' => [WeeklyCycleStatus::Active, 5, 5, '2026-10-09 09:00', WeeklyCycleProgress::Completed],
    'por completar' => [WeeklyCycleStatus::Active, 4, 5, '2026-10-09 09:00', WeeklyCycleProgress::InProgress],
    'nadie debía enviar' => [WeeklyCycleStatus::Active, 0, 0, '2026-10-09 09:00', WeeklyCycleProgress::Completed],
]);
