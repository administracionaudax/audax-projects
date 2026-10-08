<?php

namespace App\Domain\Billing;

use App\Domain\Reports\Money;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Enums\BillingType;
use App\Enums\InvoiceLineKind;
use App\Enums\ProjectStatus;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\HourBank;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sugerencias de enlace de una factura de Holded sin enlazar (Fase 12, D-388 y D-396). En Holded las
 * etiquetas no llevan el código del proyecto, así que solo hay pistas: el cliente (por el contacto),
 * el servicio de sus líneas (bolsadehoras → un proyecto de bolsas y la bolsa de esa fecha; un fee →
 * un fee mensual; horas → un proyecto por horas o de precio cerrado) y la fecha. NUNCA enlaza sola:
 * quien tiene view-billing acepta la sugerencia (enlace manual).
 */
final class InvoiceLinkSuggester
{
    /** Sugerencias como mucho por factura. */
    public const int MAX = 3;

    /** @var array<int, Collection<int, Project>> proyectos de cada cliente (memoria de la petición) */
    private array $projects = [];

    /**
     * @return list<array{project: array{id: int, code: string, name: string}, bank: array{id: int, name: string}|null, reason: string}>
     */
    public function for(HoldedInvoice $invoice): array
    {
        if ($invoice->client_id === null) {
            return [];
        }

        $kind = self::dominantKind($invoice);
        $date = $invoice->issued_on;
        $candidates = [];

        foreach ($this->projectsOf($invoice->client_id) as $project) {
            $match = match ($kind) {
                InvoiceLineKind::HourBank => $project->usesHourBanks(),
                InvoiceLineKind::Fee => self::isFee($project),
                InvoiceLineKind::Hours => $project->billing_type === BillingType::TimeAndMaterials && ! self::isFee($project),
                default => $project->billing_type === BillingType::FixedPrice,
            };
            if (! $match) {
                continue;
            }

            $bank = $project->usesHourBanks() ? self::bankNear($project, $date) : null;
            $active = self::activeAt($project, $date);
            $candidates[] = [
                'score' => ($active ? 2 : 0) + ($bank !== null ? 1 : 0),
                'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                'bank' => $bank === null ? null : ['id' => $bank->id, 'name' => $bank->name],
                'reason' => $kind->value,
            ];
        }

        usort($candidates, fn (array $a, array $b): int => [$b['score'], $a['project']['code']] <=> [$a['score'], $b['project']['code']]);

        return array_values(array_map(fn (array $row): array => ['project' => $row['project'], 'bank' => $row['bank'], 'reason' => $row['reason']], array_slice($candidates, 0, self::MAX)));
    }

    /** El tipo de línea que más importa en la factura (por su base). */
    public static function dominantKind(HoldedInvoice $invoice): InvoiceLineKind
    {
        $totals = [];
        foreach ($invoice->lines as $line) {
            $kind = self::lineKind($line)->value;
            $totals[$kind] = Money::add($totals[$kind] ?? '0', Money::abs((string) $line->subtotal));
        }
        uasort($totals, fn (string $a, string $b): int => bccomp($b, $a, 6));

        return InvoiceLineKind::tryFrom((string) array_key_first($totals)) ?? InvoiceLineKind::Other;
    }

    public static function lineKind(HoldedInvoiceLine $line): InvoiceLineKind
    {
        return InvoiceLineKind::classify($line->service_code, $line->name, (string) $line->units);
    }

    private static function isFee(Project $project): bool
    {
        return $project->billing_type === BillingType::MonthlyFee
            || ($project->billing_type === BillingType::TimeAndMaterials && WeeklyProjectStatus::isMonthlyFee($project));
    }

    private static function activeAt(Project $project, CarbonImmutable $date): bool
    {
        return ($project->start_date === null || $project->start_date->subDays(31)->lessThanOrEqualTo($date))
            && ($project->due_date === null || $project->due_date->addDays(62)->greaterThanOrEqualTo($date));
    }

    /** La bolsa cuyo inicio queda más cerca de la fecha de la factura (se suele facturar al empezar). */
    private static function bankNear(Project $project, CarbonImmutable $date): ?HourBank
    {
        return HourBank::query()->where('project_id', $project->id)->get(['id', 'name', 'start_date', 'project_id'])
            ->sortBy(fn (HourBank $bank): int => (int) abs($bank->start_date->diffInDays($date)))
            ->first();
    }

    /**
     * @return Collection<int, Project>
     */
    private function projectsOf(int $clientId): Collection
    {
        return $this->projects[$clientId] ??= Project::query()
            ->where('client_id', $clientId)
            ->where('billing_type', '!=', BillingType::Internal->value)
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->orderBy('code')
            ->get();
    }
}
