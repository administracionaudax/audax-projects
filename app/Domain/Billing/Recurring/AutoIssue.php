<?php

namespace App\Domain\Billing\Recurring;

use App\Domain\Billing\Issuing\InvoiceIssuer;
use App\Domain\Billing\Issuing\InvoiceMailer;
use App\Domain\Billing\Issuing\InvoicingAccess;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Emitir y enviar sin nadie delante (E3): las recurrentes en modo «Emitir» y las programadas. Se
 * emite en nombre de quien lo dejó preparado (con manage-billing; si ya no lo tiene, no se emite) y
 * se envía a los emails de facturación del cliente con la plantilla de su tipo e idioma. Lo que no
 * sale deja la factura como estaba, con el motivo en `auto_issue_error` («Requiere atención»).
 */
final class AutoIssue
{
    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly InvoiceMailer $mailer,
    ) {}

    /**
     * Emite el borrador. Devuelve la emitida o el motivo por el que no se pudo.
     */
    public function issue(SalesDocument $document, ?User $by): SalesDocument|string
    {
        try {
            if ($by === null || ! InvoicingAccess::manages($by)) {
                throw ValidationException::withMessages(['document' => __('invoicing_work.auto.no_issuer')]);
            }

            return $this->issuer->issue($document, $by);
        } catch (Throwable $error) {
            return self::fail($document, __('invoicing_work.auto.not_issued', ['reason' => self::reason($error)]));
        }
    }

    /** Envía una emitida a los emails de facturación del cliente. Null si salió; si no, el motivo. */
    public function send(SalesDocument $document, User $by): ?string
    {
        try {
            $draft = $this->mailer->compose($document, $by);
            $this->mailer->send($document, ['to' => $draft['to'], 'subject' => $draft['subject'], 'body' => $draft['body']], $by);
        } catch (Throwable $error) {
            return self::fail($document, __('invoicing_work.auto.not_sent', ['reason' => self::reason($error)]));
        }

        return null;
    }

    private static function fail(SalesDocument $document, string $message): string
    {
        $message = mb_substr($message, 0, 500);
        SalesDocument::query()->whereKey($document->id)->update(['auto_issue_error' => $message]);

        return $message;
    }

    /** El motivo de un error, legible (los de validación, sus mensajes). */
    public static function reason(Throwable $error): string
    {
        if ($error instanceof ValidationException) {
            return implode(' ', array_map(fn (mixed $message): string => (string) $message, array_merge(...array_values($error->errors()))));
        }

        return $error->getMessage();
    }
}
