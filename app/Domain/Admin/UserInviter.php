<?php

namespace App\Domain\Admin;

use App\Enums\Role;
use App\Http\Controllers\Auth\InvitationController;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Admin\UserInvitation;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Alta por invitación (SPEC §14, D-010, D-017, D-036):
 * - crea el usuario con una contraseña aleatoria que nadie conoce ni se muestra,
 * - le da su horario por defecto (default_work_minutes desde hoy),
 * - le envía por la cola `mail` el enlace de un solo uso para fijar su contraseña.
 */
final class UserInviter
{
    public function __construct(private readonly WorkScheduleVersions $schedules) {}

    /**
     * @param  array{name: string, email: string, department_id: int|null, job_title?: string|null, hourly_cost?: string|null, default_hourly_rate?: string|null}  $data
     */
    public function invite(User $actor, array $data, Role $role): User
    {
        $user = DB::transaction(function () use ($data, $role): User {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => Str::lower(trim($data['email'])),
                'password' => Str::password(40),
                'department_id' => $data['department_id'],
                'job_title' => $data['job_title'] ?? null,
                'hourly_cost' => $data['hourly_cost'] ?? null,
                'default_hourly_rate' => $data['default_hourly_rate'] ?? null,
                'is_active' => true,
            ])->save();

            $user->syncRoles([$role->value]);
            $this->schedules->createDefault($user);

            return $user;
        });

        $this->send($actor, $user);

        return $user;
    }

    /**
     * Envía (o reenvía) la invitación: un enlace nuevo invalida los anteriores.
     */
    public function send(User $actor, User $user): void
    {
        // Broker propio de invitaciones: el enlace dura 7 días (config/auth.php) y se acepta en
        // /invitacion/{token} (App\Http\Controllers\Auth\InvitationController).
        /** @var PasswordBroker $broker */
        $broker = Password::broker(InvitationController::BROKER);
        $token = $broker->createToken($user);

        $user->notify(new UserInvitation(
            token: $token,
            companyName: (string) Setting::get('company_name', Setting::DEFAULTS['company_name']),
            invitedBy: $actor->id !== $user->id ? $actor->name : null,
        ));
    }
}
