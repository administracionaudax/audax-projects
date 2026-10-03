<?php

use App\Models\ReportDelivery;
use App\Models\ReportDownload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
| reports:prune-downloads (D-141): los informes enviados como enlace se borran al caducar el
| enlace, del disco privado y de la base.
*/

it('borra los ficheros caducados y conserva los vigentes', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $delivery = ReportDelivery::query()->create([
        'sender_user_id' => $admin->id,
        'title' => 'Informe',
        'request' => ['kind' => 'detail'],
        'formats' => ['pdf'],
        'recipient_user_ids' => [],
        'recipient_emails' => ['a@example.com'],
        'status' => 'sent',
    ]);

    $make = function (string $name, int $days) use ($delivery): ReportDownload {
        Storage::disk('local')->put("reports/deliveries/{$name}.pdf", 'pdf');

        return ReportDownload::query()->create([
            'delivery_id' => $delivery->id,
            'disk' => 'local',
            'path' => "reports/deliveries/{$name}.pdf",
            'filename' => 'informe.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 3,
            'expires_at' => now()->addDays($days),
        ]);
    };

    $expired = $make('caducado', -1);
    $valid = $make('vigente', 3);

    $this->artisan('reports:prune-downloads')->assertSuccessful();

    Storage::disk('local')->assertMissing($expired->path);
    Storage::disk('local')->assertExists($valid->path);
    expect(ReportDownload::query()->pluck('id')->all())->toBe([$valid->id]);
});
