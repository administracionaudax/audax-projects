<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\Reports\WeeklyDigestNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Resumen diario por email (SPEC §13, D-073) que envía notifications:daily-digest a las 08:00 de
 * Madrid a quien lo tiene activado, con el contenido de App\Domain\Notifications\DailyDigest.
 *
 * NO es una AppNotification: es el transporte del resumen, no un evento del catálogo. Solo va por
 * email (cola `mail`) y no deja nada en la campana, donde ya están los avisos que resume. Guarda
 * una foto en datos planos (títulos y enlaces relativos): el email no cambia si los avisos se leen
 * o se borran mientras espera en la cola.
 */
class DailyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{group: string, total: int, items: list<array{title: string, url: string|null}>}>  $groups
     * @param  int  $hours  horas que abarca el resumen (config notifications.daily_digest.hours)
     */
    public function __construct(
        public readonly array $groups,
        public readonly int $hours,
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Avisos que resume, sumando todos los grupos (también los que no se citan).
     */
    public function total(): int
    {
        return array_sum(array_column($this->groups, 'total'));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = $this->total();
        $name = $notifiable instanceof User ? $notifiable->name : '';

        $message = (new MailMessage)
            ->subject(trans_choice('notifications.digest.subject', $total, ['count' => $total]))
            ->greeting($name !== '' ? self::text('notifications.mail.greeting', ['name' => self::plain($name)]) : self::text('notifications.mail.greeting_anonymous'))
            ->line(trans_choice('notifications.digest.intro', $total, ['count' => $total, 'hours' => $this->hours]));

        foreach ($this->groups as $group) {
            $message->line($this->section($group));
        }

        $settings = self::link(self::text('notifications.digest.settings_link'), route('notification-settings.edit'));

        return $message
            ->action(self::text('notifications.digest.action'), route('notifications.index', ['filtro' => 'sin-leer']))
            ->line(new HtmlString(WeeklyDigestNotification::escape(self::text('notifications.digest.settings_hint'))."\n\n".$settings))
            ->salutation(self::text('notifications.mail.salutation', [
                'company' => (string) Setting::get('company_name', config('app.name')),
            ]));
    }

    /**
     * Un grupo del email: su nombre y cuántos avisos tiene, y una lista (Markdown) con los títulos
     * enlazados a su página y, si hay más de los que se citan, «y N más» enlazado a la campana.
     * Va como HtmlString para conservar los saltos de línea de la lista, así que cada texto se
     * escapa aquí: los títulos nunca se interpretan como HTML ni como Markdown.
     *
     * @param  array{group: string, total: int, items: list<array{title: string, url: string|null}>}  $group
     */
    private function section(array $group): HtmlString
    {
        $lines = array_map(
            fn (array $item): string => '- '.($item['url'] !== null
                ? self::link($item['title'], url($item['url']))
                : WeeklyDigestNotification::escape($item['title'])),
            $group['items'],
        );

        $rest = $group['total'] - count($group['items']);

        if ($rest > 0) {
            $lines[] = '- '.self::link(
                trans_choice('notifications.digest.more', $rest, ['count' => $rest]),
                route('notifications.index', ['filtro' => 'sin-leer']),
            );
        }

        $title = self::text('notifications.digest.group', [
            'group' => self::text("notifications.groups.{$group['group']}"),
            'count' => $group['total'],
        ]);

        return new HtmlString('**'.WeeklyDigestNotification::escape($title)."**\n\n".implode("\n", $lines));
    }

    /**
     * Enlace Markdown con el texto escapado. La dirección es de la propia app; aun así se codifican
     * los caracteres que cerrarían el enlace.
     */
    private static function link(string $text, string $url): string
    {
        $url = str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $url);

        return '['.WeeklyDigestNotification::escape($text).']('.$url.')';
    }

    /**
     * Texto que la plantilla ya escapa como HTML pero que después se lee como Markdown (el saludo):
     * sin enlaces, énfasis ni código colados en un nombre.
     */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]])/', '\\\\$1', trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
