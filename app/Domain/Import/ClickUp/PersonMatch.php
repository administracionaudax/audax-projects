<?php

namespace App\Domain\Import\ClickUp;

use App\Enums\Role;
use App\Models\User;

/**
 * Persona de ClickUp ya resuelta: su ficha del fichero de personas y su cuenta en la app (null si
 * no se importa).
 */
final readonly class PersonMatch
{
    public function __construct(
        public PersonSpec $spec,
        public ?User $user,
    ) {}

    public function isCollaborator(): bool
    {
        return $this->spec->role === Role::Collaborator;
    }
}
