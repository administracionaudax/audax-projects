<?php

namespace App\Notifications\HourBanks;

use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Base de los avisos de bolsa (SPEC §8.5, §8.6 y §13): en la app (campana) y por email, siempre
 * por cola (el email, por la cola `mail`, AppNotification::viaQueues). Guarda una foto de las
 * cifras en el momento del aviso para que el texto no cambie si la bolsa sigue consumiéndose.
 */
abstract class HourBankNotification extends AppNotification
{
    public int $hourBankId;

    public int $projectId;

    public string $bankName;

    /** «ACME-WEB · Web corporativa». */
    public string $projectLabel;

    public int $consumedMinutes;

    public int $totalMinutes;

    public int $overageMinutes;

    public function __construct(HourBank $bank)
    {
        $project = Project::query()->withTrashed()->whereKey($bank->project_id)->first(['id', 'code', 'name']);

        $this->hourBankId = $bank->id;
        $this->projectId = $bank->project_id;
        $this->bankName = $bank->name;
        $this->projectLabel = $project !== null ? "{$project->code} · {$project->name}" : '';
        $this->consumedMinutes = $bank->consumed_minutes;
        $this->totalMinutes = $bank->total_minutes;
        $this->overageMinutes = $bank->overage_minutes;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function url(object $notifiable): ?string
    {
        return route('projects.hour-banks.show', ['project' => $this->projectId, 'hourBank' => $this->hourBankId], absolute: false);
    }

    /**
     * Texto extra del email (qué hacer ahora).
     */
    abstract protected function hint(): string;

    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable instanceof User ? $notifiable->name : '';

        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->greeting($this->trans('hour_banks.mail.greeting', ['name' => $name]))
            ->line((string) $this->body($notifiable))
            ->line($this->hint())
            ->action($this->trans('hour_banks.mail.action'), url((string) $this->url($notifiable)))
            ->salutation($this->trans('hour_banks.mail.salutation', [
                'company' => (string) Setting::get('company_name', config('app.name')),
            ]));
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected function trans(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
