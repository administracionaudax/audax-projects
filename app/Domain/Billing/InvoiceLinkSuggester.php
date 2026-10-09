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
use Illuminate\Database\Eloquent\Builder;
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

    /** @var array<int, \Illuminate\Support\Collection<int, HourBank>> bolsas de cada proyecto (memoria de la petición) */
    private array $banks = [];

    /**
     * Carga de una vez los proyectos de los clientes de unas facturas y sus bolsas (el listado, D-406):
     * dos consultas para toda la página en vez de una por cliente y otra por proyecto y factura.
     *
     * @param  iterable<HoldedInvoice>  $invoices
     */
    public function prime(iterable $invoices): void
    {
        $clients = [];
        foreach ($invoices as $invoice) {
            if ($invoice->client_id !== null && ! isset($this->projects[$invoice->client_id])) {
                $clients[$invoice->client_id] = true;
            }
        }

        if ($clients === []) {
            return;
        }

        $projects = $this->projectQuery()->whereIn('client_id', array_keys($clients))->get();
        foreach (array_keys($clients) as $clientId) {
            $this->projects[$clientId] = $projects->where('client_id', $clientId)->values();
        }

        $this->primeBanks(array_values($projects->filter(fn (Project $project): bool => $project->usesHourBanks())->map(fn (Project $project): int => $project->id)->all()));
    }

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

            $bank = $project->usesHourBanks() ? $this->bankNear($project, $date) : null;
            $active = self::activeAt($project, $date);
            $candidates[] = [
                'score' => ($active ? 2 : 0) + ($bank !== null ? 1 : 0),
                'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                'bank' => $bank === null ? null : ['id' => $bank->id, 'name' => $bank->name],
                'reason' => $kind->value,
            ];
        }

        usort($candidates, fn (array $a, array $b): int => [$b['score'], $a['project']['code']] <=> [$a['score'], $b['project']['code']]);

        return array_map(fn (array $row): array => ['project' => $row['project'], 'bank' => $row['bank'], 'reason' => $row['reason']], array_slice($candidates, 0, self::MAX));
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
    private function bankNear(Project $project, CarbonImmutable $date): ?HourBank
    {
        if (! isset($this->banks[$project->id])) {
            $this->primeBanks([$project->id]);
        }

        return $this->banks[$project->id]
            ->sortBy(fn (HourBank $bank): int => (int) abs($bank->start_date->diffInDays($date)))
            ->first();
    }

    /**
     * @param  list<int>  $projectIds
     */
    private function primeBanks(array $projectIds): void
    {
        $missing = array_values(array_filter($projectIds, fn (int $id): bool => ! isset($this->banks[$id])));

        if ($missing === []) {
            return;
        }

        $banks = HourBank::query()->whereIn('project_id', $missing)->orderBy('start_date')->orderBy('id')->get(['id', 'name', 'start_date', 'project_id']);
        foreach ($missing as $projectId) {
            $this->banks[$projectId] = $banks->where('project_id', $projectId)->values();
        }
    }

    /**
     * @return Builder<Project>
     */
    private function projectQuery(): Builder
    {
        return Project::query()
            ->where('billing_type', '!=', BillingType::Internal->value)
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->orderBy('code')
            ->orderBy('id');
    }

    /**
     * @return Collection<int, Project>
     */
    private function projectsOf(int $clientId): Collection
    {
        return $this->projects[$clientId] ??= $this->projectQuery()->where('client_id', $clientId)->get();
    }
}
