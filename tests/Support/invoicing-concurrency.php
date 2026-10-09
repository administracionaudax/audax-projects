<?php

/*
| Proceso hijo del test de concurrencia de la emisión (tests/Feature/Invoicing/ConcurrencyTest.php;
| T-NUM, D-419): arranca la app contra la base SQLite en fichero que le pasa el test (DB_DATABASE)
| y, según el modo, prepara los datos o emite un borrador. Varios procesos emiten a la vez: la
| instalación bloqueada y el contador hacen que salgan números seguidos, sin huecos ni repetidos.
|
|   php tests/Support/invoicing-concurrency.php seed <borradores>   → ids de los borradores (JSON)
|   php tests/Support/invoicing-concurrency.php issue <id>          → número emitido
*/

use App\Domain\Billing\Issuing\DraftWriter;
use App\Domain\Billing\Issuing\InvoiceIssuer;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\NumberingSeries;
use App\Models\SalesDocument;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Los PDF de estos procesos, en una carpeta de pruebas (nunca en el disco privado de verdad).
config([
    'filesystems.disks.concurrency' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/concurrency')],
    'invoicing.disk' => 'concurrency',
    'services.reports_pdf.driver' => 'html',
    'queue.default' => 'sync',
    // Varios escritores sobre el mismo fichero: esperar al candado en lugar de fallar.
    'database.connections.sqlite.busy_timeout' => 30000,
]);

[$script, $mode, $argument] = $argv + [null, null, null];

if ($mode === 'seed') {
    (new RolesAndPermissionsSeeder)->run();
    $admin = User::factory()->withRole(Role::Admin)->create();
    Setting::set('modules', ['billing' => true, 'invoicing' => true]);
    Setting::set('billing_issuer', ['legal_name' => 'Audax Studio, S.L.', 'tax_id' => 'B12345678', 'address' => 'Calle de Colón, 1', 'postal_code' => '46004', 'city' => 'València', 'country_code' => 'ES']);
    $client = Client::factory()->create(['tax_id' => 'B87654321']);
    ClientBillingProfile::query()->create(['client_id' => $client->id, 'legal_name' => 'Cliente, S.L.', 'address' => 'Calle 1', 'postal_code' => '46001', 'city' => 'València', 'country_code' => 'ES']);
    $tax = TaxRate::query()->where('key', 'iva_21')->value('id');
    $series = NumberingSeries::query()->where('code', 'PRU')->value('id');

    $ids = [];
    foreach (range(1, (int) $argument) as $n) {
        $ids[] = app(DraftWriter::class)->save(null, [
            'client_id' => $client->id,
            'series_id' => $series,
            'issue_date' => now('Europe/Madrid')->toDateString(),
            'lines' => [['kind' => 'item', 'description' => "Línea {$n}", 'quantity' => '1', 'unit' => 'unit', 'unit_price' => (string) (100 + $n), 'discount_pct' => '0', 'tax_rate_id' => $tax]],
        ], $admin)->id;
    }

    echo json_encode(['admin' => $admin->id, 'drafts' => $ids]);
    exit(0);
}

if ($mode === 'issue') {
    $document = SalesDocument::query()->findOrFail((int) $argument);
    $admin = User::query()->role('admin')->orderBy('id')->firstOrFail();

    echo app(InvoiceIssuer::class)->issue($document, $admin)->full_number;
    exit(0);
}

fwrite(STDERR, "Modo desconocido\n");
exit(1);
