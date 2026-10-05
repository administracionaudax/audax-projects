<?php

namespace App\Notifications\Weeklies;

use App\Domain\Weeklies\Reminders\TracksWeeklyReminderLogs;
use App\Domain\Weeklies\Reminders\WeeklyTemplates;
use App\Domain\Weeklies\WeeklyCalendar;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Notifications\AppNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Base de los avisos de la weekly (10.5, D-199 a D-201): la foto de la semana (número, etiqueta y
 * plazo, por si cambia después) y, si tiene plantilla, su asunto y su cuerpo tal como estaban al
 * enviar. El email es el cuerpo de la plantilla con sus variables ({nombre}, {semana},
 * {week_label} y {weekly_url}) y un botón al enlace; en la campana y en el navegador, el asunto
 * como título y una línea con la semana y el plazo. Cierra sus filas del registro
 * (TracksWeeklyReminderLogs).
 */
abstract class WeeklyNotice extends AppNotification
{
    use TracksWeeklyReminderLogs;

    public int $cycleId;

    public string $cycleNumber;

    public string $cycleLabel;

    public string $deadline;

    public function __construct(WeeklyCycle $cycle, public ?string $subject = null, public ?string $mailBody = null)
    {
        $this->cycleId = $cycle->id;
        $this->cycleNumber = $cycle->number;
        $this->cycleLabel = $cycle->label;
        $this->deadline = $cycle->deadline_date->toDateString();
    }

    /** Ruta relativa a la que lleva el aviso. */
    abstract protected function path(): string;

    /** Texto del botón del email. */
    abstract protected function actionLabel(): string;

    public function url(object $notifiable): ?string
    {
        return $this->path();
    }

    public function title(object $notifiable): string
    {
        return $this->subject !== null ? $this->render($this->subject, $notifiable) : $this->fallbackTitle();
    }

    public function icon(): ?string
    {
        return 'notebook-pen';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $body = $this->mailBody !== null ? $this->render($this->mailBody, $notifiable) : (string) $this->body($notifiable);
        $paragraphs = array_values(array_filter(
            array_map(trim(...), preg_split('/\n\s*\n/', $body) ?: []),
            fn (string $paragraph): bool => $paragraph !== '',
        ));

        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->markdown('mail.weeklies.notice', [
                'paragraphs' => array_map(fn (string $paragraph): array => explode("\n", $paragraph), $paragraphs),
                'url' => url($this->path()),
                'action' => $this->actionLabel(),
            ]);
    }

    /**
     * Variables de las plantillas para quien lo recibe.
     *
     * @return array<string, string>
     */
    public function variables(object $notifiable): array
    {
        return [
            'nombre' => $notifiable instanceof User ? $notifiable->name : '',
            'semana' => $this->cycleNumber,
            'week_label' => $this->cycleLabel,
            'weekly_url' => url($this->path()),
        ];
    }

    protected function fallbackTitle(): string
    {
        return $this->cycleLabel;
    }

    protected function render(string $text, object $notifiable): string
    {
        return WeeklyTemplates::render($text, $this->variables($notifiable));
    }

    /** El plazo, en largo: «viernes 9 de octubre». */
    protected function deadlineText(): string
    {
        return CarbonImmutable::parse($this->deadline, WeeklyCalendar::TIMEZONE)->settings(['locale' => 'es'])->isoFormat('dddd D [de] MMMM');
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
