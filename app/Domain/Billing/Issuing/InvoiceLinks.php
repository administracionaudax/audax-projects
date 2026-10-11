<?php

namespace App\Domain\Billing\Issuing;

use App\Models\SalesDocument;
use App\Models\SalesDocumentEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * El enlace firmado de una factura para el cliente (PLAN-EMISION §3.4, H-098; E2, D-440):
 * - `/factura/{uuid}?envio={id}&expires=…&signature=…`, sin usuario: lo abre cualquiera que tenga el
 *   enlace mientras no caduque (60 días). Lleva el uuid de la factura (no su id) y el envío del que
 *   sale, para saber qué email abrió el cliente. Caducado o manipulado: una página que lo explica.
 * - «Vista por el cliente»: se anota la primera vez y cuántas veces se abre la página o se descarga
 *   el PDF desde el enlace (o desde el portal), sin píxel espía. Las visitas de la plantilla (con la
 *   sesión iniciada) no cuentan.
 */
final class InvoiceLinks
{
    public static function show(SalesDocument $document, ?SalesDocumentEmail $email = null, ?CarbonImmutable $expires = null): string
    {
        return URL::temporarySignedRoute('invoices.public.show', $expires ?? self::expiry(), self::parameters($document, $email));
    }

    public static function pdf(SalesDocument $document, ?int $emailId, CarbonImmutable $expires, bool $download = false): string
    {
        return URL::temporarySignedRoute('invoices.public.pdf', $expires, [...self::parameters($document, $emailId), ...($download ? ['descargar' => 1] : [])]);
    }

    public static function attachment(SalesDocument $document, int $attachmentId, ?int $emailId, CarbonImmutable $expires): string
    {
        return URL::temporarySignedRoute('invoices.public.attachment', $expires, [...self::parameters($document, $emailId), 'attachment' => $attachmentId]);
    }

    public static function expiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(InvoiceEmailSettings::LINK_DAYS);
    }

    /**
     * Anota una vista del cliente en la factura y, si viene de un email, en ese envío. Las de la
     * plantilla interna no cuentan.
     */
    public static function recordView(SalesDocument $document, ?int $emailId, ?User $viewer): void
    {
        if ($viewer !== null && $viewer->isInternal()) {
            return;
        }

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($document, $emailId, $now): void {
            SalesDocument::query()->whereKey($document->id)->whereNull('first_viewed_at')->update(['first_viewed_at' => $now]);
            SalesDocument::query()->whereKey($document->id)->update(['last_viewed_at' => $now, 'view_count' => DB::raw('view_count + 1')]);

            if ($emailId !== null) {
                $email = SalesDocumentEmail::query()->where('sales_document_id', $document->id)->whereKey($emailId);
                (clone $email)->whereNull('first_viewed_at')->update(['first_viewed_at' => $now]);
                $email->update(['last_viewed_at' => $now, 'view_count' => DB::raw('view_count + 1')]);
            }
        });
    }

    /**
     * @return array<string, int|string>
     */
    private static function parameters(SalesDocument $document, SalesDocumentEmail|int|null $email): array
    {
        $id = $email instanceof SalesDocumentEmail ? $email->id : $email;

        return ['document' => $document->uuid, ...($id !== null ? ['envio' => $id] : [])];
    }
}
