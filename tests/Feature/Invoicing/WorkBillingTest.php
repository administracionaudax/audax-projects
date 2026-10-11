<?php

use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Billing\Issuing\InvoiceIssuer;
use App\Domain\Billing\SoldVsActual;
use App\Domain\Billing\SoldVsActualQuery;
use App\Domain\Billing\UnbilledReport;
use App\Domain\Billing\Work\UnbilledWork;
use App\Domain\Billing\Work\WorkDraft;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\TimeEntryStatus;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentLink;
use App\Models\SalesDocumentTimeEntry;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Facturar desde el trabajo (PLAN-EMISION §3.8; E3): «Crear borrador» con las horas sin facturar de
| los proyectos por horas (agrupadas, a su tarifa congelada y al céntimo), las bolsas vendidas sin
| factura, el exceso (D-433), los fees del mes y los precios cerrados. Al emitir, las horas quedan
| bloqueadas y enlazadas a su línea; al anular vuelven, al rectificar no (D-244); una hora nunca
| está en dos facturas.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-15 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->ana = userWithRole('employee', ['name' => 'Ana Pérez']);
    $this->luis = userWithRole('employee', ['name' => 'Luis Gil']);
    $this->client = invoicingClient([], ['name' => 'Bodegas Arrieta', 'default_hourly_rate' => null]);
    $this->web = Project::factory()->create(['client_id' => $this->client->id, 'code' => 'ARR-WEB', 'name' => 'Web', 'hourly_rate' => '65.00', 'start_date' => '2026-12-01']);
    $this->dev = TaskType::query()->create(['name' => 'Desarrollo', 'color' => '#0171FF', 'is_active' => true, 'position' => 1]);
    $this->design = TaskType::query()->create(['name' => 'Diseño', 'color' => '#179FA5', 'is_active' => true, 'position' => 2]);
    $this->home = Task::factory()->create(['project_id' => $this->web->id, 'title' => 'Portada', 'task_type_id' => $this->dev->id]);
    $this->cart = Task::factory()->create(['project_id' => $this->web->id, 'title' => 'Carrito', 'task_type_id' => $this->design->id]);
    CatalogService::query()->create(['code' => 'DES', 'name' => 'Desarrollo', 'unit' => 'hour', 'unit_price' => '65', 'category' => 'desarrollo']);
    CatalogService::query()->create(['code' => 'BDH', 'name' => 'bolsadehoras', 'unit' => 'hour', 'unit_price' => '60', 'category' => 'bolsas']);
    CatalogService::query()->create(['code' => 'F_UX', 'name' => 'Fee Producto digital', 'unit' => 'month', 'unit_price' => '1200', 'category' => 'fees']);

    $this->entry = function (Task $task, User $user, int $minutes, string $date, array $extra = []): TimeEntry {
        return TimeEntry::factory()->forTask($task)->minutes($minutes)->status($extra['status'] ?? TimeEntryStatus::Approved)->on($date)
            ->create(['user_id' => $user->id, 'is_billable' => true, 'hourly_rate_snapshot' => '65.00', 'hourly_cost_snapshot' => '30.00', ...array_diff_key($extra, ['status' => true])]);
    };
    // Ana: 2 h y 20 min en la portada; Luis: 1 h y 7 min en el carrito (a 70 €/h, una tarifa propia).
    $this->e1 = ($this->entry)($this->home, $this->ana, 120, '2027-01-11');
    $this->e2 = ($this->entry)($this->home, $this->ana, 20, '2027-01-12');
    $this->e3 = ($this->entry)($this->cart, $this->luis, 67, '2027-01-20', ['hourly_rate_snapshot' => '70.00']);
    // Sin aprobar: no entra.
    ($this->entry)($this->cart, $this->luis, 45, '2027-01-25', ['status' => TimeEntryStatus::Submitted]);

    $this->work = fn (string $grouping = 'task', array $extra = []): array => app(UnbilledWork::class)
        ->forClient($this->client, CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'), $grouping, ...$extra);
    $this->draft = fn (array $selection, string $grouping = 'task'): SalesDocument => app(WorkDraft::class)->create($this->client, $selection, $grouping, $this->admin);
    $this->pending = fn (): array => app(UnbilledReport::class)->detail(new ReportScope($this->admin, ReportFilters::fromQuery(['periodo' => 'anio', 'cliente' => [$this->client->id]])), true, $this->client->id);
});

it('ofrece las horas aprobadas sin facturar agrupadas por tarea y tarifa, al céntimo, y aparte las pendientes de aprobar', function () {
    $work = ($this->work)();
    expect($work['hours'])->toHaveCount(1);
    $project = $work['hours'][0];
    expect($project['project']['code'])->toBe('ARR-WEB')
        ->and($project['minutes'])->toBe(207)
        ->and($project['pending_minutes'])->toBe(45)
        // 140 min a 65 €/h = 151,67 €; 67 min a 70 €/h = 78,17 €.
        ->and($project['amount'])->toBe('229.84')
        ->and(collect($project['lines'])->map(fn (array $line): array => [$line['label'], $line['minutes'], $line['rate'], $line['amount'], count($line['entry_ids'])])->all())->toBe([
            ['Portada', 140, '65.00', '151.67', 2],
            ['Carrito', 67, '70.00', '78.17', 1],
        ])
        ->and($project['lines'][0]['description'])->toBe('Portada · enero de 2027')
        // El servicio del catálogo por el tipo de la tarea (Desarrollo → DES).
        ->and($project['lines'][0]['service_id'])->toBe(CatalogService::query()->where('code', 'DES')->value('id'));

    expect(collect(($this->work)('person')['hours'][0]['lines'])->pluck('label')->all())->toBe(['Ana Pérez', 'Luis Gil'])
        ->and(collect(($this->work)('type')['hours'][0]['lines'])->pluck('label')->all())->toBe(['Desarrollo', 'Diseño'])
        // Por proyecto: una línea por tarifa.
        ->and(collect(($this->work)('project')['hours'][0]['lines'])->pluck('label')->all())->toBe(['Horas del proyecto (65,00 €/h)', 'Horas del proyecto (70,00 €/h)'])
        ->and(($this->work)('entry')['hours'][0]['lines'])->toHaveCount(3);
});

it('crea el borrador con las horas a su tarifa: la base de cada línea es el valor de sus entradas al céntimo', function () {
    $draft = ($this->draft)(['entries' => [$this->e1->id, $this->e2->id, $this->e3->id]]);

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->client_id)->toBe($this->client->id)
        ->and((string) $draft->subtotal)->toBe('229.84');
    $lines = $draft->lines()->get();
    expect($lines->map(fn (SalesDocumentLine $line): array => [$line->description, (string) $line->quantity, (string) $line->unit_price, (string) $line->line_base, $line->origin, $line->minutes])->all())->toBe([
        ['Portada · enero de 2027', '2.3334', '65.0000', '151.67', 'hours', 140],
        ['Carrito · enero de 2027', '1.1167', '70.0000', '78.17', 'hours', 67],
    ]);
    expect(SalesDocumentTimeEntry::query()->whereIn('sales_document_line_id', $lines->modelKeys())->pluck('time_entry_id')->sort()->values()->all())
        ->toBe([$this->e1->id, $this->e2->id, $this->e3->id])
        ->and(SalesDocumentLink::query()->where('sales_document_id', $draft->id)->get(['project_id', 'hour_bank_id', 'method'])->toArray())
        ->toBe([['project_id' => $this->web->id, 'hour_bank_id' => null, 'method' => 'work']]);

    // Ya no se ofrecen (están en un borrador) y no se pueden poner en otra factura.
    $work = ($this->work)();
    expect($work['hours'][0]['lines'])->toBe([])
        ->and($work['hours'][0]['drafts'])->toBe([['id' => $draft->id, 'minutes' => 207]]);
    expect(fn () => ($this->draft)(['entries' => [$this->e1->id]]))->toThrow(ValidationException::class);
});

it('al emitir, las horas quedan bloqueadas y dejan de estar pendientes; al anular vuelven; al rectificar no', function () {
    // Por facturar redondea el total del proyecto (229,8333 €); la factura, cada línea (151,67 + 78,17).
    expect(($this->pending)()['totals'])->toBe(['minutes' => 207, 'pending_minutes' => 45, 'amount' => '229.83']);

    $draft = ($this->draft)(['entries' => [$this->e1->id, $this->e2->id, $this->e3->id]]);
    $draft->forceFill(['series_id' => invoicingSeries('PRU')->id])->save();
    // Un borrador aún no es facturado: siguen pendientes.
    expect(($this->pending)()['totals']['minutes'])->toBe(207);

    $issued = app(InvoiceIssuer::class)->issue($draft, $this->admin);
    expect($this->e1->refresh()->status)->toBe(TimeEntryStatus::Locked)
        ->and(TimeEntryLock::query()->where('sales_document_id', $issued->id)->value('entries_count'))->toBe(3)
        ->and(($this->pending)()['totals'])->toBe(['minutes' => 0, 'pending_minutes' => 45, 'amount' => '0.00']);

    // Rectificar por diferencias: siguen bloqueadas y facturadas.
    $line = $issued->lines()->first();
    app(InvoiceCorrections::class)->rectify($issued->refresh(), $this->admin, 'Descuento acordado', [['line_id' => $line->id, 'quantity' => (string) $line->quantity, 'unit_price' => '60']]);
    expect($this->e1->refresh()->status)->toBe(TimeEntryStatus::Locked)
        ->and(($this->pending)()['totals']['minutes'])->toBe(0)
        ->and(($this->pending)()['totals']['amount'])->toBe('0.00');

    // Anular: se desbloquean y vuelven a estar pendientes (y se pueden facturar otra vez).
    app(InvoiceCorrections::class)->cancel($issued->refresh(), $this->admin, 'Cliente equivocado');
    expect($this->e1->refresh()->status)->toBe(TimeEntryStatus::Approved)
        ->and(($this->pending)()['totals'])->toBe(['minutes' => 207, 'pending_minutes' => 45, 'amount' => '229.83'])
        ->and(collect(($this->work)()['hours'][0]['lines'])->sum('minutes'))->toBe(207);
    $again = ($this->draft)(['entries' => [$this->e1->id]]);
    expect((string) $again->subtotal)->toBe('130.00');
});

it('una hora solo puede estar en una factura emitida viva (índice único)', function () {
    $draft = ($this->draft)(['entries' => [$this->e1->id]]);
    $line = $draft->lines()->firstOrFail();
    $other = invoicingDraft($this->admin, $this->client);

    expect(inSavepoint(fn () => SalesDocumentTimeEntry::query()->create(['sales_document_line_id' => $other->lines()->firstOrFail()->id, 'time_entry_id' => $this->e1->id])))
        ->toThrow(QueryException::class);
    // Borrar el borrador la libera.
    $draft->delete();
    expect(SalesDocumentTimeEntry::query()->where('sales_document_line_id', $line->id)->exists())->toBeFalse()
        ->and(($this->work)()['hours'][0]['lines'])->toHaveCount(2);
});

it('la bolsa vendida: «bolsadehoras» con horas × €/h y el descuento que da su precio; con su exceso de la anterior (D-433)', function () {
    $banksProject = Project::factory()->hourBank()->create(['client_id' => $this->client->id, 'code' => 'ARR-BDH', 'name' => 'Mantenimiento', 'start_date' => '2026-06-01']);
    $old = HourBank::factory()->renewed()->create(['project_id' => $banksProject->id, 'name' => 'Bolsa enero', 'total_minutes' => 600, 'hourly_rate' => '60.00', 'price_amount' => '600.00', 'start_date' => '2026-12-01']);
    $new = HourBank::factory()->create(['project_id' => $banksProject->id, 'name' => 'Bolsa febrero', 'total_minutes' => 6000, 'hourly_rate' => '60.00', 'price_amount' => '5100.00', 'start_date' => '2027-02-01', 'renewed_from_id' => $old->id]);
    // La bolsa de enero ya está facturada (enlazada): solo queda su exceso.
    invoicingIssue($this->admin, $this->client, null, ['project_id' => $banksProject->id, 'hour_bank_id' => $old->id]);
    $task = Task::factory()->create(['project_id' => $banksProject->id, 'hour_bank_id' => $old->id, 'title' => 'Incidencias']);
    $over = ($this->entry)($task, $this->ana, 630, '2027-01-28', ['hour_bank_id' => $old->id, 'overage_minutes' => 30, 'hourly_rate_snapshot' => '60.00']);

    $work = ($this->work)('task', ['bankId' => $new->id]);
    expect($work['hours'])->toBe([])
        ->and($work['banks'])->toHaveCount(1)
        ->and($work['banks'][0]['line'])->toMatchArray(['quantity' => '100.0000', 'unit' => 'hour', 'unit_price' => '60.0000', 'discount_pct' => '15.00', 'description' => 'Bolsa de horas 100h febrero 2027 · Mantenimiento'])
        ->and($work['banks'][0]['previous'])->toBe(['id' => $old->id, 'name' => 'Bolsa enero'])
        ->and($work['overage'])->toHaveCount(1)
        ->and($work['overage'][0])->toMatchArray(['bank' => ['id' => $old->id, 'name' => 'Bolsa enero'], 'next_bank' => ['id' => $new->id, 'name' => 'Bolsa febrero'], 'minutes' => 30, 'amount' => '30.00']);

    $draft = ($this->draft)(['banks' => [$new->id], 'overage_entries' => [$over->id]]);
    $lines = $draft->lines()->get();
    expect($lines->map(fn (SalesDocumentLine $line): array => [$line->origin, (string) $line->line_base, $line->hour_bank_id, $line->minutes])->all())->toBe([
        ['hour_bank', '5100.00', $new->id, null],
        ['overage', '30.00', $old->id, 30],
    ])->and($lines[1]->description)->toBe('Exceso de la bolsa Bolsa enero · enero de 2027')
        // Va con la bolsa siguiente: la factura se enlaza solo a ella (D-433).
        ->and(SalesDocumentLink::query()->where('sales_document_id', $draft->id)->pluck('hour_bank_id')->all())->toBe([$new->id]);

    // La bolsa ya tiene factura (un borrador cuenta): no se ofrece otra vez.
    expect(($this->work)('task', ['bankId' => $new->id])['banks'])->toBe([]);
});

it('los fees: un «Fee de [mes]» por mes sin facturar; facturado (aunque sea el mes siguiente), deja de pedirse', function () {
    $fee = Project::factory()->monthlyFee(20, '1200.00')->create(['client_id' => $this->client->id, 'code' => 'ARR-FEE', 'name' => 'Redes', 'start_date' => '2027-01-01']);

    $fees = ($this->work)('task', ['projectId' => $fee->id])['fees'];
    expect(collect($fees)->map(fn (array $one): array => [$one['month'], $one['amount'], $one['description']])->all())->toBe([
        ['2027-01', '1200.00', 'Fee de enero de 2027 · Redes'],
        ['2027-02', '1200.00', 'Fee de febrero de 2027 · Redes'],
    ]);

    $draft = ($this->draft)(['fees' => [['project_id' => $fee->id, 'month' => '2027-01']]]);
    $line = $draft->lines()->firstOrFail();
    expect($line->origin)->toBe('fee')
        ->and($line->period_start->toDateString())->toBe('2027-01-01')
        ->and($line->service_code)->toBe('F_UX');

    $draft->forceFill(['series_id' => invoicingSeries('F')->id])->save();
    app(InvoiceIssuer::class)->issue($draft, $this->admin);
    // Emitida en febrero, pero factura enero: enero ya no está pendiente.
    expect(collect(($this->work)('task', ['projectId' => $fee->id])['fees'])->pluck('month')->all())->toBe(['2027-02'])
        ->and(fn () => ($this->draft)(['fees' => [['project_id' => $fee->id, 'month' => '2027-01']]]))->toThrow(ValidationException::class);
});

it('precios cerrados: un importe o hito, como mucho lo pendiente; emitido, lo descuenta', function () {
    $fixed = Project::factory()->fixedPrice()->create(['client_id' => $this->client->id, 'code' => 'ARR-APP', 'name' => 'App', 'fixed_price_amount' => '10000.00', 'start_date' => '2027-01-01']);

    expect(($this->work)('task', ['projectId' => $fixed->id])['fixed'])->toBe([[
        'project' => ['id' => $fixed->id, 'code' => 'ARR-APP', 'name' => 'App'], 'price' => '10000.00', 'invoiced' => '0.00', 'pending' => '10000.00', 'description' => 'App · Hito',
    ]]);

    expect(fn () => ($this->draft)(['fixed' => [['project_id' => $fixed->id, 'amount' => '10000.01']]]))->toThrow(ValidationException::class);

    $draft = ($this->draft)(['fixed' => [['project_id' => $fixed->id, 'amount' => '5000', 'description' => 'App · 50 % al empezar']]]);
    expect($draft->lines()->first()->only(['description', 'origin']))->toBe(['description' => 'App · 50 % al empezar', 'origin' => 'fixed']);
    $draft->forceFill(['series_id' => invoicingSeries('F')->id])->save();
    app(InvoiceIssuer::class)->issue($draft, $this->admin);

    expect(($this->work)('task', ['projectId' => $fixed->id])['fixed'][0]['pending'])->toBe('5000.00');
});

it('varios proyectos en una factura: un título por proyecto; y la factura cuenta en «Vendido frente a real»', function () {
    $fixed = Project::factory()->fixedPrice()->create(['client_id' => $this->client->id, 'code' => 'ARR-APP', 'name' => 'App', 'fixed_price_amount' => '1000.00', 'start_date' => '2027-01-01']);
    $draft = ($this->draft)(['entries' => [$this->e1->id], 'fixed' => [['project_id' => $fixed->id, 'amount' => '1000']]]);

    expect($draft->lines()->get()->map(fn (SalesDocumentLine $line): string => $line->kind.':'.$line->description)->all())->toBe([
        'text:ARR-WEB · Web', 'item:Portada · enero de 2027', 'text:ARR-APP · App', 'item:App · Hito',
    ]);

    $draft->forceFill(['series_id' => invoicingSeries('F')->id])->save();
    app(InvoiceIssuer::class)->issue($draft, $this->admin);
    $report = app(SoldVsActual::class)->report(SoldVsActualQuery::fromQuery(['periodo' => 'anio']), $this->admin, true);
    expect(collect($report['units'])->firstWhere('key', 'project:'.$fixed->id)['invoiced'])->toBe('1000.00')
        // Por horas: las 2 h de la portada ya están facturadas (minutos exactos de la línea).
        ->and(collect($report['units'])->firstWhere('key', 'project:'.$this->web->id)['unbilled_minutes'])->toBe(87);
});

it('un cliente de la UE lleva el impuesto de la UE en las líneas', function () {
    $eu = invoicingClient(['tax_regime' => 'intra_eu', 'eu_vat_number' => 'FR12345678901', 'country_code' => 'FR'], ['name' => 'Maison']);
    $project = Project::factory()->create(['client_id' => $eu->id, 'hourly_rate' => '50.00']);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $entry = ($this->entry)($task, $this->ana, 60, '2027-02-01', ['hourly_rate_snapshot' => '50.00']);

    $draft = app(WorkDraft::class)->create(Client::query()->findOrFail($eu->id), ['entries' => [$entry->id]], 'task', $this->admin);
    expect($draft->lines()->first()->tax_rate_id)->toBe(invoicingTax('intra_eu')->id)
        ->and((string) $draft->tax_total)->toBe('0.00');
});
