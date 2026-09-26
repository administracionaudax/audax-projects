<?php

namespace App\Enums;

/**
 * Roles globales (SPEC §5). "Gestor de proyecto" no es un rol: es una relación por proyecto (D-005).
 */
enum Role: string
{
    case Admin = 'admin';
    case DepartmentManager = 'department_manager';
    case Employee = 'employee';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administración',
            self::DepartmentManager => 'Responsable de departamento',
            self::Employee => 'Empleado',
            self::Client => 'Cliente',
        };
    }

    /**
     * Permisos que cada rol recibe por defecto al instalar.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            default => [],
        };
    }

    public function isInternal(): bool
    {
        return $this !== self::Client;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
