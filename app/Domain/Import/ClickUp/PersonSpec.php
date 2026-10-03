<?php

namespace App\Domain\Import\ClickUp;

use App\Enums\Role;

/**
 * Una persona del fichero de personas (PeopleFile).
 */
final readonly class PersonSpec
{
    public function __construct(
        public string $clickupEmail,
        public string $email,
        public string $name,
        public Role $role,
        public ?string $department,
        public bool $isDepartmentManager,
        public bool $import,
    ) {}

    /**
     * Admin o responsable: puede ser gestor principal de un proyecto importado (D-135).
     */
    public function canOwnProjects(): bool
    {
        return $this->role === Role::Admin || $this->role === Role::DepartmentManager;
    }
}
