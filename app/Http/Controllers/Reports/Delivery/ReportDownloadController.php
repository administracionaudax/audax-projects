<?php

namespace App\Http\Controllers\Reports\Delivery;

use App\Domain\Reports\Delivery\DeliveryAudit;
use App\Http\Controllers\Controller;
use App\Models\ReportDownload;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de un informe enviado como enlace (D-141, más de 10 MB). Sin sesión: lo abre quien
 * recibió el correo, también un destinatario externo. Solo con la firma de la URL (middleware
 * `signed`, caduca a los 7 días) y mientras el fichero no haya caducado. Cada descarga queda en
 * la auditoría.
 */
class ReportDownloadController extends Controller
{
    public function __invoke(ReportDownload $download): StreamedResponse
    {
        abort_if($download->expires_at->isPast(), 410);

        $disk = Storage::disk($download->disk);
        abort_unless($disk->exists($download->path), 410);

        DeliveryAudit::record('report_downloaded', $download->delivery, null, ['download_id' => $download->id, 'filename' => $download->filename]);

        return $disk->download($download->path, $download->filename, ['Content-Type' => $download->mime]);
    }
}
