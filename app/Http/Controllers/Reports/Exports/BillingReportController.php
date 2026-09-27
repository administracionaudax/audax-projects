<?php

namespace App\Http\Controllers\Reports\Exports;

use App\Domain\Reports\BillingReport;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Client;
use App\Models\User;
use Generator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de horas para facturar (SPEC §10 «Exportación», D-045; R2): /informes/facturacion.
 * Un cliente (obligatorio, ?cliente[]=id) y un periodo con los filtros globales. La página muestra
 * el resumen por proyecto y bolsa (dentro, exceso, facturables y pendientes de aprobar; tarifas e
 * importes con view-financials) y ?formato=xlsx|csv descarga el detalle de cada entrada.
 *
 * Quién (ClientPolicy::viewBilling): admins y quien tenga view-financials. Las horas pasan por
 * ReportScope (D-044): un no admin con view-financials solo exporta las que puede ver.
 *
 * Límite (D-045: sin cola hasta 20.000 filas): si las entradas no caben en el fichero (con la fila
 * de totales), la exportación responde 422 en lugar de recortarlo, y la página avisa antes.
 */
class BillingReportController extends Controller
{
    use AuthorizesRequests, BuildsReportScope;

    public function __invoke(Request $request, BillingReport $billing, ReportCache $cache, TableExporter $exporter): Response|StreamedResponse
    {
        $this->authorize('viewBilling', Client::class);

        /** @var User $user */
        $user = $request->user();
        // Facturación no compara con el periodo anterior (INT-05): sin comparar=1 en la barra ni en sus enlaces.
        $urlFilters = ReportFilters::fromQuery($request->query())->withoutComparison();
        $client = $urlFilters->clientIds === [] ? null : Client::query()->find($urlFilters->clientIds[0], ['id', 'name', 'is_active']);

        $format = $this->exportFormat($request);

        if ($client === null) {
            abort_if($format !== null, 422, self::text('reports.r2.billing.client_required'));

            return $this->page($user, $urlFilters, null, null, $exporter->maxRows() - 1);
        }

        $scope = $this->reportScope($request, ['clientIds' => [$client->id]]);

        $summary = fn (): array => $cache->remember($scope, 'r2.billing.'.$client->id, fn (): array => $billing->summary($scope));

        if ($format !== null) {
            $entries = (clone $scope->entries())->count();
            abort_if($entries + 1 > $exporter->maxRows(), 422, self::text('reports.r2.billing.too_many_rows', [
                'entries' => number_format($entries, 0, ',', '.'),
                'max' => number_format($exporter->maxRows() - 1, 0, ',', '.'),
            ]));

            // El total del detalle es el del resumen (el mismo que enseña la página).
            $total = $scope->canSeeFinancials() ? $summary()['totals']['income'] : null;

            return $this->export($exporter, $billing, $scope, $client, $format, $total);
        }

        return $this->page($user, $urlFilters->with(['clientIds' => [$client->id]]), $client, $summary(), $exporter->maxRows() - 1);
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    private function page(User $user, ReportFilters $filters, ?Client $client, ?array $summary, int $exportLimit): Response
    {
        return Inertia::render('reports/billing', [
            'filters' => $this->filterProps(new ReportScope($user, $filters)),
            'client' => $client === null ? null : ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active],
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (Client $option): array => ['id' => $option->id, 'name' => $option->name, 'is_active' => $option->is_active])
                ->values()->all(),
            'summary' => $summary,
            'scope' => ['team_only' => ! $user->isAdmin()],
            // Entradas que caben en la exportación (sin la fila de totales).
            'export_limit' => $exportLimit,
            // El informe del cliente tiene sus propios permisos (ClientPolicy::viewReport).
            'can' => ['viewReport' => $client !== null && $user->can('viewReport', $client)],
        ]);
    }

    private function export(TableExporter $exporter, BillingReport $billing, ReportScope $scope, Client $client, string $format, ?string $total): StreamedResponse
    {
        $financials = $scope->canSeeFinancials();
        $c = fn (string $key): string => self::text('reports.r2.billing.columns.'.$key);

        $headers = [$c('date'), $c('person'), $c('project'), $c('bank'), $c('task'), $c('description'),
            $c('hours'), $c('in_bank'), $c('overage'), $c('billable'), $c('status')];
        if ($financials) {
            array_push($headers, $c('rate'), $c('amount'), $c('basis'));
        }

        return $exporter->download(
            self::text('reports.r2.billing.export_name', ['client' => $client->name]),
            $headers,
            $this->rows($billing, $scope, $financials, $total),
            $format,
        );
    }

    /**
     * Una fila por entrada y una de totales al final. El total del importe es el ingreso del
     * resumen ($total) y los importes de las entradas, en céntimos, suman exactamente ese total.
     *
     * @return Generator<int, array<int, string|int|float|bool|null>>
     */
    private function rows(BillingReport $billing, ReportScope $scope, bool $financials, ?string $total): Generator
    {
        $minutes = 0;
        $inBank = 0;
        $overage = 0;
        $amount = '0.00';
        $noBank = self::text('reports.r2.no_bank');
        /** @var array<string, string> $labels textos de estado y de valoración, traducidos una vez */
        $labels = [];

        foreach ($billing->entries($scope, $total) as ['entry' => $entry, 'valuation' => $valuation, 'amount' => $line]) {
            $inside = $entry['bank'] !== null;
            $minutes += $entry['minutes'];
            $inBank += $inside ? $entry['minutes'] - $entry['overage_minutes'] : 0;
            $overage += $entry['overage_minutes'];

            $row = [
                $entry['date'],
                $entry['person'],
                $entry['project_code'].' · '.$entry['project_name'],
                $entry['bank'] ?? $noBank,
                $entry['task'],
                $entry['description'],
                TableExporter::hours($entry['minutes']),
                $inside ? TableExporter::hours($entry['minutes'] - $entry['overage_minutes']) : null,
                $inside ? TableExporter::hours($entry['overage_minutes']) : null,
                $entry['is_billable'],
                $labels['status.'.$entry['status']->value] ??= $entry['status']->label(),
            ];

            if ($financials && $valuation !== null) {
                $amount = bcadd($amount, $line ?? '0', 2);
                array_push($row,
                    TableExporter::money($valuation['rate']),
                    TableExporter::money($line),
                    $labels['basis.'.$valuation['basis']] ??= self::text('reports.r2.billing.basis.'.$valuation['basis']),
                );
            }

            yield $row;
        }

        $totals = [self::text('reports.r2.total'), '', '', '', '', '', TableExporter::hours($minutes), TableExporter::hours($inBank), TableExporter::hours($overage), null, null];
        if ($financials) {
            array_push($totals, null, TableExporter::money($amount), null);
        }

        yield $totals;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
