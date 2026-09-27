<?php

namespace App\Http\Controllers\Portal\Banks;

use App\Domain\Portal\PortalBankFigures;
use App\Domain\Portal\PortalScope;
use App\Enums\ProjectStatus;
use App\Models\HourBank;
use Illuminate\Database\Eloquent\Collection;

/**
 * Datos de las bolsas para las páginas del portal (SPEC §11, D-064), en arrays explícitos con la
 * forma de resources/js/components/portal/banks/types.ts. Nunca el modelo serializado: el precio,
 * la tarifa, la referencia de factura y las notas internas de la bolsa no viajan al navegador.
 *
 * @phpstan-type Figures array{total_minutes: int, within_minutes: int, overage_minutes: int, remaining_minutes: int, percent: float}
 * @phpstan-type BankItem array{id: int, name: string, project: array{code: string, name: string}, status: string, start_date: string, end_date: string|null, closed_at: string|null, figures: Figures}
 */
final class PortalBankData
{
    /** Columnas de la bolsa que usa el portal (sin price_amount, hourly_rate, invoice_reference ni notes). */
    public const array COLUMNS = ['id', 'project_id', 'name', 'total_minutes', 'start_date', 'end_date', 'status', 'renewed_from_id', 'closed_at'];

    /**
     * Todas las bolsas del cliente (cualquier estado, D-064) con su proyecto, en una consulta más la
     * del proyecto.
     *
     * @return Collection<int, HourBank>
     */
    public static function banks(PortalScope $scope): Collection
    {
        return $scope->hourBanks()
            ->with(['project' => fn ($project) => $project->select(['id', 'code', 'name', 'status'])])
            ->get(self::COLUMNS);
    }

    /**
     * @param  Figures  $figures
     * @return BankItem
     */
    public static function item(HourBank $bank, array $figures): array
    {
        return [
            'id' => $bank->id,
            'name' => $bank->name,
            'project' => ['code' => $bank->project->code, 'name' => $bank->project->name],
            'status' => PortalBankFigures::status($bank, $figures)->value,
            'start_date' => $bank->start_date->toDateString(),
            'end_date' => $bank->end_date?->toDateString(),
            'closed_at' => $bank->closed_at?->toIso8601ZuluString(),
            'figures' => $figures,
        ];
    }

    /**
     * ¿Sale entre las bolsas activas del inicio? Activa o agotada, y de un proyecto sin archivar
     * (como en la vista global de bolsas, D-054). Las demás forman el histórico.
     */
    public static function isOpen(HourBank $bank): bool
    {
        return $bank->status->acceptsTime() && $bank->project->status !== ProjectStatus::Archived;
    }

    /**
     * Umbral de «cerca del límite», en %: el primero configurado (D-035), como mucho el 100 %.
     *
     * @param  list<int>  $thresholds
     */
    public static function firstThreshold(array $thresholds): int
    {
        return min($thresholds[0] ?? 75, 100);
    }
}
