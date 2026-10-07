<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceStatus;
use App\Models\Absence;
use App\Models\AbsenceDocument;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Lo que R3 añade a cada ausencia en la interfaz (Fase 11; resources/js/types/leave.ts, AbsenceLeave),
 * solo con el módulo `people` visible para quien mira (LeaveMode): el tipo del catálogo, la franja,
 * lo que cuesta, los justificantes (o solo cuántos hay, si quien mira no puede verlos), los avisos,
 * el primer nivel de aprobación y la cancelación pedida. Con el módulo apagado no se añade nada y
 * /ausencias se ve como en la Fase 3.
 *
 * @phpstan-import-type Warning from AbsenceAdvisor
 */
final class LeavePresenter
{
    public function __construct(
        private readonly AbsenceCost $cost,
        private readonly AbsenceAdvisor $advisor,
    ) {}

    /**
     * @param  iterable<Absence>  $absences
     * @return array<int, array<string, mixed>> id → datos de R3
     */
    public function forAbsences(iterable $absences, User $viewer, bool $withWarnings = true): array
    {
        if (! LeaveMode::on($viewer)) {
            return [];
        }

        $absences = new EloquentCollection(array_values(Collection::make($absences)->all()));

        if ($absences->isEmpty()) {
            return [];
        }

        $absences->loadMissing(['leaveType', 'documents', 'firstApprover:id,name', 'user']);
        $costs = $this->cost->forAbsences($absences);
        $result = [];

        foreach ($absences as $absence) {
            $days = $costs[$absence->id] ?? [];
            $type = $absence->leaveType;
            $seesDocuments = AbsenceDocuments::canView($viewer, $absence);
            /** @var list<Warning> $warnings */
            $warnings = $withWarnings && $type !== null && in_array($absence->status, [AbsenceStatus::Requested, AbsenceStatus::Approved], true) && $absence->end_date->toDateString() >= LocalTime::todayString()
                ? $this->advisor->forAbsence($absence, $days)
                : [];

            $result[$absence->id] = [
                'type' => $type === null ? null : self::type($type),
                'start_time' => $absence->start_time,
                'end_time' => $absence->end_time,
                'cost' => AbsenceCost::total($days),
                'documents_count' => $absence->documents->count(),
                'documents' => $seesDocuments ? $absence->documents->map(fn (AbsenceDocument $document): array => [
                    'id' => $document->id,
                    'name' => $document->original_name,
                    'size' => $document->size,
                    'created_at' => $document->created_at?->toIso8601ZuluString(),
                    'can_delete' => AbsenceDocuments::canDelete($viewer, $document, $absence),
                ])->values()->all() : null,
                'warnings' => $warnings,
                'first_approval' => $absence->first_approved_at === null ? null : [
                    'by' => $absence->firstApprover?->name,
                    'at' => $absence->first_approved_at->toIso8601ZuluString(),
                ],
                'awaiting_second' => $absence->status === AbsenceStatus::Requested && $absence->first_approved_at !== null,
                'cancellation' => $absence->cancellation_status === null ? null : [
                    'status' => $absence->cancellation_status->value,
                    'reason' => $absence->cancellation_reason,
                    'requested_at' => $absence->cancellation_requested_at?->toIso8601ZuluString(),
                    'comment' => $absence->cancellation_comment,
                    'decided_at' => $absence->cancellation_decided_at?->toIso8601ZuluString(),
                ],
                'can' => [
                    'upload' => AbsenceDocuments::canUpload($viewer, $absence),
                    'request_cancellation' => Gate::forUser($viewer)->allows('requestCancellation', $absence),
                    'decide_cancellation' => Gate::forUser($viewer)->allows('decideCancellation', $absence),
                ],
            ];
        }

        return $result;
    }

    /**
     * Un tipo del catálogo como lo recibe la interfaz (LeaveTypeOption).
     *
     * @return array<string, mixed>
     */
    public static function type(LeaveType $type): array
    {
        return [
            'id' => $type->id,
            'key' => $type->key,
            'name' => $type->name,
            'category' => $type->category->value,
            'unit' => $type->unit->value,
            'paid' => $type->paid,
            'requires_document' => $type->requires_document,
            'notice_days' => $type->notice_days,
            'health_data' => $type->health_data,
            'default_amount' => $type->default_amount,
            'travel_extra' => $type->travel_extra,
            'annual_allowance' => $type->annual_allowance,
            'allowance_in_days' => $type->allowance_in_days,
            'allow_without_balance' => $type->allow_without_balance,
            'second_approval' => $type->needsSecondApproval(),
            'respects_blocked_days' => $type->respects_blocked_days,
            'description' => $type->description,
            'legal_basis' => $type->legal_basis,
            'advisor_pending' => $type->advisor_pending,
            'active' => $type->active,
        ];
    }

    /**
     * Los saldos del año como los recibe la interfaz (LeaveBalance).
     *
     * @param  list<array<string, mixed>>  $summaries  LeaveBalances::forUser
     * @return list<array<string, mixed>>
     */
    public static function balances(array $summaries): array
    {
        return array_map(function (array $summary): array {
            /** @var LeaveType $type */
            $type = $summary['type'];

            return [
                'type' => ['id' => $type->id, 'name' => $type->name, 'unit' => $type->unit->value, 'allow_without_balance' => $type->allow_without_balance],
                'year' => $summary['year'],
                'entitled' => $summary['entitled'],
                'adjusted' => $summary['adjusted'],
                'total' => $summary['total'],
                'used' => $summary['used'],
                'pending' => $summary['pending'],
                'available' => $summary['available'],
                'carried' => $summary['carried'],
                'expired' => $summary['expired'],
                'expiring' => $summary['expiring'],
            ];
        }, $summaries);
    }
}
