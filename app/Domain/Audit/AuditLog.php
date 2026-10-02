<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Consulta de la auditoría visible (D-074) sobre activity_log, de lo más reciente a lo más
 * antiguo (id descendente, para paginar por cursor). Cada filtro tiene su índice: (log_name,
 * created_at), (causer_type, causer_id, created_at), (event, created_at) y created_at
 * (migración 2026_10_02_110000).
 */
final class AuditLog
{
    /** Entradas por página. */
    public const int PER_PAGE = 50;

    /**
     * @return Builder<Activity>
     */
    public function query(AuditFilters $filters): Builder
    {
        $query = Activity::query();

        if ($filters->entity !== null) {
            $query->whereIn('log_name', AuditCatalog::ENTITIES[$filters->entity]);
        }

        if ($filters->person === AuditFilters::SYSTEM) {
            $query->whereNull('causer_id');
        } elseif (is_int($filters->person)) {
            $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $filters->person);
        }

        if ($filters->action !== null) {
            $query->whereIn('event', AuditCatalog::ACTIONS[$filters->action]);
        }

        if (($from = $filters->fromUtc()) !== null) {
            $query->where('created_at', '>=', $from);
        }

        if (($to = $filters->toUtc()) !== null) {
            $query->where('created_at', '<', $to);
        }

        return $query->orderByDesc('id');
    }
}
