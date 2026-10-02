<?php

namespace App\Domain\Portal\Access;

use App\Domain\Admin\UserInviter;
use App\Enums\Role;
use App\Http\Controllers\Auth\InvitationController;
use App\Models\Client;
use App\Models\LoginEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios del portal de un cliente (SPEC §11, D-063): rol `client` y `client_id`.
 * - Alta por invitación: el usuario se crea sin contraseña conocida y recibe por la cola `mail` el
 *   enlace de un solo uso de 7 días del broker «invitations» (UserInviter::send, D-017, D-038). Sin
 *   departamento ni jornada: no es parte de la plantilla.
 * - Reenviar: un enlace nuevo invalida el anterior.
 * - Revocar: se desactiva (nunca se borra). User::booted cierra al momento todas sus sesiones y su
 *   «Recordarme» con SessionTerminator::destroyAll, y se anula la invitación pendiente.
 * - Reactivar: vuelve a entrar con su contraseña (si no la llegó a fijar, se le reenvía la invitación).
 * Cada paso queda en la auditoría del cliente (activity_log `clients`).
 */
final class PortalUsers
{
    public function __construct(private readonly UserInviter $inviter) {}

    /**
     * Usuarios del portal del cliente con su estado: activo, invitación (pendiente, caducada o
     * aceptada) y último acceso. Tres consultas en total.
     *
     * @return list<array{id: int, name: string, email: string, is_active: bool, invitation: string, invitation_expires_at: string|null, last_login_at: string|null}>
     */
    public function list(Client $client): array
    {
        $users = User::query()
            ->select(['users.id', 'users.name', 'users.email', 'users.is_active', 'users.email_verified_at'])
            ->addSelect(['last_login_at' => LoginEvent::query()
                ->select('created_at')
                ->whereColumn('login_events.user_id', 'users.id')
                ->where('succeeded', true)
                ->latest('created_at')
                ->limit(1),
            ])
            ->where('client_id', $client->id)
            ->role(Role::Client->value)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $tokens = $users->isEmpty() ? [] : DB::table($this->tokenTable())
            ->whereIn('email', $users->pluck('email')->all())
            ->pluck('created_at', 'email')
            ->all();

        $rows = [];
        foreach ($users as $user) {
            $lastLogin = $user->getAttribute('last_login_at');
            $lastLogin = $lastLogin === null ? null : CarbonImmutable::parse((string) $lastLogin, 'UTC');
            $sentAt = $tokens[$user->email] ?? null;
            $expiresAt = is_string($sentAt) ? CarbonImmutable::parse($sentAt, 'UTC')->addMinutes($this->expireMinutes()) : null;

            $rows[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'invitation' => $this->invitationState($user, $lastLogin, $expiresAt),
                'invitation_expires_at' => $expiresAt?->toIso8601ZuluString(),
                'last_login_at' => $lastLogin?->toIso8601ZuluString(),
            ];
        }

        return $rows;
    }

    /**
     * @throws ValidationException
     */
    public function invite(User $actor, Client $client, string $name, string $email): User
    {
        $this->assertClientActive($client);
        $email = Str::lower(trim($email));

        $user = DB::transaction(function () use ($actor, $client, $name, $email): User {
            $existing = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();

            if ($existing !== null) {
                throw ValidationException::withMessages(['email' => match (true) {
                    $existing->isInternal() => __('portal.access.errors.email_internal'),
                    $existing->client_id === $client->id => __('portal.access.errors.email_same_client'),
                    default => __('portal.access.errors.email_taken'),
                }]);
            }

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(40),
                'client_id' => $client->id,
                'department_id' => null,
                'is_active' => true,
            ])->save();
            $user->syncRoles([Role::Client->value]);

            $this->log($client, $actor, 'portal_user_invited', $user);

            return $user;
        });

        $this->inviter->send($actor, $user);

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public function resend(User $actor, Client $client, User $user): void
    {
        $this->assertClientActive($client);

        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => __('portal.access.errors.user_inactive')]);
        }

        $this->inviter->send($actor, $user);
        $this->log($client, $actor, 'portal_invitation_resent', $user);
    }

    /**
     * @throws ValidationException
     */
    public function revoke(User $actor, Client $client, User $user): void
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => __('portal.access.errors.already_revoked')]);
        }

        DB::transaction(function () use ($actor, $client, $user): void {
            // User::booted: al pasar a inactivo, SessionTerminator::destroyAll cierra sus sesiones.
            $user->is_active = false;
            $user->save();

            $this->broker()->deleteToken($user);
            $this->log($client, $actor, 'portal_user_revoked', $user);
        });
    }

    /**
     * @throws ValidationException
     */
    public function reactivate(User $actor, Client $client, User $user): void
    {
        $this->assertClientActive($client);

        if ($user->is_active) {
            throw ValidationException::withMessages(['user' => __('portal.access.errors.already_active')]);
        }

        DB::transaction(function () use ($actor, $client, $user): void {
            $user->is_active = true;
            $user->save();

            $this->log($client, $actor, 'portal_user_reactivated', $user);
        });
    }

    /**
     * Aceptada si ya fijó su contraseña con la invitación (email verificado) o si ha entrado alguna
     * vez; si no, pendiente mientras el enlace esté vigente y caducada después (o sin enlace).
     */
    private function invitationState(User $user, ?CarbonImmutable $lastLogin, ?CarbonImmutable $expiresAt): string
    {
        if ($user->email_verified_at !== null || $lastLogin !== null) {
            return 'accepted';
        }

        return $expiresAt !== null && $expiresAt->isFuture() ? 'pending' : 'expired';
    }

    /**
     * @throws ValidationException
     */
    private function assertClientActive(Client $client): void
    {
        if (! $client->is_active) {
            throw ValidationException::withMessages(['client' => __('portal.access.errors.client_inactive')]);
        }
    }

    private function log(Client $client, User $actor, string $event, User $user): void
    {
        $description = __("portal.access.activity.{$event}");

        activity($client->getTable())
            ->performedOn($client)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(['user_id' => $user->id, 'user_name' => $user->name, 'email' => $user->email])
            ->log(is_string($description) ? $description : $event);
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker(InvitationController::BROKER);

        return $broker;
    }

    private function tokenTable(): string
    {
        return (string) config('auth.passwords.'.InvitationController::BROKER.'.table', 'invitation_tokens');
    }

    private function expireMinutes(): int
    {
        return (int) config('auth.passwords.'.InvitationController::BROKER.'.expire', 7 * 24 * 60);
    }
}
