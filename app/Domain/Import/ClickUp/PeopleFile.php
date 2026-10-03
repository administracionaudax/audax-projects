<?php

namespace App\Domain\Import\ClickUp;

use App\Enums\Role;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/**
 * Fichero de personas de la importación (D-136), fuera de Git. Formato:
 *
 * {
 *   "default_manager": "correo-en-la-app@…",      // gestor principal si nadie más encaja (D-135)
 *   "people": [
 *     {
 *       "clickup_email": "correo-en-clickup@…",   // con el que aparece en ClickUp
 *       "email": "correo-en-la-app@…",            // el de su cuenta en la app (puede ser el mismo)
 *       "name": "Nombre Apellido",
 *       "role": "admin" | "department_manager" | "employee" | "collaborator",
 *       "department": "Diseño" | null,            // se crea si no existe
 *       "is_department_manager": false,           // responsable de su departamento (pivote)
 *       "import": true                            // false: ni cuenta, ni tareas, ni horas
 *     }
 *   ]
 * }
 */
final readonly class PeopleFile
{
    /**
     * @param  list<PersonSpec>  $people
     */
    public function __construct(
        public array $people,
        public ?string $defaultManagerEmail,
    ) {}

    /**
     * @throws RuntimeException si el fichero no existe o no es JSON
     * @throws ValidationException si los datos no cumplen el formato
     */
    public static function load(string $path): self
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("No se puede leer el fichero de personas: {$path}.");
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("El fichero de personas no es JSON válido: {$e->getMessage()}");
        }

        return self::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $roles = [Role::Admin->value, Role::DepartmentManager->value, Role::Employee->value, Role::Collaborator->value];

        /** @var array{default_manager?: string|null, people: list<array{clickup_email: string, email: string, name: string, role: string, department?: string|null, is_department_manager?: bool, import?: bool}>} $valid */
        $valid = Validator::make($data, [
            'default_manager' => ['nullable', 'string', 'email'],
            'people' => ['required', 'array', 'min:1'],
            'people.*.clickup_email' => ['required', 'string', 'email', 'distinct:ignore_case'],
            'people.*.email' => ['required', 'string', 'email', 'distinct:ignore_case'],
            'people.*.name' => ['required', 'string', 'max:255'],
            'people.*.role' => ['required', 'string', Rule::in($roles)],
            'people.*.department' => ['nullable', 'string', 'max:255'],
            'people.*.is_department_manager' => ['sometimes', 'boolean'],
            'people.*.import' => ['sometimes', 'boolean'],
        ])->validate();

        $people = [];
        foreach ($valid['people'] as $person) {
            $department = isset($person['department']) ? trim($person['department']) : '';

            $people[] = new PersonSpec(
                clickupEmail: Str::lower(trim($person['clickup_email'])),
                email: Str::lower(trim($person['email'])),
                name: trim($person['name']),
                role: Role::from($person['role']),
                department: $department !== '' ? $department : null,
                isDepartmentManager: (bool) ($person['is_department_manager'] ?? false),
                import: (bool) ($person['import'] ?? true),
            );
        }

        $manager = $valid['default_manager'] ?? null;

        return new self($people, $manager !== null ? Str::lower(trim($manager)) : null);
    }
}
