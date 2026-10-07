<?php

namespace App\Domain\People\Reports;

use App\Models\User;

/**
 * A quién y a qué periodo se refiere un fichero del registro (R2, D-351): las personas, los días
 * (ambos incluidos), cómo se describe el ámbito en la portada y si se oculta el tipo de las
 * ausencias (la Inspección: minimización).
 */
final readonly class RegisterScope
{
    /**
     * @param  list<User>  $users
     */
    public function __construct(
        public array $users,
        public string $from,
        public string $to,
        public string $label,
        public bool $hideAbsenceType = false,
    ) {}

    /**
     * Parámetros para la auditoría y `people_exports`.
     *
     * @return array{users: list<int>, from: string, to: string}
     */
    public function params(): array
    {
        return [
            'users' => array_map(fn (User $user): int => $user->id, $this->users),
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
