<?php

namespace App\Domain\Import\WeeklySync;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/**
 * Ficheros de correspondencias de la importación (D-149 y D-214), opcionales y fuera de Git porque
 * llevan correos y nombres reales. Solo hacen falta para lo que no casa solo.
 *
 * Personas (`--personas`, por defecto {volcado}/personas.json):
 *
 *   {"people": [
 *     {"weeklysync_email": "ana.personal@gmail.com", "email": "ana@audaxstudio.com"},
 *     {"weeklysync_id": "uuid-de-weeklysync", "email": "luis@audaxstudio.com"},
 *     {"weeklysync_email": "pruebas@audaxstudio.com", "import": false},
 *     {"weeklysync_email": "antigua@gmail.com", "create": true}
 *   ]}
 *
 *   - email: la cuenta de Audax con ese correo (tiene que existir),
 *   - import: false: se queda fuera, con todo lo suyo (envíos, borradores, votos…),
 *   - create: true: se crea inactiva aunque no tenga nada escrito.
 *
 * Clientes (`--clientes`, por defecto {volcado}/clientes.json):
 *
 *   {"clients": [
 *     {"weeklysync_name": "Manzanas", "client": "Manzanas Pérez S.L."},
 *     {"weeklysync_id": "uuid-de-weeklysync", "client_id": 12},
 *     {"weeklysync_name": "Prospecto X", "create": true}
 *   ]}
 *
 *   - client: el cliente de Audax con ese nombre (sin distinguir mayúsculas), o client_id,
 *   - create: true: se crea inactivo aunque se parezca a otro.
 */
final readonly class WeeklySyncMappings
{
    /**
     * @param  array<string, array{email: string|null, import: bool, create: bool}>  $people  «id:uuid» o «email:correo» => regla
     * @param  array<string, array{client_id: int|null, client: string|null, create: bool}>  $clients  «id:uuid» o «name:normalizado» => regla
     */
    public function __construct(
        public array $people = [],
        public array $clients = [],
    ) {}

    /**
     * @throws RuntimeException si un fichero indicado no existe o no es JSON
     * @throws ValidationException si no cumple el formato
     */
    public static function load(?string $peoplePath, ?string $clientsPath): self
    {
        return new self(
            $peoplePath !== null ? self::people(self::read($peoplePath, 'personas')) : [],
            $clientsPath !== null ? self::clients(self::read($clientsPath, 'clientes')) : [],
        );
    }

    /**
     * Regla de una persona de WeeklySync: por su id o por cualquiera de sus correos.
     *
     * @param  list<string>  $emails
     * @return array{email: string|null, import: bool, create: bool}|null
     */
    public function person(string $id, array $emails): ?array
    {
        if (isset($this->people['id:'.$id])) {
            return $this->people['id:'.$id];
        }

        foreach ($emails as $email) {
            if (isset($this->people['email:'.$email])) {
                return $this->people['email:'.$email];
            }
        }

        return null;
    }

    /**
     * @return array{client_id: int|null, client: string|null, create: bool}|null
     */
    public function client(string $id, string $name): ?array
    {
        return $this->clients['id:'.$id] ?? $this->clients['name:'.WeeklySyncNames::client($name)] ?? null;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, array{email: string|null, import: bool, create: bool}>
     */
    public static function people(array $data): array
    {
        /** @var array{people: list<array{weeklysync_email?: string|null, weeklysync_id?: string|null, email?: string|null, import?: bool, create?: bool}>} $valid */
        $valid = Validator::make($data, [
            'people' => ['required', 'array'],
            'people.*.weeklysync_email' => ['nullable', 'string', 'email', 'required_without:people.*.weeklysync_id'],
            'people.*.weeklysync_id' => ['nullable', 'string', 'uuid'],
            'people.*.email' => ['nullable', 'string', 'email'],
            'people.*.import' => ['sometimes', 'boolean'],
            'people.*.create' => ['sometimes', 'boolean'],
        ])->after(function ($validator) use ($data): void {
            foreach ((array) ($data['people'] ?? []) as $index => $person) {
                $person = (array) $person;
                $targets = (int) ! empty($person['email']) + (int) (($person['import'] ?? true) === false) + (int) (($person['create'] ?? false) === true);
                if ($targets !== 1) {
                    $validator->errors()->add("people.{$index}", 'Cada persona lleva una sola de estas: «email», «import»: false o «create»: true.');
                }
            }
        })->validate();

        $rules = [];
        foreach ($valid['people'] as $person) {
            $rule = [
                'email' => ! empty($person['email']) ? WeeklySyncNames::email($person['email']) : null,
                'import' => ($person['import'] ?? true) !== false,
                'create' => ($person['create'] ?? false) === true,
            ];

            if (! empty($person['weeklysync_id'])) {
                $rules['id:'.strtolower($person['weeklysync_id'])] = $rule;
            }
            if (! empty($person['weeklysync_email'])) {
                $rules['email:'.WeeklySyncNames::email($person['weeklysync_email'])] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, array{client_id: int|null, client: string|null, create: bool}>
     */
    public static function clients(array $data): array
    {
        /** @var array{clients: list<array{weeklysync_name?: string|null, weeklysync_id?: string|null, client?: string|null, client_id?: int|null, create?: bool}>} $valid */
        $valid = Validator::make($data, [
            'clients' => ['required', 'array'],
            'clients.*.weeklysync_name' => ['nullable', 'string', 'max:255', 'required_without:clients.*.weeklysync_id'],
            'clients.*.weeklysync_id' => ['nullable', 'string', 'uuid'],
            'clients.*.client' => ['nullable', 'string', 'max:255'],
            'clients.*.client_id' => ['nullable', 'integer', 'min:1'],
            'clients.*.create' => ['sometimes', 'boolean'],
        ])->after(function ($validator) use ($data): void {
            foreach ((array) ($data['clients'] ?? []) as $index => $client) {
                $client = (array) $client;
                $targets = (int) ! empty($client['client']) + (int) ! empty($client['client_id']) + (int) (($client['create'] ?? false) === true);
                if ($targets !== 1) {
                    $validator->errors()->add("clients.{$index}", 'Cada cliente lleva una sola de estas: «client», «client_id» o «create»: true.');
                }
            }
        })->validate();

        $rules = [];
        foreach ($valid['clients'] as $client) {
            $rule = [
                'client_id' => ! empty($client['client_id']) ? (int) $client['client_id'] : null,
                'client' => ! empty($client['client']) ? trim($client['client']) : null,
                'create' => ($client['create'] ?? false) === true,
            ];

            if (! empty($client['weeklysync_id'])) {
                $rules['id:'.strtolower($client['weeklysync_id'])] = $rule;
            }
            if (! empty($client['weeklysync_name'])) {
                $rules['name:'.WeeklySyncNames::client($client['weeklysync_name'])] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @return array<mixed>
     */
    private static function read(string $path, string $label): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("No se puede leer el fichero de {$label}: {$path}.");
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("El fichero de {$label} no es JSON válido: {$e->getMessage()}");
        }

        return is_array($data) ? $data : [];
    }
}
