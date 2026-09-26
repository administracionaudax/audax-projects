<?php

namespace App\Enums;

/**
 * Permisos globales (spatie/laravel-permission). Los permisos por proyecto se resuelven con Policies.
 */
enum Permission: string
{
    case ManageUsers = 'manage-users';
    case ManageSettings = 'manage-settings';
    case ViewFinancials = 'view-financials';

    public function label(): string
    {
        return match ($this) {
            self::ManageUsers => 'Gestionar usuarios',
            self::ManageSettings => 'Gestionar ajustes',
            self::ViewFinancials => 'Ver datos económicos',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
