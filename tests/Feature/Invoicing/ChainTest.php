<?php

use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Billing\Issuing\RecordChain;
use App\Models\InvoiceRecord;
use App\Models\SalesDocumentLine;
use App\Notifications\Billing\BillingChainBroken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| T-CAD (PLAN-EMISION §8.2; V-05, D-420 y D-429): la cadena no tiene huecos ni bifurcaciones, y
| app:billing-verify-chain delata una fila alterada, un hueco en la numeración o un contador que no
| cuadra (con los *triggers* quitados dentro del test, que se deshace).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();

    // Emitir, rectificar, anular y anular por error, en secuencia.
    $this->first = invoicingIssue($this->admin, $this->client);
    $this->second = invoicingIssue($this->admin, $this->client);
    app(InvoiceCorrections::class)->rectify($this->first, $this->admin, 'Una hora de menos', [
        ['line_id' => SalesDocumentLine::query()->where('sales_document_id', $this->first->id)->value('id'), 'quantity' => '9', 'unit_price' => '60'],
    ]);
    app(InvoiceCorrections::class)->cancel($this->second, $this->admin, 'Se rehace con otro cliente');
    $this->third = invoicingIssue($this->admin, $this->client);
    app(InvoiceCorrections::class)->void($this->third, $this->admin, 'Emitida por error');
});

it('encadena cada registro con el anterior, en orden y sin huecos, con un solo primer registro', function () {
    $records = InvoiceRecord::query()->orderBy('seq')->get();

    expect($records->pluck('seq')->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and($records->pluck('kind')->map->value->all())->toBe(['alta', 'alta', 'alta', 'alta', 'alta', 'anulacion'])
        ->and($records->pluck('invoice_number')->all())->toBe(['F270001', 'F270002', 'CN270001', 'CN270002', 'F270003', 'F270003'])
        ->and($records->where('is_first', true)->count())->toBe(1);

    foreach ($records->slice(1) as $index => $record) {
        expect($record->previous_hash)->toBe($records[$index - 1]->hash)
            ->and($record->previous_record_id)->toBe($records[$index - 1]->id)
            ->and($record->payload['encadenamiento']['registro_anterior']['num_serie_factura'])->toBe($records[$index - 1]->invoice_number);
    }

    expect(app(RecordChain::class)->verify())->toMatchArray(['ok' => true, 'records' => 6, 'documents' => 5, 'problems' => []]);
});

it('app:billing-verify-chain sale bien con todo íntegro', function () {
    expect(Artisan::call('app:billing-verify-chain'))->toBe(0)
        ->and(Artisan::output())->toContain('Cadena íntegra: 6 registros y 5 facturas comprobados.');
});

it('delata una huella o un campo alterados, un registro borrado y avisa a los admins por la noche', function () {
    Notification::fake();

    DB::beginTransaction();
    tamperInvoicing();
    DB::table('invoice_records')->where('seq', 2)->update(['total_text' => '1.00']);
    DB::table('invoice_records')->where('seq', 6)->update(['seq' => 8]);
    $code = Artisan::call('app:billing-verify-chain', ['--nightly' => true]);
    $output = Artisan::output();
    DB::rollBack();

    expect($code)->toBe(1)
        ->and($output)->toContain('la huella del registro 2 (F270002) no coincide')
        ->toContain('el registro 8 no sigue al 5')
        ->toContain('El registro de la factura F270002 no coincide con la factura');

    Notification::assertSentTo($this->admin, BillingChainBroken::class);
});

it('delata una factura sin su registro de alta o una anulada por error sin el de anulación', function () {
    DB::beginTransaction();
    tamperInvoicing();
    DB::table('invoice_records')->where('seq', 6)->delete();
    DB::table('sales_documents')->where('id', $this->first->id)->update(['invoice_record_id' => 999]);
    $result = app(RecordChain::class)->verify();
    DB::rollBack();

    expect(implode(' ', $result['problems']))->toContain('La factura F270003, anulada por error, no tiene su registro de anulación')
        ->toContain('La factura F270001 no tiene su registro de alta');
});

it('delata un hueco en la numeración de una serie y un contador que no cuadra', function () {
    DB::beginTransaction();
    tamperInvoicing();
    DB::table('sales_documents')->where('id', $this->second->id)->update(['number' => 5, 'full_number' => 'F270005']);
    DB::table('numbering_counters')->where('series_id', invoicingSeries('F')->id)->update(['last_number' => 9]);
    $result = app(RecordChain::class)->verify();
    DB::rollBack();

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['problems']))->toContain('La factura F270003 rompe la correlación de su serie: tocaba el 2')
        ->toContain('El contador de la serie F en 2027 está en 9');
});
