<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Base de los avisos de ausencias (SPEC §13, D-049): en la app (campana) y por email, siempre por
 * cola (el email, por la cola `mail`). Guarda una foto en datos planos (no el modelo): el texto no
 * cambia si la ausencia cambia después y la cola no falla si se borra.
 */
abstract class AbsenceNotification extends AppNotification
{
    public int $absenceId;

    public string $ownerName;

    public string $type;

    public string $startDate;

    public string $endDate;

    public ?int $partialMinutes;

    /** Quien provoca el aviso (quien solicita, aprueba, rechaza, registra o anula). */
    public string $actorName;

    public function __construct(Absence $absence, User $actor)
    {
        $this->absenceId = $absence->id;
        $this->ownerName = $absence->relationLoaded('user') ? $absence->user->name : (string) User::query()->whereKey($absence->user_id)->value('name');
        $this->type = $absence->type->value;
        $this->startDate = $absence->start_date->toDateString();
        $this->endDate = $absence->end_date->toDateString();
        $this->partialMinutes = $absence->partial_minutes;
        $this->actorName = $actor->name;
    }

    /**
     * «/ausencias» para la persona y «/ausencias/equipo» para quien aprueba.
     */
    public function url(object $notifiable): ?string
    {
        return route($this->forApprover() ? 'absences.team.index' : 'absences.index', absolute: false);
    }

    /**
     * ¿El aviso es para quien aprueba (y no para la persona de la ausencia)?
     */
    protected function forApprover(): bool
    {
        return false;
    }

    /**
     * El email: el asunto en texto plano y el resto en Markdown, con los textos de la gente (nombres,
     * notas y comentarios) escapados (AbsenceText::markdown): nada de enlaces ni formato colados.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable instanceof User ? $notifiable->name : '';
        $mail = (new MailMessage)
            ->subject($this->title($notifiable))
            ->greeting(AbsenceText::markdown(AbsenceText::get('absences.mail.greeting', ['name' => $name])))
            ->line(AbsenceText::markdown($this->title($notifiable)));

        $body = $this->body($notifiable);
        if ($body !== null && $body !== '') {
            $mail->line(AbsenceText::markdown($body));
        }

        return $mail
            ->action(
                AbsenceText::get($this->forApprover() ? 'absences.mail.action_team' : 'absences.mail.action_mine'),
                url((string) $this->url($notifiable)),
            )
            ->salutation(AbsenceText::get('absences.mail.salutation', [
                'company' => AbsenceText::markdown((string) Setting::get('company_name', config('app.name'))),
            ]));
    }

    protected function absenceType(): AbsenceType
    {
        return AbsenceType::from($this->type);
    }

    /**
     * Reemplazos comunes de los textos: nombre, tipo (etiqueta y en frase) y fechas.
     *
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        return [
            'name' => $this->ownerName,
            'actor' => $this->actorName,
            'reviewer' => $this->actorName,
            'type' => $this->absenceType()->label(),
            'phrase' => AbsenceText::phrase($this->absenceType()),
            'period' => AbsenceText::period($this->startDate, $this->endDate, $this->partialMinutes),
        ];
    }
}
