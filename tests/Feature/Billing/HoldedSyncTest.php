<?php

use App\Domain\Billing\Holded\FakeHolded;
use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedRequestFailed;
use App\Domain\Billing\Holded\HoldedSync;
use App\Domain\Billing\Holded\HoldedSyncBusy;
use App\Domain\Billing\HoldedInvoiceLinker;
use App\Domain\Billing\SoldVsActual;
use App\Domain\Billing\SoldVsActualQuery;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLinkMethod;
use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\HoldedInvoiceLink;
use App\Models\HoldedPayment;
use App\Models\HoldedSyncRun;
use App\Models\HourBank;
use App\Models\ImportRef;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/*
| Sincronización de solo lectura con Holded (Fase 12, F1; D-385 a D-389): contactos ↔ clientes (NIF,
| nombre, sin casar), facturas, rectificativas, cobros y estado de cobro, enlace por código F y por
| proyecto de Holded, PDF en el disco privado e idempotencia (repetirla no duplica ni reescribe).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');

    $this->arrieta = Client::factory()->create(['name' => 'Bodegas Arrieta', 'tax_id' => 'B26123456']);
    $this->mirador = Client::factory()->create(['name' => 'Hoteles Mirador', 'tax_id' => null]);
    $this->montana = Client::factory()->create(['name' => 'Cervezas Montaña', 'tax_id' => 'B22333111']);

    $this->bankProject = Project::factory()->hourBank()->create(['client_id' => $this->arrieta->id, 'code' => 'ARR-BH']);
    $this->bank = HourBank::factory()->create(['project_id' => $this->bankProject->id, 'invoice_reference' => 'F260170', 'start_date' => '2026-01-10', 'price_amount' => '3000.00']);
    $this->fee = Project::factory()->monthlyFee()->create(['client_id' => $this->montana->id, 'code' => 'MON-FE1', 'start_date' => '2026-01-01']);
    $this->fixed = Project::factory()->fixedPrice()->create(['client_id' => $this->mirador->id, 'code' => 'MIR-WE1', 'description' => 'Web nueva. Factura: F26/0045.']);

    $this->data = [
        'contacts' => [
            holdedContact('c1', 'Bodegas Arrieta S.L.', 'ES-B26123456'),
            holdedContact('c2', 'Hoteles Mirador, S.A.', null),
            holdedContact('c3', 'Estudio Nébula, S.L.', 'B46000999'),
            holdedContact('c4', 'Papelería Ruiz', 'B46111222', ['type' => 'supplier']),
            holdedContact('c5', 'Cervecera del Pirineo SA', 'b-22.333.111', ['trade_name' => 'Cervezas Montaña']),
        ],
        'projects' => [['id' => 'hp1', 'name' => 'MON-FE1 · Mantenimiento'], ['id' => 'hp2', 'name' => 'Proyecto sin pareja']],
        'invoices' => [
            holdedInvoice('i1', 'F260170', 'c1', '2026-01-15', '3000.00'),
            holdedInvoice('i2', 'F260200', 'c5', '2026-02-01', '1500.00', ['items' => [['name' => 'Fee de febrero', 'units' => 1, 'price' => '1500.00', 'taxes' => ['s_iva_21'], 'project_id' => 'hp1']]]),
            holdedInvoice('i3', 'F260045', 'c2', '2026-03-10', '2000.00'),
            holdedInvoice('i4', 'F260300', 'c3', '2026-03-15', '800.00'),
            holdedInvoice('i5', '', 'c1', '2026-03-16', '50.00', ['draft' => true, 'approval_status' => 'draft']),
            holdedInvoice('i6', 'F260301', 'c1', '2026-03-16', '90.00', ['status' => 'cancelled']),
        ],
        'creditNotes' => [
            ['id' => 'cn1', 'document_number' => 'R260001', 'contact_id' => 'c5', 'date' => '2026-02-12', 'subtotal' => '150.00', 'tax' => '31.50', 'total' => '181.50',
                'status' => 'completed', 'payments_total' => '0', 'payments_pending' => '0', 'rectified_document_id' => 'i2', 'items' => [['name' => 'Descuento', 'units' => 1, 'price' => '150.00']]],
        ],
        'payments' => [
            ['id' => 'p1', 'document_id' => 'i1', 'date' => '2026-02-10', 'amount' => '3630.00', 'payment_method' => 'Transferencia'],
            ['id' => 'p2', 'document_id' => 'i3', 'date' => '2026-03-18', 'amount' => '1210.00'],
        ],
    ];
    // Holded da lo cobrado de cada factura (payments_total/payments_pending).
    $this->data['invoices'][0] = [...$this->data['invoices'][0], 'payments_total' => '3630.00', 'payments_pending' => '0.00', 'status' => 'completed'];
    $this->data['invoices'][2] = [...$this->data['invoices'][2], 'payments_total' => '1210.00', 'payments_pending' => '1210.00', 'status' => 'partial'];
});

it('casa los contactos por NIF y por nombre, deja los demás sin casar y no lee proveedores', function () {
    $run = syncHolded(holdedFake($this->data));

    expect($run->status)->toBe(HoldedSyncRun::OK)
        ->and(HoldedContact::query()->count())->toBe(4)
        ->and(HoldedContact::query()->where('holded_id', 'c1')->value('client_id'))->toBe($this->arrieta->id)
        ->and(HoldedContact::query()->where('holded_id', 'c1')->value('match_method'))->toBe('tax_id')
        ->and(HoldedContact::query()->where('holded_id', 'c2')->value('client_id'))->toBe($this->mirador->id)
        ->and(HoldedContact::query()->where('holded_id', 'c2')->value('match_method'))->toBe('name')
        ->and(HoldedContact::query()->where('holded_id', 'c5')->value('client_id'))->toBe($this->montana->id)
        ->and(HoldedContact::query()->where('holded_id', 'c3')->value('client_id'))->toBeNull()
        ->and($run->stats['contacts_unresolved'])->toBe(1)
        // No crea clientes.
        ->and(Client::query()->count())->toBe(3);

    // Rellena lo que falta del cliente, sin pisar lo que ya tiene.
    $profile = ClientBillingProfile::query()->where('client_id', $this->mirador->id)->firstOrFail();
    expect($profile->legal_name)->toBe('Hoteles Mirador, S.A.')
        ->and($profile->city)->toBe('Valencia')
        ->and($this->arrieta->fresh()->tax_id)->toBe('B26123456');
});

it('lee facturas y rectificativas con sus importes, líneas, cliente y estado de cobro', function () {
    syncHolded(holdedFake($this->data));

    expect(HoldedInvoice::query()->count())->toBe(7);

    $i1 = HoldedInvoice::query()->where('holded_id', 'i1')->firstOrFail();
    expect($i1->number)->toBe('F260170')
        ->and($i1->client_id)->toBe($this->arrieta->id)
        ->and((string) $i1->subtotal)->toBe('3000.00')
        ->and((string) $i1->tax_total)->toBe('630.00')
        ->and((string) $i1->total)->toBe('3630.00')
        ->and($i1->collection_status)->toBe(CollectionStatus::Paid)
        ->and($i1->lines)->toHaveCount(1)
        ->and((string) $i1->lines->first()?->tax_rate)->toBe('21.00');

    expect(HoldedInvoice::query()->where('holded_id', 'i2')->value('collection_status'))->toBe(CollectionStatus::Overdue)
        ->and(HoldedInvoice::query()->where('holded_id', 'i3')->value('collection_status'))->toBe(CollectionStatus::Partial)
        ->and(HoldedInvoice::query()->where('holded_id', 'i4')->value('collection_status'))->toBe(CollectionStatus::Unpaid)
        ->and(HoldedInvoice::query()->where('holded_id', 'i6')->value('collection_status'))->toBe(CollectionStatus::Cancelled)
        // El borrador (de una recurrente): sin número, fuera de lo facturado.
        ->and(HoldedInvoice::query()->where('holded_id', 'i5')->value('collection_status'))->toBe(CollectionStatus::Draft)
        ->and(HoldedInvoice::query()->where('holded_id', 'i5')->value('number'))->toBeNull()
        ->and(HoldedInvoice::query()->where('holded_id', 'i5')->firstOrFail()->counts())->toBeFalse();

    $credit = HoldedInvoice::query()->where('holded_id', 'cn1')->firstOrFail();
    expect($credit->kind)->toBe(HoldedDocumentKind::CreditNote)
        ->and((string) $credit->subtotal)->toBe('-150.00')
        ->and((string) $credit->total)->toBe('-181.50')
        ->and($credit->rectified_invoice_id)->toBe(HoldedInvoice::query()->where('holded_id', 'i2')->value('id'));

    expect(HoldedPayment::query()->count())->toBe(2)
        ->and(HoldedPayment::query()->where('holded_id', 'p1')->value('holded_invoice_id'))->toBe($i1->id);
});

it('enlaza por el código F (bolsa y descripción del proyecto) y por el proyecto de Holded; la rectificativa hereda', function () {
    syncHolded(holdedFake($this->data));

    $link = fn (string $holdedId) => HoldedInvoiceLink::query()->whereHas('invoice', fn ($q) => $q->where('holded_id', $holdedId))->get();

    expect($link('i1'))->toHaveCount(1)
        ->and($link('i1')->first()?->hour_bank_id)->toBe($this->bank->id)
        ->and($link('i1')->first()?->method)->toBe(InvoiceLinkMethod::FCode)
        ->and($link('i3')->first()?->project_id)->toBe($this->fixed->id)
        ->and($link('i2')->first()?->project_id)->toBe($this->fee->id)
        ->and($link('i2')->first()?->method)->toBe(InvoiceLinkMethod::HoldedProject)
        ->and($link('cn1')->first()?->project_id)->toBe($this->fee->id)
        ->and($link('cn1')->first()?->method)->toBe(InvoiceLinkMethod::Rectified)
        ->and($link('i4'))->toHaveCount(0);
});

it('repetida no duplica nada ni reescribe lo que no cambia (idempotente)', function () {
    $fake = holdedFake($this->data);
    syncHolded($fake);
    $before = HoldedInvoice::query()->where('holded_id', 'i1')->value('updated_at');
    $counts = fn (): array => [HoldedContact::query()->count(), HoldedInvoice::query()->count(), HoldedInvoiceLine::query()->count(), HoldedPayment::query()->count(), HoldedInvoiceLink::query()->count(), ImportRef::query()->where('source', 'holded')->count()];
    $first = $counts();

    $this->travel(2)->hours();
    $run = syncHolded($fake);

    expect($counts())->toBe($first)
        ->and(HoldedInvoice::query()->where('holded_id', 'i1')->value('updated_at'))->toEqual($before)
        ->and($run->stats['invoices_unchanged'])->toBe(5)
        ->and($run->stats['invoices_created'] ?? 0)->toBe(0)
        ->and(ImportRef::query()->where(['source' => 'holded', 'kind' => 'invoice', 'external_id' => 'i1'])->count())->toBe(1);
});

it('una factura que cambia en Holded se actualiza, rehace sus líneas y vuelve a pedir el PDF', function () {
    syncHolded(holdedFake($this->data), pdfs: true);
    $i4 = HoldedInvoice::query()->where('holded_id', 'i4')->firstOrFail();
    expect($i4->pdf_path)->not->toBeNull();
    Storage::disk('local')->assertExists($i4->pdf_path);

    $data = $this->data;
    $data['invoices'][3] = holdedInvoice('i4', 'F260300', 'c3', '2026-03-15', '950.00', ['items' => [
        ['name' => 'Uno', 'units' => 1, 'price' => '500.00'], ['name' => 'Dos', 'units' => 1, 'price' => '450.00'],
    ]]);
    $run = syncHolded(holdedFake($data));

    $i4->refresh();
    expect((string) $i4->subtotal)->toBe('950.00')
        ->and($i4->lines)->toHaveCount(2)
        ->and($i4->pdf_path)->toBeNull()
        ->and($run->stats['invoices_updated'])->toBe(1);
});

it('guarda los PDF en el disco privado', function () {
    syncHolded(holdedFake($this->data), pdfs: true);

    $invoice = HoldedInvoice::query()->where('holded_id', 'i1')->firstOrFail();
    expect($invoice->pdf_path)->toBe('holded/2026/i1.pdf');
    expect(Storage::disk('local')->get($invoice->pdf_path))->toStartWith('%PDF');
});

it('los enlaces y los contactos resueltos a mano sobreviven a la sincronización', function () {
    $fake = holdedFake($this->data);
    syncHolded($fake);

    $i4 = HoldedInvoice::query()->where('holded_id', 'i4')->firstOrFail();
    app(HoldedInvoiceLinker::class)->link($i4, $this->fixed, null, userWithRole('admin'));
    HoldedContact::query()->where('holded_id', 'c3')->update(['client_id' => $this->mirador->id, 'match_method' => 'manual']);

    syncHolded($fake);

    expect(HoldedInvoiceLink::query()->where('holded_invoice_id', $i4->id)->value('method'))->toBe(InvoiceLinkMethod::Manual)
        ->and(HoldedContact::query()->where('holded_id', 'c3')->value('client_id'))->toBe($this->mirador->id)
        ->and($i4->fresh()?->client_id)->toBe($this->mirador->id);
});

it('si cambia el código F de una bolsa, el enlace automático se rehace', function () {
    $fake = holdedFake($this->data);
    syncHolded($fake);
    $this->bank->update(['invoice_reference' => 'F260300']);

    syncHolded($fake);

    expect(HoldedInvoiceLink::query()->where('hour_bank_id', $this->bank->id)->with('invoice')->get()->map(fn ($l) => $l->invoice->number)->all())->toBe(['F260300']);
});

it('no corre dos a la vez', function () {
    $lock = Cache::lock(HoldedSync::LOCK, 60);
    $lock->get();

    expect(fn () => syncHolded(holdedFake($this->data)))->toThrow(HoldedSyncBusy::class)
        ->and(HoldedSync::busy())->toBeTrue();

    $lock->release();
    expect(HoldedSync::busy())->toBeFalse();
});

it('la orden no hace nada con el módulo apagado y sincroniza con él encendido', function () {
    app()->instance(HoldedApi::class, holdedFake($this->data));

    $this->artisan('app:holded-sync', ['--sin-pdf' => true])->assertSuccessful()->expectsOutputToContain('apagado');
    expect(HoldedSyncRun::query()->count())->toBe(0);

    enableBilling();
    $this->artisan('app:holded-sync', ['--sin-pdf' => true])->assertSuccessful();
    expect(HoldedSyncRun::query()->value('status'))->toBe(HoldedSyncRun::OK)
        ->and(HoldedInvoice::query()->count())->toBe(7);
});

it('sin clave, la orden avisa y la programada no falla', function () {
    enableBilling();
    config(['services.holded.driver' => 'holded', 'services.holded.key' => '']);

    $this->artisan('app:holded-sync')->assertFailed()->expectsOutputToContain('HOLDED_API_KEY');
    $this->artisan('app:holded-sync', ['--programada' => true])->assertSuccessful();
});

it('un error de Holded deja la ejecución como fallida con el mensaje', function () {
    $broken = new class extends FakeHolded
    {
        public function contacts(): iterable
        {
            throw HoldedRequestFailed::forStatus(401, '/contacts');
        }
    };

    expect(fn () => syncHolded($broken))->toThrow(HoldedRequestFailed::class);
    $run = HoldedSyncRun::query()->firstOrFail();
    expect($run->status)->toBe(HoldedSyncRun::FAILED)
        ->and($run->error)->toContain('rechazado la clave')
        ->and(HoldedSync::busy())->toBeFalse();
});

it('un borrador aprobado en Holded recibe su número; uno borrado en Holded desaparece', function () {
    $draft = holdedInvoice('d1', '', 'c5', '2026-03-19', '1500.00', ['draft' => true, 'approval_status' => 'draft', 'tags' => ['#fee']]);
    syncHolded(holdedFake([...$this->data, 'invoices' => [...$this->data['invoices'], $draft]]));
    expect(HoldedInvoice::query()->where('holded_id', 'd1')->firstOrFail())
        ->is_draft->toBeTrue()
        ->tags->toBe(['#fee']);

    $approved = [...$draft, 'draft' => false, 'approval_status' => 'approved', 'document_number' => 'F260210'];
    syncHolded(holdedFake([...$this->data, 'invoices' => [...$this->data['invoices'], $approved]]));
    $invoice = HoldedInvoice::query()->where('holded_id', 'd1')->firstOrFail();
    expect($invoice->is_draft)->toBeFalse()
        ->and($invoice->number)->toBe('F260210')
        ->and($invoice->collection_status)->toBe(CollectionStatus::Unpaid);

    // El borrador i5 ya no está en Holded: se borra (y su correspondencia).
    $data = $this->data;
    unset($data['invoices'][4]);
    $run = syncHolded(holdedFake([...$data, 'invoices' => array_values($data['invoices'])]));
    expect(HoldedInvoice::query()->where('holded_id', 'i5')->exists())->toBeFalse()
        ->and(ImportRef::query()->where(['source' => 'holded', 'external_id' => 'i5'])->exists())->toBeFalse()
        ->and($run->stats['drafts_removed'])->toBe(1);
});

it('guarda el servicio de cada línea (código del catálogo de Holded)', function () {
    $data = $this->data;
    $data['invoices'][0]['items'] = [['name' => 'bolsadehoras', 'code' => 'BDH', 'units' => 100, 'price' => '60.00', 'discount' => '15', 'description' => 'Bolsa de horas 100h Octubre 2026']];
    syncHolded(holdedFake($data));

    $line = HoldedInvoice::query()->where('holded_id', 'i1')->firstOrFail()->lines->firstOrFail();
    expect($line->service_code)->toBe('BDH')
        ->and((string) $line->units)->toBe('100.0000')
        ->and((string) $line->discount_pct)->toBe('15.00')
        // Sin subtotal en la línea: unidades × precio con el descuento.
        ->and((string) $line->subtotal)->toBe('5100.00');
});

it('una rectificativa de la serie CN es rectificativa aunque llegue con las facturas, y se resta una sola vez', function () {
    enableBilling();
    $admin = userWithRole('admin');
    $data = $this->data;
    // F260045 (MIR-WE1, 2.000 €) se anula en Holded con su rectificativa por el total, CN260004.
    $data['invoices'][2] = [...$data['invoices'][2], 'status' => 'cancelled'];
    // Una rectificativa por diferencias de la F260170 (bolsa): −15 h × 60 € con un 5 % = −855 €.
    $data['invoices'][] = holdedInvoice('cn-a', 'CN260004', 'c2', '2026-03-12', '-2000.00', ['status' => 'cancelled', 'rectified_document_id' => 'i3', 'payments_total' => '0', 'payments_pending' => '0']);
    $data['invoices'][] = holdedInvoice('cn-b', 'CN260005', 'c1', '2026-03-14', '-855.00', ['total' => '-1034.55', 'tax' => '-179.55', 'status' => 'cancelled', 'rectified_document_id' => 'i1',
        'payments_total' => '0', 'payments_pending' => '0',
        'items' => [['name' => 'bolsadehoras', 'code' => 'BDH', 'units' => -15, 'price' => '60.00', 'discount' => '5', 'subtotal' => '-855.00']]]);
    syncHolded(holdedFake($data));

    $cnA = HoldedInvoice::query()->where('holded_id', 'cn-a')->firstOrFail();
    $cnB = HoldedInvoice::query()->where('holded_id', 'cn-b')->firstOrFail();
    expect($cnA->kind)->toBe(HoldedDocumentKind::CreditNote)
        ->and((string) $cnB->subtotal)->toBe('-855.00')
        ->and((string) $cnB->total)->toBe('-1034.55')
        ->and($cnB->collection_status)->not->toBe(CollectionStatus::Cancelled)
        ->and($cnB->rectified_invoice_id)->toBe(HoldedInvoice::query()->where('holded_id', 'i1')->value('id'))
        // Hereda el enlace de su original (la bolsa) y cuenta; la de la anulada, no (ya no cuenta la original).
        ->and(HoldedInvoiceLink::query()->where('holded_invoice_id', $cnB->id)->value('hour_bank_id'))->toBe($this->bank->id)
        ->and($cnB->counts())->toBeTrue()
        ->and($cnA->counts())->toBeFalse()
        ->and(HoldedInvoice::query()->where('holded_id', 'i3')->firstOrFail()->counts())->toBeFalse();

    $report = app(SoldVsActual::class)->report(SoldVsActualQuery::fromQuery(['periodo' => 'anio', 'fecha' => '2026-03-20']), $admin, true);
    $unit = fn (string $key): array => collect($report['units'])->firstWhere('key', $key);
    expect($unit('bank:'.$this->bank->id)['invoiced'])->toBe('2145.00')
        // La anulada y su rectificativa: 0, no −2.000.
        ->and($unit('project:'.$this->fixed->id)['invoiced'])->toBe('0.00');

    // El sumatorio del listado sigue la misma regla.
    $this->actingAs($admin)->get('/facturacion/facturas?cliente='.$this->mirador->id)
        ->assertInertia(fn ($page) => $page->where('totals.subtotal', '0.00'));
});
