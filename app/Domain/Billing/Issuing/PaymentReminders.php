<?php

namespace App\Domain\Billing\Issuing;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\SalesDocumentStatus;
use App\Enums\SalesDocumentType;
use App\Models\PaymentReminderDelivery;
use App\Models\PaymentReminderRule;
use App\Models\SalesDocument;
use App\Models\SalesDocumentEmail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Los recordatorios de cobro por escalones (PLAN-EMISION §3.3, H-092; E2, D-442), una vez al día
 * (`app:billing-send-reminders`, 09:00 de Madrid):
 * - **Escalones** en Ajustes (de serie: 3 días antes, el día del vencimiento, y 7 y 30 días después).
 * - **A quién**: facturas propias emitidas (no rectificativas ni de la serie de pruebas) con algo
 *   pendiente y vencimiento, de clientes con los recordatorios encendidos. **Nunca** a una cobrada o
 *   anulada: se mira justo antes de enviar.
 * - **Cuál**: el último escalón que ya ha llegado, como mucho de hace 7 días (si un día no corre, al
 *   siguiente se pone al día; al encender el módulo no se manda una tanda de recordatorios viejos) y
 *   no anterior a la emisión. Uno por factura y escalón, y nunca uno anterior a otro ya enviado.
 * - **Destinatarios**: los del último envío de la factura o, si no se ha enviado, los emails de
 *   facturación del cliente. Sin ninguno, el recordatorio queda «no enviado» y sale en «Requiere
 *   atención» del Resumen, igual que si falla el correo.
 * - **Modo de prueba** (D-239): con los módulos `billing` o `invoicing` sin encender de verdad no se
 *   envía nada, ni siquiera a los admins.
 */
final class PaymentReminders
{
    /** Días que un escalón sigue valiendo si no se envió en su día. */
    public const int CATCH_UP_DAYS = 7;

    public function __construct(private readonly InvoiceMailer $mailer) {}

    public static function active(): bool
    {
        return AppModules::enabled(AppModule::Billing) && AppModules::enabled(AppModule::Invoicing);
    }

    /**
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function run(CarbonImmutable $today): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        if (! self::active()) {
            return $result;
        }

        $rules = PaymentReminderRule::query()->current()->where('is_active', true)->get();
        if ($rules->isEmpty()) {
            return $result;
        }

        foreach ($this->candidates($today, $rules)->lazyById(100) as $document) {
            $rule = self::dueRule($document, $rules, $today);
            if ($rule === null) {
                continue;
            }

            $result[$this->remind($document, $rule)]++;
        }

        return $result;
    }

    /**
     * El escalón que toca hoy a una factura (o null): el último que ya ha llegado, de hace como mucho
     * CATCH_UP_DAYS, no anterior a la emisión y sin otro igual o posterior ya enviado.
     *
     * @param  Collection<int, PaymentReminderRule>  $rules
     */
    public static function dueRule(SalesDocument $document, Collection $rules, CarbonImmutable $today): ?PaymentReminderRule
    {
        if ($document->due_date === null) {
            return null;
        }

        $day = $today->toDateString();
        $oldest = $today->subDays(self::CATCH_UP_DAYS)->toDateString();
        $delivered = $document->relationLoaded('reminderDeliveries')
            ? $document->getRelation('reminderDeliveries')->pluck('rule_id')->all()
            : PaymentReminderDelivery::query()->where('sales_document_id', $document->id)->pluck('rule_id')->all();
        $deliveredDays = $rules->whereIn('id', $delivered)->pluck('days')->all();
        $latestDelivered = $deliveredDays === [] ? null : max($deliveredDays);

        $due = null;
        foreach ($rules->sortBy('days') as $rule) {
            $date = $document->due_date->addDays($rule->days)->toDateString();
            if ($date <= $day && $date >= $oldest && $date >= $document->issue_date->toDateString()) {
                $due = $rule;
            }
        }

        if ($due === null || in_array($due->id, $delivered, true) || ($latestDelivered !== null && $latestDelivered >= $due->days)) {
            return null;
        }

        return $due;
    }

    /**
     * Manda el recordatorio de un escalón (o anota por qué no se ha podido): `sent`, `failed` o
     * `skipped` (ya no hay nada que recordar, o ya estaba).
     *
     * @return 'sent'|'failed'|'skipped'
     */
    public function remind(SalesDocument $document, PaymentReminderRule $rule): string
    {
        $document->refresh();
        // Justo antes de enviar: nunca a una cobrada, anulada ni sin nada pendiente.
        if ($document->status !== SalesDocumentStatus::Issued || $document->isCreditNote() || bccomp($document->pendingAmount(), '0', 2) <= 0) {
            return 'skipped';
        }

        $delivery = PaymentReminderDelivery::query()->firstOrNew(['sales_document_id' => $document->id, 'rule_id' => $rule->id]);
        if ($delivery->exists) {
            return 'skipped';
        }

        $recipients = self::recipients($document);

        if ($recipients['to'] === []) {
            $delivery->fill(['status' => PaymentReminderDelivery::FAILED, 'detail' => 'no_recipients'])->save();

            return 'failed';
        }

        $template = EmailTemplates::get('reminder', EmailTemplates::language($document->language));
        $values = EmailTemplates::variables($document);

        try {
            $email = DB::transaction(function () use ($document, $rule, $recipients, $template, $values, $delivery): SalesDocumentEmail {
                $email = $this->mailer->send($document, [
                    'to' => $recipients['to'],
                    'cc' => $recipients['cc'],
                    'subject' => EmailTemplates::fill($template['subject'], $values),
                    'body' => EmailTemplates::fill($template['body'], $values),
                ], null, 'reminder', $rule);
                $delivery->fill(['status' => PaymentReminderDelivery::SENT, 'email_id' => $email->id])->save();

                return $email;
            });
        } catch (Throwable $exception) {
            report($exception);
            $delivery->fill(['status' => PaymentReminderDelivery::FAILED, 'detail' => mb_substr($exception->getMessage(), 0, 255)])->save();

            return 'failed';
        }

        return $email->refresh()->status === SalesDocumentEmail::FAILED ? 'failed' : 'sent';
    }

    /**
     * Los del último envío de la factura (no de prueba) o los emails de facturación del cliente.
     *
     * @return array{to: list<string>, cc: list<string>}
     */
    public static function recipients(SalesDocument $document): array
    {
        $last = SalesDocumentEmail::query()->where('sales_document_id', $document->id)->where('kind', 'send')
            ->where('status', SalesDocumentEmail::SENT)->where('test_mode', false)->orderByDesc('id')->first();
        if ($last !== null) {
            return ['to' => array_values($last->to), 'cc' => array_values($last->cc ?? [])];
        }

        $emails = $document->client()->with('billingProfile')->first()?->billingProfile?->billing_emails ?? [];

        return ['to' => array_values($emails), 'cc' => []];
    }

    /**
     * Los recordatorios que no han salido (sin destinatarios o con el correo fallido) de facturas que
     * siguen pendientes: «Requiere atención» del Resumen.
     *
     * @return Builder<PaymentReminderDelivery>
     */
    public static function failed(): Builder
    {
        return PaymentReminderDelivery::query()
            ->join('sales_documents', 'sales_documents.id', '=', 'payment_reminder_deliveries.sales_document_id')
            ->leftJoin('sales_document_emails', 'sales_document_emails.id', '=', 'payment_reminder_deliveries.email_id')
            ->where('sales_documents.status', SalesDocumentStatus::Issued->value)
            ->whereColumn('sales_documents.total', '>', 'sales_documents.paid_total')
            ->where(fn (Builder $q) => $q->where('payment_reminder_deliveries.status', PaymentReminderDelivery::FAILED)
                ->orWhere('sales_document_emails.status', SalesDocumentEmail::FAILED));
    }

    /**
     * Las facturas que pueden tener un recordatorio hoy (una consulta; los escalones se miran en PHP).
     *
     * @param  Collection<int, PaymentReminderRule>  $rules
     * @return Builder<SalesDocument>
     */
    private function candidates(CarbonImmutable $today, Collection $rules): Builder
    {
        $min = (int) $rules->min('days');
        $max = (int) $rules->max('days');

        return SalesDocument::query()
            ->where('status', SalesDocumentStatus::Issued->value)
            ->where('type', SalesDocumentType::Invoice->value)
            ->where('is_test', false)
            ->whereNotNull('due_date')
            ->whereColumn('total', '>', 'paid_total')
            // El vencimiento entre el escalón más tardío (y la puesta al día) y el más temprano.
            ->where('due_date', '>=', $today->subDays($max + self::CATCH_UP_DAYS)->toDateString())
            ->where('due_date', '<=', $today->subDays($min)->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('client_billing_profiles')
                ->whereColumn('client_billing_profiles.client_id', 'sales_documents.client_id')
                ->where('client_billing_profiles.payment_reminders', false))
            ->with('reminderDeliveries:id,sales_document_id,rule_id');
    }
}
