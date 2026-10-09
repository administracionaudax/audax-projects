<?php

use App\Domain\Billing\InvoiceLinkSuggester;
use App\Domain\Billing\SoldVsActual;
use App\Domain\Billing\SoldVsActualQuery;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceLinkMethod;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\HoldedInvoiceLink;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

/*
| Cómo factura Audax en Holded (Fase 12, D-395 y D-396): el servicio de cada línea (bolsadehoras con
| horas × €/h y descuento, fees de una unidad, horas, gastos repercutidos), los borradores de las
| recurrentes como «previsto» y las sugerencias de enlace por cliente, servicio y fecha (las etiquetas
| no llevan el proyecto).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'Europe/Madrid'));
    $this->admin = userWithRole('admin');
    $this->client = Client::factory()->create(['name' => 'Pinturas Montó']);

    $this->invoice = function (string $number, string $date, array $lines, array $attributes = []): HoldedInvoice {
        $subtotal = array_reduce($lines, fn (string $sum, array $line): string => bcadd($sum, $line['subtotal'], 2), '0.00');
        $invoice = HoldedInvoice::query()->create([
            'holded_id' => 'h-'.$number, 'kind' => HoldedDocumentKind::Invoice, 'number' => $number, 'number_normalized' => $number,
            'client_id' => $this->client->id, 'issued_on' => $date, 'subtotal' => $subtotal, 'tax_total' => '0', 'total' => $subtotal,
            'paid_total' => '0', 'pending_total' => $subtotal, 'collection_status' => CollectionStatus::Unpaid, ...$attributes,
        ]);
        foreach ($lines as $position => $line) {
            HoldedInvoiceLine::query()->create(['holded_invoice_id' => $invoice->id, 'position' => $position + 1, 'units' => '1', 'unit_price' => '0', ...$line]);
        }

        return $invoice->load('lines');
    };
});

it('clasifica el servicio de cada línea por su código o su nombre', function (?string $code, string $name, string $units, InvoiceLineKind $kind) {
    expect(InvoiceLineKind::classify($code, $name, $units))->toBe($kind);
})->with([
    'bolsa por código' => ['BDH', 'bolsadehoras', '25', InvoiceLineKind::HourBank],
    'bolsa por nombre' => [null, 'Bolsa de horas', '100', InvoiceLineKind::HourBank],
    'fee de producto' => ['F_UX', 'Fee Producto digital', '1', InvoiceLineKind::Fee],
    'fee de marketing' => ['FMKRRSS', 'Fee MK y RRSS', '1', InvoiceLineKind::Fee],
    'desarrollo por horas' => ['DES', 'Desarrollo', '8', InvoiceLineKind::Hours],
    'diseño por horas' => ['D_UX_UI', 'Diseño Producto UX/UI', '58', InvoiceLineKind::Hours],
    'diseño de importe cerrado' => ['D_UX_UI', 'Diseño Producto UX/UI', '1', InvoiceLineKind::Other],
    'inversión en medios' => [null, 'Inversión', '1', InvoiceLineKind::PassThrough],
    'herramienta' => [null, 'Herramienta', '3', InvoiceLineKind::PassThrough],
]);

it('sugiere el proyecto por el cliente, el servicio y la fecha; la bolsa más cercana a la fecha', function () {
    $bankProject = Project::factory()->hourBank()->create(['client_id' => $this->client->id, 'code' => 'MON-BH']);
    $old = HourBank::factory()->create(['project_id' => $bankProject->id, 'name' => 'Bolsa 2.º trimestre', 'start_date' => '2026-04-01']);
    $new = HourBank::factory()->create(['project_id' => $bankProject->id, 'name' => 'Bolsa 4.º trimestre', 'start_date' => '2026-10-01']);
    $fee = Project::factory()->monthlyFee()->create(['client_id' => $this->client->id, 'code' => 'MON-FE1', 'start_date' => '2026-01-01']);
    $hourly = Project::factory()->create(['client_id' => $this->client->id, 'code' => 'MON-WEB']);
    Project::factory()->monthlyFee()->create(['code' => 'OTRO-FE1']);

    $bankInvoice = ($this->invoice)('F260194', '2026-10-02', [['name' => 'bolsadehoras', 'service_code' => 'BDH', 'units' => '100', 'subtotal' => '5100.00']]);
    $feeInvoice = ($this->invoice)('F260195', '2026-10-03', [['name' => 'Fee Producto digital', 'service_code' => 'F_UX', 'subtotal' => '6454.00']]);
    $hoursInvoice = ($this->invoice)('F260196', '2026-10-04', [['name' => 'Desarrollo', 'service_code' => 'DES', 'units' => '8', 'subtotal' => '560.00'], ['name' => 'Herramienta', 'subtotal' => '0']]);

    $suggester = app(InvoiceLinkSuggester::class);
    expect($suggester->for($bankInvoice)[0])->toMatchArray(['project' => ['id' => $bankProject->id, 'code' => 'MON-BH', 'name' => $bankProject->name], 'bank' => ['id' => $new->id, 'name' => 'Bolsa 4.º trimestre'], 'reason' => 'bank'])
        ->and(array_column(array_column($suggester->for($feeInvoice), 'project'), 'code'))->toBe(['MON-FE1'])
        ->and(array_column(array_column($suggester->for($hoursInvoice), 'project'), 'code'))->toBe(['MON-WEB'])
        ->and($old->id)->not->toBe($new->id)
        ->and($hourly->id)->toBeInt();

    // Sin cliente, sin sugerencias.
    $bankInvoice->update(['client_id' => null]);
    expect($suggester->for($bankInvoice->fresh()))->toBe([]);
});

it('el informe lee las horas de las líneas: lo facturado por horas y el precio de una bolsa sin precio; los borradores, como previsto', function () {
    $worker = userWithRole('employee');
    $bankProject = Project::factory()->hourBank()->create(['client_id' => $this->client->id, 'code' => 'MON-BH']);
    $bank = HourBank::factory()->create(['project_id' => $bankProject->id, 'total_minutes' => 100 * 60, 'price_amount' => null, 'start_date' => '2026-10-01']);
    $hourly = Project::factory()->create(['client_id' => $this->client->id, 'code' => 'MON-WEB', 'hourly_rate' => '70.00']);
    $fee = Project::factory()->monthlyFee(20, '1500.00')->create(['client_id' => $this->client->id, 'code' => 'MON-FE1', 'start_date' => '2026-10-01']);

    $task = Task::factory()->create(['project_id' => $hourly->id]);
    TimeEntry::factory()->forTask($task)->minutes(600)->status(TimeEntryStatus::Approved)->on('2026-10-05')->create(['user_id' => $worker->id]);

    $link = fn (HoldedInvoice $invoice, Project $project, ?HourBank $bank = null) => HoldedInvoiceLink::query()->create(['holded_invoice_id' => $invoice->id, 'project_id' => $project->id, 'hour_bank_id' => $bank?->id, 'method' => InvoiceLinkMethod::Manual]);
    // Bolsa de 100 h a 60 € con un 15 % de descuento: 5.100 €.
    $link(($this->invoice)('F260194', '2026-10-02', [['name' => 'bolsadehoras', 'service_code' => 'BDH', 'units' => '100', 'unit_price' => '60', 'discount_pct' => '15', 'subtotal' => '5100.00']]), $bankProject, $bank);
    // 8 h de desarrollo a 70 € (y una herramienta que no son horas).
    $link(($this->invoice)('F260196', '2026-10-04', [['name' => 'Desarrollo', 'service_code' => 'DES', 'units' => '8', 'subtotal' => '560.00'], ['name' => 'Herramienta', 'units' => '1', 'subtotal' => '30.00']]), $hourly);
    // El borrador de la recurrente del fee.
    $link(($this->invoice)('', '2026-10-07', [['name' => 'Fee Producto digital', 'service_code' => 'F_UX', 'subtotal' => '1500.00']], ['number' => null, 'number_normalized' => null, 'holded_id' => 'h-draft', 'is_draft' => true, 'collection_status' => CollectionStatus::Draft]), $fee);

    $report = app(SoldVsActual::class)->report(SoldVsActualQuery::fromQuery(['periodo' => 'mes', 'fecha' => '2026-10-08']), $this->admin, true);
    $unit = fn (string $key): array => collect($report['units'])->firstWhere('key', $key);

    expect($unit('bank:'.$bank->id))->toMatchArray(['sold_minutes' => 6000, 'sold_amount' => '5100.00', 'sold_source' => 'holded', 'invoiced' => '5100.00', 'invoiced_minutes' => 6000])
        // Por horas (D-411): 8 h facturadas de 10 reales no son un «pasado», son 2 h pendientes de facturar.
        ->and($unit('project:'.$hourly->id))->toMatchArray(['sold_minutes' => null, 'real_minutes' => 600, 'deviation_minutes' => null, 'status' => 'unbilled', 'unbilled_minutes' => 120, 'invoiced' => '590.00', 'invoiced_minutes' => 480])
        ->and($unit('project:'.$fee->id))->toMatchArray(['invoiced' => '0.00', 'planned' => '1500.00', 'invoices_count' => 0])
        ->and($report['totals']['planned'])->toBe('1500.00')
        ->and($report['totals']['invoiced'])->toBe('5690.00');
});
