<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Work\UnbilledWork;
use App\Domain\Billing\Work\WorkDraft;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * «Crear borrador» desde el trabajo (PLAN-EMISION §3.8 y §6.2; E3): el panel lateral pide lo que un
 * cliente tiene por facturar (JSON, con las horas agrupadas como se elija) y crea el borrador con lo
 * que se marque, que se abre en el editor. Desde Por facturar, la ficha del proyecto o de la bolsa
 * y el Resumen. Quién: quien prepara facturas (use-invoicing, en la ruta, con los módulos y las
 * exclusiones de D-245); emitirlo, como siempre, manage-billing.
 */
class WorkDraftController extends Controller
{
    public function show(Request $request, Client $client, UnbilledWork $work): JsonResponse
    {
        $data = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'agrupar' => ['nullable', Rule::in(UnbilledWork::GROUPINGS)],
            'proyecto' => ['nullable', 'integer'],
            'bolsa' => ['nullable', 'integer'],
        ]);
        $today = LocalTime::today();
        $from = isset($data['desde']) ? CarbonImmutable::parse((string) $data['desde']) : $today->startOfYear();
        $to = isset($data['hasta']) ? CarbonImmutable::parse((string) $data['hasta']) : $today;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return response()->json($work->forClient(
            $client,
            $from,
            $to,
            (string) ($data['agrupar'] ?? 'task'),
            isset($data['proyecto']) ? (int) $data['proyecto'] : null,
            isset($data['bolsa']) ? (int) $data['bolsa'] : null,
        ));
    }

    public function store(Request $request, Client $client, WorkDraft $drafts): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'agrupar' => ['required', Rule::in(UnbilledWork::GROUPINGS)],
            'entries' => ['array', 'max:5000'],
            'entries.*' => ['integer'],
            'overage_entries' => ['array', 'max:5000'],
            'overage_entries.*' => ['integer'],
            'banks' => ['array', 'max:100'],
            'banks.*' => ['integer'],
            'fees' => ['array', 'max:120'],
            'fees.*.project_id' => ['required', 'integer'],
            'fees.*.month' => ['required', 'date_format:Y-m'],
            'fixed' => ['array', 'max:50'],
            'fixed.*.project_id' => ['required', 'integer'],
            'fixed.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'fixed.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $document = $drafts->create($client, [
            'entries' => array_values(array_map('intval', (array) ($data['entries'] ?? []))),
            'overage_entries' => array_values(array_map('intval', (array) ($data['overage_entries'] ?? []))),
            'banks' => array_values(array_map('intval', (array) ($data['banks'] ?? []))),
            'fees' => array_values(array_map(fn (array $fee): array => ['project_id' => (int) $fee['project_id'], 'month' => (string) $fee['month']], (array) ($data['fees'] ?? []))),
            'fixed' => array_values(array_map(fn (array $item): array => [
                'project_id' => (int) $item['project_id'],
                'amount' => (string) $item['amount'],
                'description' => isset($item['description']) ? (string) $item['description'] : null,
            ], (array) ($data['fixed'] ?? []))),
        ], (string) $data['agrupar'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing_work.work.flash.created')]);

        return redirect($document->url().'/editar');
    }
}
