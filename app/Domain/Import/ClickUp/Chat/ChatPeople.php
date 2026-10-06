<?php

namespace App\Domain\Import\ClickUp\Chat;

use App\Domain\Import\ClickUp\ImportReport;
use App\Domain\Import\ClickUp\PeopleFile;
use App\Domain\Import\ClickUp\PersonSpec;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Personas del chat de ClickUp → cuentas de la app (D-275). Cada id de ClickUp se casa por su
 * correo (users.json y los miembros de los canales del volcado) con el fichero de personas de la
 * importación de ClickUp (D-136) y, por él, con su cuenta (activa o de un antiguo empleado,
 * desactivada). Si el correo no está o esa ficha no se importa, se prueba por el nombre. Lo que
 * no casa es «Usuario de ClickUp»: una cuenta desactivada que solo firma esos mensajes.
 *
 * Las cuentas no se crean aquí (las crea app:import-clickup), salvo la de «Usuario de ClickUp».
 */
final class ChatPeople
{
    public const string PLACEHOLDER_EMAIL = 'usuario-clickup@antiguos.audaxstudio.invalid';

    public const string PLACEHOLDER_NAME = 'Usuario de ClickUp';

    /** @var array<string, User|null> id de ClickUp → cuenta (null = «Usuario de ClickUp») */
    private array $resolved = [];

    /** @var array<string, User> correo de la app → cuenta */
    private array $accounts = [];

    /** @var array<string, PersonSpec> correo de ClickUp → ficha */
    private array $byEmail = [];

    /** @var array<string, list<PersonSpec>> nombre normalizado → fichas que se importan */
    private array $byName = [];

    private ?User $placeholder = null;

    /**
     * @param  array<string, array{name?: string|null, email?: string|null}>  $users  id de ClickUp → nombre y correo
     */
    public function __construct(
        PeopleFile $file,
        private readonly array $users,
        private readonly ImportReport $report,
    ) {
        foreach ($file->people as $spec) {
            $this->byEmail[$spec->clickupEmail] = $spec;
            if ($spec->import) {
                $this->byName[self::key($spec->name)][] = $spec;
            }
        }

        $emails = array_map(fn (PersonSpec $spec): string => $spec->email, $file->people);
        foreach (User::query()->whereIn(DB::raw('lower(email)'), $emails)->get() as $user) {
            $this->accounts[Str::lower($user->email)] = $user;
        }
    }

    /**
     * La cuenta de una persona de ClickUp, o null si es «Usuario de ClickUp».
     */
    public function user(string $clickupId): ?User
    {
        if (array_key_exists($clickupId, $this->resolved)) {
            return $this->resolved[$clickupId];
        }

        $known = $this->users[$clickupId] ?? [];
        $email = Str::lower(trim((string) ($known['email'] ?? '')));
        $name = trim((string) ($known['name'] ?? ''));
        $spec = $this->byEmail[$email] ?? null;

        if ($spec === null || ! $spec->import) {
            $candidates = $name === '' ? [] : ($this->byName[self::key($name)] ?? []);
            $spec = count($candidates) === 1 ? $candidates[0] : $spec;
        }

        $user = $spec !== null && $spec->import ? ($this->accounts[$spec->email] ?? null) : null;

        if ($user === null) {
            $this->report->warn($spec !== null && $spec->import
                ? "Persona del fichero sin cuenta en la app (ejecuta antes app:import-clickup): {$spec->name}."
                : 'Personas de ClickUp sin pareja: sus mensajes los firma «'.self::PLACEHOLDER_NAME.'» ('.($name !== '' ? $name : "id {$clickupId}").').');
        }

        return $this->resolved[$clickupId] = $user;
    }

    /**
     * Id local de quien firma (la persona o «Usuario de ClickUp»).
     */
    public function authorId(string $clickupId): int
    {
        return $this->user($clickupId)->id ?? $this->placeholder()->id;
    }

    /**
     * El nombre con el que ClickUp conoce a la persona (para las menciones que no se resuelven).
     */
    public function name(string $clickupId): ?string
    {
        $name = trim((string) ($this->users[$clickupId]['name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * «Usuario de ClickUp»: cuenta desactivada, de la plantilla (empleado) y sin acceso.
     */
    public function placeholder(): User
    {
        if ($this->placeholder !== null) {
            return $this->placeholder;
        }

        $user = User::query()->where('email', self::PLACEHOLDER_EMAIL)->first();

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => self::PLACEHOLDER_NAME,
                'email' => self::PLACEHOLDER_EMAIL,
                'password' => Str::password(40),
                'is_active' => false,
            ])->save();
            $user->syncRoles([Role::Employee->value]);
            $this->report->count('people', ImportReport::CREATED);
        }

        return $this->placeholder = $user;
    }

    private static function key(string $name): string
    {
        $ascii = Str::ascii(Str::lower(trim($name)));

        return (string) preg_replace('/\s+/', ' ', $ascii);
    }
}
