<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Enums\Role;
use App\Models\User;

/**
 * Perfil: los datos de la cuenta. Sin contraseña, sin los secretos ni los códigos del doble
 * factor, sin el token de «recordarme» y sin el coste ni la tarifa por hora (datos económicos de la
 * empresa, SPEC §5).
 */
final class ProfileSection extends Section
{
    public function key(): string
    {
        return 'perfil';
    }

    protected function textKey(): string
    {
        return 'profile';
    }

    protected function columnKeys(): array
    {
        return [
            'id', 'name', 'email', 'department', 'roles', 'is_active', 'two_factor_enabled',
            'theme_preference', 'locale', 'notification_preferences', 'privacy_acknowledged_version',
            'privacy_acknowledged_at', 'email_verified_at', 'created_at',
        ];
    }

    public function rows(User $user): iterable
    {
        $user->loadMissing(['department', 'roles']);
        $preferences = $user->notification_preferences;

        yield [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department' => $user->department?->name,
            'roles' => implode(', ', array_map(
                fn (string $role): string => Role::tryFrom($role)?->label() ?? $role,
                $user->getRoleNames()->values()->all(),
            )),
            'is_active' => $user->is_active,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'theme_preference' => $user->theme_preference,
            'locale' => $user->locale,
            'notification_preferences' => $preferences === null || $preferences === [] ? null : (string) json_encode($preferences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'privacy_acknowledged_version' => $user->privacy_acknowledged_version,
            'privacy_acknowledged_at' => self::instant($user->privacy_acknowledged_at),
            'email_verified_at' => self::instant($user->email_verified_at),
            'created_at' => self::instant($user->created_at),
        ];
    }
}
