<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Holded\HoldedPayload;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLinkMethod;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HoldedProject;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enlaza las facturas de Holded con los proyectos y las bolsas de Audax (Fase 12, F1; D-388). Es la
 * ÚNICA pieza que escribe `holded_invoice_links`.
 *
 * Automáticos (se rehacen en cada sincronización):
 * 1. **Código F** (D-135): el número de la factura es el `invoice_reference` de una bolsa (→ esa
 *    bolsa) o el «Factura: F…» de la descripción de un proyecto (→ el proyecto).
 * 2. **Proyecto de Holded**: una línea con un proyecto de Holded enlazado con uno de Audax. Si es de
 *    bolsas, va a la bolsa vigente en la fecha de la factura (o a la última que empezó antes).
 * 3. Una rectificativa sin enlaces propios hereda los de la factura que rectifica.
 * Manuales: desde la factura, por quien tiene view-billing. La sincronización nunca los toca y un
 * enlace automático no se duplica con uno manual.
 */
final class HoldedInvoiceLinker
{
    /** @var array<string, list<int>>|null código normalizado → bolsas */
    private ?array $bankCodes = null;

    /** @var array<string, list<int>>|null código normalizado → proyectos */
    private ?array $projectCodes = null;

    /** @var array<string, int>|null proyecto de Holded → proyecto de Audax */
    private ?array $holdedProjects = null;

    /**
     * Rehace los enlaces automáticos de todas las facturas (o de las dadas). Devuelve cuántas
     * facturas quedan con algún enlace.
     *
     * @param  iterable<HoldedInvoice>|null  $invoices
     */
    public function relink(?iterable $invoices = null): int
    {
        $this->load();
        $linked = 0;

        $invoices ??= HoldedInvoice::query()->orderBy('kind')->orderBy('issued_on')->orderBy('id')->lazy(200);
        $credits = [];

        foreach ($invoices as $invoice) {
            if ($invoice->kind === HoldedDocumentKind::CreditNote) {
                $credits[] = $invoice;

                continue;
            }
            $linked += $this->sync($invoice, $this->desired($invoice)) ? 1 : 0;
        }

        // Las rectificativas, después: pueden heredar los enlaces de la original.
        foreach ($credits as $credit) {
            $desired = $this->desired($credit);
            if ($desired === [] && $credit->rectified_invoice_id !== null) {
                $desired = HoldedInvoiceLink::query()->where('holded_invoice_id', $credit->rectified_invoice_id)
                    ->orderBy('id')->get()
                    ->map(fn (HoldedInvoiceLink $link): array => [$link->project_id, $link->hour_bank_id, InvoiceLinkMethod::Rectified])
                    ->all();
                $desired = array_values($desired);
            }
            $linked += $this->sync($credit, $desired) ? 1 : 0;
        }

        return $linked;
    }

    /** Enlace a mano (desde la ficha de la factura). La bolsa, si se da, tiene que ser del proyecto. */
    public function link(HoldedInvoice $invoice, Project $project, ?HourBank $bank, User $by): HoldedInvoiceLink
    {
        if ($bank !== null && $bank->project_id !== $project->id) {
            throw ValidationException::withMessages(['hour_bank_id' => __('billing.invoices.errors.bank_not_in_project')]);
        }
        if ($project->isInternal()) {
            throw ValidationException::withMessages(['project_id' => __('billing.invoices.errors.internal_project')]);
        }

        return DB::transaction(function () use ($invoice, $project, $bank, $by): HoldedInvoiceLink {
            $existing = $this->find($invoice->id, $project->id, $bank?->id);
            if ($existing !== null) {
                $existing->forceFill(['method' => InvoiceLinkMethod::Manual, 'created_by' => $by->id])->save();

                return $existing;
            }

            return HoldedInvoiceLink::query()->create([
                'holded_invoice_id' => $invoice->id,
                'project_id' => $project->id,
                'hour_bank_id' => $bank?->id,
                'method' => InvoiceLinkMethod::Manual,
                'created_by' => $by->id,
            ]);
        });
    }

    /** Quita un enlace. Uno automático volvería en la siguiente sincronización: por eso solo se quitan los manuales. */
    public function unlink(HoldedInvoiceLink $link): void
    {
        if ($link->method->automatic()) {
            throw ValidationException::withMessages(['link' => __('billing.invoices.errors.automatic_link')]);
        }

        $link->delete();
    }

    /** Olvida los códigos y proyectos leídos. */
    public function reset(): void
    {
        $this->bankCodes = null;
        $this->projectCodes = null;
        $this->holdedProjects = null;
    }

    /**
     * Enlaces automáticos que le tocan a una factura: [proyecto, bolsa|null, método].
     *
     * @return list<array{0: int, 1: int|null, 2: InvoiceLinkMethod}>
     */
    private function desired(HoldedInvoice $invoice): array
    {
        $desired = [];
        $code = $invoice->number_normalized;

        if ($code !== null) {
            foreach ($this->bankCodes[$code] ?? [] as $bankId) {
                $bank = HourBank::query()->withTrashed()->find($bankId, ['id', 'project_id']);
                if ($bank !== null) {
                    $desired[$bank->project_id.'|'.$bank->id] = [$bank->project_id, $bank->id, InvoiceLinkMethod::FCode];
                }
            }
            foreach ($this->projectCodes[$code] ?? [] as $projectId) {
                $desired[$projectId.'|'] ??= [$projectId, null, InvoiceLinkMethod::FCode];
            }
        }

        $holdedProjectIds = $invoice->lines()->reorder()->whereNotNull('holded_project_id')->orderBy('holded_project_id')->distinct()->pluck('holded_project_id')->all();
        foreach ($holdedProjectIds as $holdedProjectId) {
            $projectId = $this->holdedProjects[(string) $holdedProjectId] ?? null;
            if ($projectId === null || $this->hasProject($desired, $projectId)) {
                continue;
            }
            $bankId = $this->bankAt($projectId, $invoice->issued_on);
            $desired[$projectId.'|'.($bankId ?? '')] = [$projectId, $bankId, InvoiceLinkMethod::HoldedProject];
        }

        return array_values($desired);
    }

    /**
     * Deja los enlaces automáticos de la factura como $desired (los manuales no se tocan).
     *
     * @param  list<array{0: int, 1: int|null, 2: InvoiceLinkMethod}>  $desired
     */
    private function sync(HoldedInvoice $invoice, array $desired): bool
    {
        $current = HoldedInvoiceLink::query()->where('holded_invoice_id', $invoice->id)->orderBy('id')->get();
        $keep = [];

        foreach ($desired as [$projectId, $bankId, $method]) {
            $key = $projectId.'|'.($bankId ?? '');
            $keep[$key] = true;
            $existing = $current->first(fn (HoldedInvoiceLink $link): bool => $link->project_id === $projectId && $link->hour_bank_id === $bankId);

            if ($existing === null) {
                HoldedInvoiceLink::query()->create(['holded_invoice_id' => $invoice->id, 'project_id' => $projectId, 'hour_bank_id' => $bankId, 'method' => $method]);
            } elseif ($existing->method->automatic() && $existing->method !== $method) {
                $existing->forceFill(['method' => $method])->save();
            }
        }

        foreach ($current as $link) {
            if ($link->method->automatic() && ! isset($keep[$link->project_id.'|'.($link->hour_bank_id ?? '')])) {
                $link->delete();
            }
        }

        return HoldedInvoiceLink::query()->where('holded_invoice_id', $invoice->id)->exists();
    }

    /**
     * @param  array<string, array{0: int, 1: int|null, 2: InvoiceLinkMethod}>  $desired
     */
    private function hasProject(array $desired, int $projectId): bool
    {
        foreach ($desired as [$id]) {
            if ($id === $projectId) {
                return true;
            }
        }

        return false;
    }

    /** Bolsa vigente en $date de un proyecto de bolsas (o la última que empezó antes); null si no es de bolsas. */
    private function bankAt(int $projectId, CarbonImmutable $date): ?int
    {
        $project = Project::query()->withTrashed()->find($projectId, ['id', 'billing_type']);
        if ($project === null || ! $project->usesHourBanks()) {
            return null;
        }

        $day = $date->toDateString();
        $bank = HourBank::query()->where('project_id', $projectId)
            ->where('start_date', '<=', $day)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $day))
            ->orderByDesc('start_date')->orderByDesc('id')->first(['id'])
            ?? HourBank::query()->where('project_id', $projectId)->where('start_date', '<=', $day)
                ->orderByDesc('start_date')->orderByDesc('id')->first(['id']);

        return $bank?->id;
    }

    private function find(int $invoiceId, int $projectId, ?int $bankId): ?HoldedInvoiceLink
    {
        return HoldedInvoiceLink::query()
            ->where('holded_invoice_id', $invoiceId)
            ->where('project_id', $projectId)
            ->when($bankId === null, fn ($query) => $query->whereNull('hour_bank_id'), fn ($query) => $query->where('hour_bank_id', $bankId))
            ->first();
    }

    private function load(): void
    {
        if ($this->bankCodes !== null && $this->projectCodes !== null && $this->holdedProjects !== null) {
            return;
        }

        $this->bankCodes = [];
        foreach (HourBank::query()->whereNotNull('invoice_reference')->orderBy('id')->get(['id', 'invoice_reference']) as $bank) {
            foreach (self::codes((string) $bank->invoice_reference) as $code) {
                $this->bankCodes[$code][] = $bank->id;
            }
        }

        $this->projectCodes = [];
        foreach (Project::query()->where('description', 'like', '%Factura%')->orderBy('id')->get(['id', 'description', 'billing_type']) as $project) {
            if ($project->isInternal()) {
                continue;
            }
            preg_match_all('/Factura:\s*([A-Za-z0-9][A-Za-z0-9\/-]*[A-Za-z0-9])/u', (string) $project->description, $matches);
            foreach ($matches[1] as $code) {
                $normalized = HoldedPayload::normalizeNumber($code);
                if ($normalized !== null) {
                    $this->projectCodes[$normalized][] = $project->id;
                }
            }
        }

        $this->holdedProjects = HoldedProject::query()->whereNotNull('project_id')->pluck('project_id', 'holded_id')
            ->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Los códigos de una referencia («F260170» o «F260170, F260171»).
     *
     * @return list<string>
     */
    public static function codes(string $reference): array
    {
        $codes = [];
        foreach (preg_split('/[,;]+|\s+y\s+/u', $reference) ?: [] as $part) {
            $code = HoldedPayload::normalizeNumber($part);
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }
}
