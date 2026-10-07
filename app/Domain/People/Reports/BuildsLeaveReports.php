<?php

namespace App\Domain\People\Reports;

use App\Domain\Absences\AbsenceCost;
use App\Domain\Absences\AbsenceDocuments;
use App\Domain\Absences\AbsenceText;
use App\Domain\Absences\LeaveBalances;
use App\Domain\Absences\LeaveFormat;
use App\Domain\Reports\Delivery\Documents\PdfTable;
use App\Models\Absence;
use App\Models\LeaveMovement;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Los informes de vacaciones y permisos (Fase 11, R3; W-096, W-097 y W-071; D-371), con el mismo
 * patrón que los de R2 (en pantalla y en PDF, Excel y CSV con huella; solo RR. HH.):
 *
 * - **Saldos**: por persona y tipo con saldo, lo asignado, los ajustes, lo disfrutado, lo
 *   pendiente, lo disponible, lo arrastrado del año anterior y lo caducado, a la fecha «hasta».
 * - **Actividad**: las solicitudes del periodo (con su estado, quién la revisó y la cancelación) y
 *   los movimientos del libro anotados en él (asignaciones, ajustes, saldos iniciales y arrastres).
 * - **Justificantes pendientes**: las ausencias que piden justificante y no tienen ninguno.
 *
 * En CSV y Excel las cantidades van como enteros en la unidad del tipo (centésimas de día o
 * minutos, con su columna de unidad); en el PDF, en texto («12,5 días», «16:00 h»).
 */
trait BuildsLeaveReports
{
    public function leaveBalances(RegisterScope $scope): RegisterDocument
    {
        $title = PeopleReportKind::LeaveBalances->title();
        $year = (int) substr($scope->to, 0, 4);
        $types = LeaveBalances::allowanceTypes(onlyActive: false);
        $summaries = app(LeaveBalances::class)->forUsers($scope->users, $year, min($scope->to, LocalTime::todayString()), $types);
        $rows = [];
        $pdfRows = [];

        foreach ($scope->users as $user) {
            foreach ($summaries[$user->id] ?? [] as $summary) {
                /** @var LeaveType $type */
                $type = $summary['type'];

                if ($summary['total'] === 0 && $summary['used'] === 0 && $summary['pending'] === 0 && $summary['carried'] === []) {
                    continue;
                }

                $carried = array_sum(array_column($summary['carried'], 'remaining'));
                $carriedUntil = implode(', ', array_map(fn (array $row): string => PeopleFormat::date((string) $row['expires_on']), $summary['carried']));
                $amount = fn (int $value): string => LeaveFormat::amount($value, $type->unit);

                $pdfRows[] = [$user->name, $type->name, $amount($summary['entitled']), $amount($summary['adjusted']), $carried > 0 ? $amount($carried).' ('.$carriedUntil.')' : '', $amount($summary['used']), $amount($summary['pending']), $amount($summary['available']), $summary['expired'] > 0 ? $amount($summary['expired']) : ''];
                $rows[] = [$user->name, $type->name, $type->unit->value, $year, $summary['entitled'], $summary['adjusted'], $carried, $carriedUntil, $summary['used'], $summary['pending'], $summary['available'], $summary['expired']];
            }
        }

        return new RegisterDocument(
            kind: 'leave_balances',
            title: $title,
            basename: $title.' '.$year.' '.$scope->to,
            cover: $this->cover($title, $scope),
            kpis: [['label' => (string) __('people.reports.leave.kpis.people'), 'value' => (string) count($scope->users), 'detail' => (string) __('people.reports.leave.at', ['date' => PeopleFormat::date($scope->to)])]],
            sections: [[
                'title' => $title,
                'lead' => (string) __('people.reports.leave.balances_lead', ['year' => $year, 'date' => PeopleFormat::date($scope->to)]),
                'table' => PdfTable::make([
                    [__('people.reports.cols.person')], [__('people.reports.leave.cols.type')], [__('people.reports.leave.cols.entitled'), true], [__('people.reports.leave.cols.adjusted'), true],
                    [__('people.reports.leave.cols.carried')], [__('people.reports.leave.cols.used'), true], [__('people.reports.leave.cols.pending'), true], [__('people.reports.leave.cols.available'), true], [__('people.reports.leave.cols.expired'), true],
                ], $pdfRows, compact: true, empty: (string) __('people.reports.leave.none')),
            ]],
            headers: self::leaveHeaders(['person', 'type', 'unit', 'year', 'entitled', 'adjusted', 'carried', 'carried_until', 'used', 'pending', 'available', 'expired']),
            rows: $rows,
            notes: [(string) __('people.reports.leave.units_note')],
        );
    }

    public function leaveActivity(RegisterScope $scope): RegisterDocument
    {
        $title = PeopleReportKind::LeaveActivity->title();
        $ids = array_map(fn (User $user): int => $user->id, $scope->users);
        $names = array_combine($ids, array_map(fn (User $user): string => $user->name, $scope->users)) ?: [];
        $zone = LocalTime::timezone();

        $absences = Absence::query()
            ->with(['leaveType', 'approver:id,name', 'firstApprover:id,name'])
            ->whereIn('user_id', $ids ?: [0])
            ->overlapping($scope->from, $scope->to)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
        $costs = app(AbsenceCost::class)->forAbsences($absences);

        $movements = LeaveMovement::query()
            ->with(['leaveType', 'author:id,name'])
            ->whereIn('user_id', $ids ?: [0])
            ->whereBetween('created_at', [CarbonImmutable::parse($scope->from, $zone)->startOfDay()->utc(), CarbonImmutable::parse($scope->to, $zone)->endOfDay()->utc()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $rows = [];
        $pdfRows = [];

        foreach ($absences as $absence) {
            $type = $absence->leaveType;
            $name = AbsenceText::typeName($type, $absence->type);
            $cost = AbsenceCost::total($costs[$absence->id] ?? []);
            $period = PeopleFormat::date($absence->start_date->toDateString()).($absence->start_date->equalTo($absence->end_date) ? '' : ' – '.PeopleFormat::date($absence->end_date->toDateString()))
                .($absence->start_time !== null ? ' '.$absence->start_time.'–'.$absence->end_time : '');
            $status = $absence->status->label().($absence->cancellation_status !== null ? ' · '.__('people.reports.leave.cancellation.'.$absence->cancellation_status->value) : '');
            $reviewer = $absence->approver->name ?? '';

            $pdfRows[] = [(string) ($names[$absence->user_id] ?? ''), (string) __('people.reports.leave.request'), $name, $period, $type !== null ? LeaveFormat::amount($cost, $type->unit) : '', $status, $reviewer];
            $rows[] = [(string) ($names[$absence->user_id] ?? ''), 'request', $absence->created_at === null ? '' : LocalTime::dateOf(CarbonImmutable::parse($absence->created_at)), $name, $absence->start_date->toDateString(), $absence->end_date->toDateString(), $absence->start_time ?? '', $absence->end_time ?? '', $type?->unit->value ?? '', $cost, $absence->status->value, $absence->cancellation_status->value ?? '', $absence->firstApprover->name ?? '', $reviewer, ''];
        }

        foreach ($movements as $movement) {
            $kind = (string) __('people.reports.leave.kinds.'.$movement->kind->value);
            $pdfRows[] = [(string) ($names[$movement->user_id] ?? ''), $kind, $movement->leaveType->name, (string) $movement->year, LeaveFormat::amount($movement->amount, $movement->leaveType->unit), $movement->reason, $movement->author->name ?? ''];
            $rows[] = [(string) ($names[$movement->user_id] ?? ''), $movement->kind->value, $movement->created_at === null ? '' : LocalTime::dateOf($movement->created_at), $movement->leaveType->name, $movement->valid_from->toDateString(), $movement->expires_on?->toDateString() ?? '', '', '', $movement->leaveType->unit->value, $movement->amount, '', '', '', $movement->author->name ?? '', $movement->reason];
        }

        return new RegisterDocument(
            kind: 'leave_activity',
            title: $title,
            basename: $title.' '.$scope->from.' '.$scope->to,
            cover: $this->cover($title, $scope),
            kpis: [
                ['label' => (string) __('people.reports.leave.kpis.requests'), 'value' => (string) $absences->count(), 'detail' => null],
                ['label' => (string) __('people.reports.leave.kpis.movements'), 'value' => (string) $movements->count(), 'detail' => null],
            ],
            sections: [[
                'title' => $title,
                'lead' => (string) __('people.reports.leave.activity_lead'),
                'table' => PdfTable::make([
                    [__('people.reports.cols.person')], [__('people.reports.leave.cols.what')], [__('people.reports.leave.cols.type')], [__('people.reports.leave.cols.period')],
                    [__('people.reports.leave.cols.amount'), true], [__('people.reports.leave.cols.status')], [__('people.reports.leave.cols.by')],
                ], $pdfRows, compact: true, empty: (string) __('people.reports.leave.none')),
            ]],
            headers: self::leaveHeaders(['person', 'what', 'date', 'type', 'start', 'end', 'start_time', 'end_time', 'unit', 'amount', 'status', 'cancellation', 'first_approver', 'by', 'reason']),
            rows: $rows,
            notes: [(string) __('people.reports.leave.units_note')],
        );
    }

    public function missingDocuments(RegisterScope $scope): RegisterDocument
    {
        $title = PeopleReportKind::MissingDocuments->title();
        $ids = array_map(fn (User $user): int => $user->id, $scope->users);
        $today = LocalTime::todayString();
        $rows = [];
        $pdfRows = [];

        foreach (AbsenceDocuments::missing($ids, $scope->from, $scope->to) as $absence) {
            $name = AbsenceText::typeName($absence->leaveType, $absence->type);
            $started = $absence->start_date->toDateString() <= $today;
            $period = PeopleFormat::date($absence->start_date->toDateString()).($absence->start_date->equalTo($absence->end_date) ? '' : ' – '.PeopleFormat::date($absence->end_date->toDateString()));
            $person = $absence->user->name ?? '';

            $pdfRows[] = [$person, $name, $period, $absence->status->label(), $started ? (string) __('people.reports.leave.started') : (string) __('people.reports.leave.not_started')];
            $rows[] = [$person, $name, $absence->start_date->toDateString(), $absence->end_date->toDateString(), $absence->status->value, $started ? 1 : 0];
        }

        return new RegisterDocument(
            kind: 'missing_documents',
            title: $title,
            basename: $title.' '.$scope->from.' '.$scope->to,
            cover: $this->cover($title, $scope),
            kpis: [['label' => (string) __('people.reports.leave.kpis.missing'), 'value' => (string) count($rows), 'detail' => null]],
            sections: [[
                'title' => $title,
                'lead' => (string) __('people.reports.leave.missing_lead'),
                'table' => PdfTable::make([
                    [__('people.reports.cols.person')], [__('people.reports.leave.cols.type')], [__('people.reports.leave.cols.period')], [__('people.reports.leave.cols.status')], [__('people.reports.leave.cols.started')],
                ], $pdfRows, compact: true, empty: (string) __('people.reports.leave.no_missing')),
            ]],
            headers: self::leaveHeaders(['person', 'type', 'start', 'end', 'status', 'started']),
            rows: $rows,
            landscape: false,
        );
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function leaveHeaders(array $keys): array
    {
        return array_map(fn (string $key): string => (string) __("people.reports.leave.headers.{$key}"), $keys);
    }
}
