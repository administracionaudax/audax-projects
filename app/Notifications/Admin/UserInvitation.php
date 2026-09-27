<?php

namespace App\Notifications\Admin;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitación a la app (SPEC §14, D-017): email por la cola `mail` con un enlace de un solo uso para
 * que la persona fije su contraseña en /invitacion/{token}. Usa el broker «invitations», que caduca
 * a los 7 días (auth.passwords.invitations.expire); si caduca, se reenvía desde /admin/usuarios.
 */
class UserInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly string $companyName,
        public readonly ?string $invitedBy = null,
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

    public function toMail(User $notifiable): MailMessage
    {
        $email = $notifiable->getEmailForPasswordReset();
        $days = max(intdiv((int) config('auth.passwords.invitations.expire', 10080), 60 * 24), 1);
        $url = route('invitation.show', ['token' => $this->token, 'email' => $email]);

        // Usuarios del portal (Fase 5, D-063): el mismo enlace, con el asunto y la presentación del portal.
        $texts = $notifiable->isClient() ? 'portal.access.invitation' : 'admin.invitation';

        $message = (new MailMessage)
            ->subject(__("{$texts}.subject", ['company' => $this->companyName]))
            ->greeting(__('admin.invitation.greeting', ['name' => $notifiable->name]))
            ->line($this->invitedBy !== null
                ? __("{$texts}.intro_by", ['company' => $this->companyName, 'inviter' => $this->invitedBy])
                : __("{$texts}.intro", ['company' => $this->companyName]))
            ->line(__('admin.invitation.instructions'))
            ->action(__('admin.invitation.action'), $url)
            ->line(__('admin.invitation.expires', ['days' => $days]))
            ->line(__('admin.invitation.ignore'))
            ->salutation(__('admin.invitation.salutation', ['company' => $this->companyName]));

        return $message;
    }
}
