<?php

use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Billing\Issuing\InvoiceIssuer;
use App\Enums\InvoiceRecordKind;
use App\Enums\RectificationKind;
use App\Enums\SalesDocumentStatus;
use App\Enums\SalesDocumentType;
use App\Enums\TimeEntryStatus;
use App\Models\InvoiceRecord;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentLink;
use App\Models\SalesDocumentTimeEntry;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| T-RECT (PLAN-EMISION §8.2; D-244, D-424): anular emite una rectificativa CN por el total y desbloquea
| las horas; rectificar emite la diferencia y no las desbloquea; el motivo es obligatorio; anular el
| registro (V-03) es solo de un admin y solo para lo que no debió existir.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
    $this->project = Project::factory()->create(['client_id' => $this->client->id]);

    // Una factura con dos líneas, enlazada a un proyecto y con dos entradas de horas aprobadas.
    $draft = invoicingDraft($this->admin, $this->client, [invoicingLine('15', '60', 'iva_21', '5'), invoicingLine('2', '50', 'iva_10', '0', 'Diseño')], ['project_id' => $this->project->id]);
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $this->entries = TimeEntry::factory()->count(2)->forTask($task)->status(TimeEntryStatus::Approved)->on('2026-12-28')->create();
    $line = SalesDocumentLine::query()->where('sales_document_id', $draft->id)->orderBy('position')->firstOrFail();
    foreach ($this->entries as $entry) {
        SalesDocumentTimeEntry::query()->create(['sales_document_line_id' => $line->id, 'time_entry_id' => $entry->id]);
    }
    $this->invoice = app(InvoiceIssuer::class)->issue($draft, $this->admin);
});

it('al emitir, las horas de sus líneas quedan bloqueadas con la factura como referencia', function () {
    $lock = TimeEntryLock::query()->where('sales_document_id', $this->invoice->id)->firstOrFail();

    expect($lock->reference)->toBe('F270001')
        ->and(TimeEntry::query()->whereKey($this->entries->modelKeys())->pluck('status')->unique()->all())->toBe([TimeEntryStatus::Locked]);
});

it('anular emite una CN por el total en negativo, deja la original anulada y desbloquea las horas', function () {
    $credit = app(InvoiceCorrections::class)->cancel($this->invoice, $this->admin, 'El cliente pidió otra razón social');

    expect($credit->type)->toBe(SalesDocumentType::CreditNote)
        ->and($credit->full_number)->toBe('CN270001')
        ->and($credit->rectification_kind)->toBe(RectificationKind::Cancellation)
        ->and($credit->rectified_document_id)->toBe($this->invoice->id)
        ->and((string) $credit->subtotal)->toBe('-955.00')
        ->and((string) $credit->tax_total)->toBe('-189.55')
        ->and((string) $credit->total)->toBe('-1144.55')
        ->and(InvoiceRecord::query()->find($credit->invoice_record_id)->invoice_type)->toBe('R1')
        ->and(InvoiceRecord::query()->find($credit->invoice_record_id)->payload['facturas_rectificadas'][0]['num_serie_factura'])->toBe('F270001');

    $original = SalesDocument::query()->findOrFail($this->invoice->id);
    expect($original->status)->toBe(SalesDocumentStatus::Cancelled)
        ->and($original->cancelled_by_id)->toBe($credit->id)
        // El enlace con el proyecto pasa a la rectificativa.
        ->and(SalesDocumentLink::query()->where('sales_document_id', $credit->id)->value('method'))->toBe('rectified')
        // Las horas vuelven a poder facturarse.
        ->and(TimeEntry::query()->whereKey($this->entries->modelKeys())->where('status', TimeEntryStatus::Locked->value)->count())->toBe(0)
        ->and(SalesDocumentTimeEntry::query()->whereNull('released_at')->count())->toBe(0)
        ->and(TimeEntryLock::query()->where('sales_document_id', $this->invoice->id)->value('unlocked_at'))->not->toBeNull();

    // Ya no se puede anular ni rectificar otra vez.
    expect(fn () => app(InvoiceCorrections::class)->cancel($original, $this->admin, 'Otra vez'))->toThrow(ValidationException::class);
});

it('rectificar emite solo la diferencia; la original sigue emitida y las horas, bloqueadas', function () {
    $lines = SalesDocumentLine::query()->where('sales_document_id', $this->invoice->id)->orderBy('position')->get();

    // La primera eran 15 h y debían ser 12; la segunda, a 50 € y debía ser a 45 €.
    $credit = app(InvoiceCorrections::class)->rectify($this->invoice, $this->admin, 'Horas y precio mal puestos', [
        ['line_id' => $lines[0]->id, 'quantity' => '12', 'unit_price' => '60'],
        ['line_id' => $lines[1]->id, 'quantity' => '2', 'unit_price' => '45'],
    ]);

    $creditLines = SalesDocumentLine::query()->where('sales_document_id', $credit->id)->orderBy('position')->get();
    expect($credit->rectification_kind)->toBe(RectificationKind::Differences)
        ->and($credit->full_number)->toBe('CN270001')
        ->and($creditLines->map(fn ($line) => [(string) $line->quantity, (string) $line->unit_price, (string) $line->line_base])->all())
        ->toBe([['-3.0000', '60.0000', '-171.00'], ['2.0000', '-5.0000', '-10.00']])
        ->and((string) $credit->subtotal)->toBe('-181.00')
        ->and((string) $credit->tax_total)->toBe('-36.91')
        ->and(SalesDocument::query()->find($this->invoice->id)->status)->toBe(SalesDocumentStatus::Issued)
        ->and(TimeEntry::query()->whereKey($this->entries->modelKeys())->where('status', TimeEntryStatus::Locked->value)->count())->toBe(2);

    // Sin ninguna diferencia, no hay rectificativa.
    expect(fn () => app(InvoiceCorrections::class)->rectify($this->invoice, $this->admin, 'Nada que cambiar', [
        ['line_id' => $lines[0]->id, 'quantity' => '15', 'unit_price' => '60'],
    ]))->toThrow(ValidationException::class, 'No hay ninguna diferencia');

    // Anular después deshace también lo ya rectificado: el neto queda a 0.
    $cancel = app(InvoiceCorrections::class)->cancel(SalesDocument::query()->findOrFail($this->invoice->id), $this->admin, 'Se rehace entera');
    expect((string) $cancel->subtotal)->toBe('-774.00');
});

it('el motivo es obligatorio y solo emite quien tiene manage-billing', function () {
    expect(fn () => app(InvoiceCorrections::class)->cancel($this->invoice, $this->admin, '  '))->toThrow(ValidationException::class);

    $finance = userWithRole('employee');
    $finance->givePermissionTo('view-financials');
    expect(fn () => app(InvoiceCorrections::class)->cancel($this->invoice, $finance, 'Sin permiso de emitir'))->toThrow(AuthorizationException::class);
});

it('anular el registro (V-03) añade un registro de anulación encadenado y la deja anulada por error', function () {
    $mistake = invoicingIssue($this->admin, $this->client);

    $voided = app(InvoiceCorrections::class)->void($mistake, $this->admin, 'Emitida por error: duplicado de la F270001');

    $records = InvoiceRecord::query()->where('sales_document_id', $mistake->id)->orderBy('seq')->get();
    expect($voided->status)->toBe(SalesDocumentStatus::Voided)
        ->and($voided->void_reason)->toBe('Emitida por error: duplicado de la F270001')
        ->and($records->pluck('kind')->all())->toBe([InvoiceRecordKind::Alta, InvoiceRecordKind::Anulacion])
        ->and($records[1]->previous_hash)->toBe($records[0]->hash)
        ->and($records[1]->invoice_type)->toBeNull();

    // Una con rectificativas no se anula por error; y solo un admin.
    app(InvoiceCorrections::class)->rectify($this->invoice, $this->admin, 'Una diferencia', [
        ['line_id' => SalesDocumentLine::query()->where('sales_document_id', $this->invoice->id)->orderBy('position')->value('id'), 'quantity' => '14', 'unit_price' => '60'],
    ]);
    expect(fn () => app(InvoiceCorrections::class)->void(SalesDocument::query()->findOrFail($this->invoice->id), $this->admin, 'No debía existir'))
        ->toThrow(ValidationException::class, 'tiene rectificativas');

    $manager = userWithRole('employee');
    $manager->givePermissionTo(['view-financials', 'manage-billing']);
    $other = invoicingIssue($this->admin, $this->client);
    expect(fn () => app(InvoiceCorrections::class)->void($other, $manager, 'No es admin'))->toThrow(AuthorizationException::class);
});
