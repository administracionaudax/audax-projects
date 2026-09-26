<?php

namespace App\Notifications\Admin;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitación a la app (SPEC §14, D-017): email por la cola `mail` con un enlace de un solo uso para
 * que la persona fije su contraseña. El enlace es el de restablecimiento del Password broker (el
 * mismo que emite app:install): caduca según auth.passwords.users.expire y, si caduca, se puede
 * reenviar desde /admin/usuarios o pedir otro con «¿Has olvidado tu contraseña?».
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
        $minutes = (int) config('auth.passwords.'.config('fortify.passwords', 'users').'.expire', 60);
        $url = route('password.reset', ['token' => $this->token, 'email' => $email]);

        $message = (new MailMessage)
            ->subject(__('admin.invitation.subject', ['company' => $this->companyName]))
            ->greeting(__('admin.invitation.greeting', ['name' => $notifiable->name]))
            ->line($this->invitedBy !== null
                ? __('admin.invitation.intro_by', ['company' => $this->companyName, 'inviter' => $this->invitedBy])
                : __('admin.invitation.intro', ['company' => $this->companyName]))
            ->line(__('admin.invitation.instructions'))
            ->action(__('admin.invitation.action'), $url)
            ->line(__('admin.invitation.expires', ['minutes' => $minutes]))
            ->line(__('admin.invitation.ignore'))
            ->salutation(__('admin.invitation.salutation', ['company' => $this->companyName]));

        return $message;
    }
}
