<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Issuing\EmailTemplates;
use App\Domain\Billing\Issuing\InvoiceEmailSettings;
use App\Domain\Billing\Issuing\InvoicingAccess;
use App\Domain\Billing\Issuing\PaymentReminders;
use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\PaymentReminderDelivery;
use App\Models\PaymentReminderRule;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Ajustes del envío de las facturas propias (PLAN-EMISION §3.3 y §3.4, §6.2 «Ajustes»; E2, D-438,
 * D-439 y D-442): el remitente (nombre, respuesta y copia oculta), las plantillas de email por tipo e
 * idioma y los escalones de los recordatorios. Se ven con use-invoicing; se cambian con
 * manage-billing (la ruta). Cada cambio queda en la auditoría de ajustes.
 */
class InvoicingEmailSettingsController extends Controller
{
    /**
     * Lo que pintan los apartados «Correo» y «Recordatorios» de Ajustes.
     *
     * @return array<string, mixed>
     */
    public static function props(User $viewer): array
    {
        $rules = PaymentReminderRule::query()->current()->get();
        $used = PaymentReminderDelivery::query()->whereIn('rule_id', $rules->pluck('id'))
            ->selectRaw('rule_id, COUNT(*) as count')->groupBy('rule_id')->pluck('count', 'rule_id');

        return [
            'can_manage' => InvoicingAccess::manages($viewer),
            'sender' => [
                ...InvoiceEmailSettings::get(),
                'from' => InvoiceEmailSettings::fromAddress(),
                'default_name' => InvoiceEmailSettings::defaultName(),
                'link_days' => InvoiceEmailSettings::LINK_DAYS,
            ],
            'templates' => EmailTemplates::all(),
            'variables' => array_values(array_diff(EmailTemplates::VARIABLES, [])),
            'rules' => $rules->map(fn (PaymentReminderRule $rule): array => [
                'id' => $rule->id,
                'days' => $rule->days,
                'is_active' => $rule->is_active,
                'sent' => (int) ($used[$rule->id] ?? 0),
            ])->values()->all(),
            'reminders_active' => PaymentReminders::active(),
        ];
    }

    public function updateSender(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sender_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'bcc' => ['nullable', 'string', 'email:rfc', 'max:255'],
        ], [], (array) __('invoicing.emails.settings_attributes'));

        $old = InvoiceEmailSettings::get();
        InvoiceEmailSettings::put([
            'sender_name' => isset($data['sender_name']) ? (string) $data['sender_name'] : null,
            'reply_to' => isset($data['reply_to']) ? (string) $data['reply_to'] : null,
            'bcc' => isset($data['bcc']) ? (string) $data['bcc'] : null,
        ]);

        return $this->saved($request, 'email', $old, InvoiceEmailSettings::get());
    }

    public function updateTemplate(Request $request, string $kind, string $language): RedirectResponse
    {
        self::assertTemplate($kind, $language);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ], [], (array) __('invoicing.emails.attributes'));

        /** @var User $user */
        $user = $request->user();
        $old = EmailTemplates::get($kind, $language);
        EmailTemplate::query()->updateOrCreate(
            ['kind' => $kind, 'language' => $language],
            ['subject' => trim((string) $data['subject']), 'body' => trim((string) $data['body']), 'updated_by' => $user->id],
        );

        return $this->saved($request, "template.{$kind}.{$language}", $old, EmailTemplates::get($kind, $language));
    }

    /** «Volver a la de serie»: quita la plantilla cambiada. */
    public function resetTemplate(Request $request, string $kind, string $language): RedirectResponse
    {
        self::assertTemplate($kind, $language);
        $old = EmailTemplates::get($kind, $language);
        EmailTemplate::query()->where('kind', $kind)->where('language', $language)->delete();

        return $this->saved($request, "template.{$kind}.{$language}", $old, EmailTemplates::get($kind, $language));
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $this->validateRule($request, null);
        $rule = PaymentReminderRule::query()->create(['days' => (int) $data['days'], 'is_active' => (bool) ($data['is_active'] ?? true)]);

        return $this->saved($request, 'reminder_rule', [], $rule->only(['days', 'is_active']));
    }

    public function updateRule(Request $request, PaymentReminderRule $rule): RedirectResponse
    {
        abort_if($rule->archived_at !== null, 404);
        $data = $this->validateRule($request, $rule);
        $old = $rule->only(['days', 'is_active']);
        $rule->fill(['days' => (int) $data['days'], 'is_active' => (bool) ($data['is_active'] ?? true)])->save();

        return $this->saved($request, 'reminder_rule', $old, $rule->only(['days', 'is_active']));
    }

    /** Quitar un escalón: se archiva (sus recordatorios enviados lo siguen nombrando). */
    public function destroyRule(Request $request, PaymentReminderRule $rule): RedirectResponse
    {
        abort_if($rule->archived_at !== null, 404);
        $old = $rule->only(['days', 'is_active']);
        $rule->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        return $this->saved($request, 'reminder_rule', $old, []);
    }

    /** Un tipo o un idioma que no existe, 404 (después de los permisos de la ruta). */
    private static function assertTemplate(string $kind, string $language): void
    {
        abort_unless(in_array($kind, EmailTemplates::KINDS, true) && in_array($language, EmailTemplates::LANGUAGES, true), 404);
    }

    /**
     * @return array{days: int, is_active?: bool}
     */
    private function validateRule(Request $request, ?PaymentReminderRule $rule): array
    {
        /** @var array{days: int, is_active?: bool} */
        return $request->validate([
            'days' => ['required', 'integer', 'between:-60,365', Rule::unique('payment_reminder_rules', 'days')->whereNull('archived_at')->ignore($rule?->id)],
            'is_active' => ['boolean'],
        ], ['days.unique' => __('invoicing.reminders.errors.duplicate')], (array) __('invoicing.reminders.attributes'));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function saved(Request $request, string $what, array $old, array $new): RedirectResponse
    {
        $key = 'invoicing_'.str_replace('.', '_', $what);
        activity('settings')->causedBy($request->user())->event('updated')
            ->withProperties(['old' => [$key => $old], 'attributes' => [$key => $new]])
            ->log('settings.updated');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.settings_saved')]);

        return back();
    }
}
