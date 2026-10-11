<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Issuing\DocumentActions;
use App\Domain\Billing\Issuing\InvoiceMailer;
use App\Http\Controllers\Controller;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Enviar una factura propia por email (PLAN-EMISION §3.4; E2, D-438 a D-441): lo que pinta el diálogo
 * (JSON) y el envío. Con ?recordatorio=1, el recordatorio de cobro a mano (solo con algo pendiente).
 * Detrás de los módulos y de use-invoicing (la ruta) y de la matriz DocumentActions (manage-billing).
 */
class SalesDocumentEmailController extends Controller
{
    public function compose(Request $request, SalesDocument $document, InvoiceMailer $mailer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reminder = $request->boolean('recordatorio');
        $this->authorizeSend($document, $user, $reminder);

        return response()->json($mailer->compose($document, $user, $reminder));
    }

    public function store(Request $request, SalesDocument $document, InvoiceMailer $mailer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reminder = $request->boolean('reminder');
        $this->authorizeSend($document, $user, $reminder);

        $data = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:'.InvoiceMailer::MAX_RECIPIENTS],
            'to.*' => ['required', 'string', 'email:rfc', 'max:255'],
            'cc' => ['nullable', 'array', 'max:'.InvoiceMailer::MAX_RECIPIENTS],
            'cc.*' => ['required', 'string', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ], [], (array) __('invoicing.emails.attributes'));

        $email = $mailer->send($document, [
            'to' => array_values(array_map('strval', (array) $data['to'])),
            'cc' => array_values(array_map('strval', (array) ($data['cc'] ?? []))),
            'subject' => (string) $data['subject'],
            'body' => (string) $data['body'],
        ], $user, $reminder ? 'reminder' : 'send');

        Inertia::flash('toast', ['type' => 'success', 'message' => $email->test_mode
            ? __('invoicing.flash.email_test', ['email' => $user->email])
            : trans_choice($reminder ? 'invoicing.flash.reminder_sent' : 'invoicing.flash.email_sent', count($email->to), ['to' => implode(', ', $email->to)])]);

        return back();
    }

    private function authorizeSend(SalesDocument $document, User $user, bool $reminder): void
    {
        abort_unless(DocumentActions::allows($document, $user, $reminder ? 'send_reminder' : 'send') && InvoiceMailer::sendable($document), 403);
        abort_if($reminder && bccomp($document->pendingAmount(), '0', 2) <= 0, 422, __('invoicing.emails.errors.nothing_pending'));
    }
}
