<?php

namespace App\Domain\Billing\Holded;

use App\Models\HoldedInvoice;
use Illuminate\Support\Facades\Storage;

/**
 * El PDF original de una factura de Holded en el disco privado (Fase 12, D-389): se descarga en la
 * sincronización o, si aún no está, la primera vez que alguien lo pide. Ruta:
 * holded/{año}/{id de Holded}.pdf. Nunca se sirve sin pasar por el permiso view-billing.
 */
final class HoldedPdfStore
{
    public static function path(HoldedInvoice $invoice): string
    {
        return 'holded/'.$invoice->issued_on->format('Y').'/'.preg_replace('/[^A-Za-z0-9_-]/', '', $invoice->holded_id).'.pdf';
    }

    /**
     * Descarga y guarda el PDF. Devuelve su contenido.
     *
     * @throws HoldedRequestFailed
     */
    public static function fetch(HoldedApi $api, HoldedInvoice $invoice): string
    {
        $content = $api->pdf($invoice->holded_id, $invoice->kind);
        $path = self::path($invoice);
        Storage::disk(HoldedInvoice::PDF_DISK)->put($path, $content);

        $invoice->forceFill(['pdf_path' => $path, 'pdf_fetched_at' => now()])->save();

        return $content;
    }

    /**
     * El contenido del PDF: el guardado o, si falta, el de Holded (y se guarda). La API solo se pide
     * si hace falta (sin clave, un PDF ya guardado se sigue pudiendo descargar).
     *
     * @param  callable(): HoldedApi  $api
     *
     * @throws HoldedRequestFailed
     */
    public static function contents(callable $api, HoldedInvoice $invoice): string
    {
        $disk = Storage::disk(HoldedInvoice::PDF_DISK);

        if ($invoice->pdf_path !== null && $disk->exists($invoice->pdf_path)) {
            return (string) $disk->get($invoice->pdf_path);
        }

        return self::fetch($api(), $invoice);
    }
}
