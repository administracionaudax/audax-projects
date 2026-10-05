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
    /** Colaborador externo (Fase 8, D-134): app interna limitada a los proyectos de los que es miembro. */
    case Collaborator = 'collaborator';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administración',
            self::DepartmentManager => 'Responsable de departamento',
            self::Employee => 'Empleado',
            self::Collaborator => 'Colaborador externo',
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
            // La Weekly la gestionan también los responsables de departamento (D-147).
            self::DepartmentManager => [Permission::ManageWeeklies],
            default => [],
        };
    }

    public function isInternal(): bool
    {
        return $this !== self::Client;
    }

    /**
     * Rol interno restringido (D-134): solo ve sus proyectos, sus tareas y sus chats.
     */
    public function isRestricted(): bool
    {
        return $this === self::Collaborator;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
