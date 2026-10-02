<?php

namespace App\Notifications\Portal;

use App\Models\HourBank;
use App\Models\Setting;
use App\Support\Duration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email al cliente: su bolsa ha llegado al 90 % o al 100 % de lo que ve en el portal (D-065).
 * Sin importes ni tarifas; enlaza al detalle de la bolsa en el portal.
 */
class ClientHourBankThreshold extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly int $bankId;

    public readonly string $bankName;

    public readonly string $projectName;

    /**
     * @param  array{total_minutes: int, within_minutes: int, overage_minutes: int, remaining_minutes: int, percent: float}  $figures
     */
    public function __construct(HourBank $bank, public readonly int $threshold, public readonly array $figures)
    {
        $this->bankId = $bank->id;
        $this->bankName = $bank->name;
        $this->projectName = $bank->project->name;
        $this->onQueue('mail');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = (string) Setting::get('company_name');
        $key = $this->threshold >= 100 ? 'exhausted' : 'near';

        return (new MailMessage)
            ->subject(__("portal.mail.threshold.{$key}.subject", ['bank' => $this->bankName, 'threshold' => $this->threshold]))
            ->greeting(__('portal.mail.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__("portal.mail.threshold.{$key}.intro", [
                'bank' => $this->bankName,
                'project' => $this->projectName,
                'threshold' => $this->threshold,
            ]))
            ->line(__('portal.mail.threshold.figures', [
                'within' => Duration::format($this->figures['within_minutes']),
                'total' => Duration::format($this->figures['total_minutes']),
                'remaining' => Duration::format($this->figures['remaining_minutes']),
            ]))
            ->action(__('portal.mail.threshold.action'), url("/portal/bolsas/{$this->bankId}"))
            ->salutation(__('portal.mail.salutation', ['company' => $company]));
    }
}
