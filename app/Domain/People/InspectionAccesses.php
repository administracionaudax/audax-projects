<?php

namespace App\Domain\People;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\InspectionAccess;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El acceso temporal de solo lectura de la Inspección de Trabajo (PLAN-FASE-11 §6.4 y §11.3;
 * WOFFU-INVESTIGACION G.5: el borrador del RD pide acceso «inmediato, remoto y presencial» y el
 * Consejo de Estado, con la AEPD, medidas concretas; D-353):
 *
 * - **Apagado por defecto** (`people_inspection_enabled`). Lo enciende y crea los accesos solo
 *   RR. HH. o un admin (`manage-people`). Apagado, ningún acceso funciona.
 * - Cada acceso tiene un **ámbito** (personas y fechas), un **plazo** (como mucho 30 días) y se
 *   puede **revocar**. No es una cuenta de usuario: nunca entra en el resto de la app.
 * - **Dos factores separados**: un enlace secreto y un código de 8 cifras. La app enseña los dos una
 *   sola vez a quien lo crea (solo guarda sus huellas) para que los entregue por vías distintas
 *   (por ejemplo, el enlace por correo y el código en mano o por teléfono). A los 5 códigos mal
 *   puestos el acceso se bloquea.
 * - Solo lectura: ve el registro de su ámbito y descarga su exportación. **Cada consulta queda en
 *   la auditoría** (log `inspection`).
 */
final class InspectionAccesses
{
    public const string SESSION_KEY = 'inspection_access_id';

    public const string LOG = 'inspection';

    public static function enabled(): bool
    {
        return (bool) Setting::get('people_inspection_enabled', false) && AppModules::enabled(AppModule::People);
    }

    /**
     * Enciende o apaga el acceso de la Inspección.
     *
     * @throws AuthorizationException
     */
    public function toggle(User $actor, bool $enabled): void
    {
        if (! PeopleAccess::managesAll($actor)) {
            throw new AuthorizationException;
        }

        if ((bool) Setting::get('people_inspection_enabled', false) === $enabled) {
            return;
        }

        Setting::set('people_inspection_enabled', $enabled);

        activity(self::LOG)
            ->causedBy($actor)
            ->event($enabled ? 'enabled' : 'disabled')
            ->log($enabled ? 'inspection.enabled' : 'inspection.disabled');
    }

    /**
     * Crea un acceso y devuelve el enlace y el código en claro (solo esta vez).
     *
     * @param  array{name: string, email: string, reference?: string|null, user_ids?: list<int>|null, scope_from: string, scope_to: string, valid_from?: string|null, days: int}  $data
     * @return array{access: InspectionAccess, token: string, code: string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function create(User $actor, array $data): array
    {
        if (! PeopleAccess::managesAll($actor)) {
            throw new AuthorizationException;
        }

        if (! self::enabled()) {
            throw ValidationException::withMessages(['enabled' => __('people.errors.inspection_disabled')]);
        }

        $token = Str::random(48);
        $code = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
        $from = isset($data['valid_from'])
            ? CarbonImmutable::parse($data['valid_from'], LocalTime::timezone())->startOfDay()
            : CarbonImmutable::now();
        $days = max(1, min(InspectionAccess::MAX_DAYS, $data['days']));
        $userIds = $data['user_ids'] ?? null;

        $access = new InspectionAccess;
        $access->forceFill([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'reference' => isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null,
            'token_hash' => self::tokenHash($token),
            'code_hash' => Hash::make($code),
            'scope_user_ids' => $userIds === null || $userIds === [] ? null : array_map(intval(...), $userIds),
            'scope_from' => $data['scope_from'],
            'scope_to' => $data['scope_to'],
            'valid_from' => $from->utc(),
            'valid_until' => $from->addDays($days)->utc(),
            'created_by' => $actor->id,
        ])->save();

        activity(self::LOG)
            ->causedBy($actor)
            ->performedOn($access)
            ->event('created')
            ->withProperties([
                'name' => $access->name,
                'email' => $access->email,
                'reference' => $access->reference,
                'scope' => ['users' => $access->scope_user_ids, 'from' => $data['scope_from'], 'to' => $data['scope_to']],
                'valid_until' => $access->valid_until->toIso8601String(),
            ])
            ->log('inspection.created');

        return ['access' => $access, 'token' => $token, 'code' => $code];
    }

    /**
     * @throws AuthorizationException
     */
    public function revoke(User $actor, InspectionAccess $access): InspectionAccess
    {
        if (! PeopleAccess::managesAll($actor)) {
            throw new AuthorizationException;
        }

        if ($access->revoked_at === null) {
            $access->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $actor->id])->save();

            activity(self::LOG)->causedBy($actor)->performedOn($access)->event('revoked')->log('inspection.revoked');
        }

        return $access;
    }

    /** El acceso de un enlace, si existe (usable o no). */
    public function byToken(string $token): ?InspectionAccess
    {
        if (strlen($token) !== 48) {
            return null;
        }

        return InspectionAccess::query()->where('token_hash', self::tokenHash($token))->first();
    }

    /**
     * Comprueba el código y abre la sesión de solo lectura.
     *
     * @throws ValidationException
     */
    public function attempt(Request $request, InspectionAccess $access, string $code): void
    {
        if (! self::enabled() || ! $access->usable()) {
            throw ValidationException::withMessages(['code' => __('people.errors.inspection_unavailable')]);
        }

        if (! Hash::check(trim($code), $access->code_hash)) {
            $access->forceFill(['failed_attempts' => $access->failed_attempts + 1])->save();

            activity(self::LOG)->performedOn($access)->event('failed')->withProperties(['ip_hash' => RegisterHasher::ipHash($request->ip())])->log('inspection.failed');

            throw ValidationException::withMessages(['code' => __('people.errors.inspection_code')]);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $access->id);
        $access->forceFill(['failed_attempts' => 0, 'last_used_at' => CarbonImmutable::now()])->save();

        activity(self::LOG)->performedOn($access)->event('login')->withProperties(['ip_hash' => RegisterHasher::ipHash($request->ip())])->log('inspection.login');
    }

    /** El acceso de la sesión, si sigue valiendo. */
    public function current(Request $request): ?InspectionAccess
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if (! is_int($id) || ! self::enabled()) {
            return null;
        }

        $access = InspectionAccess::query()->find($id);

        return $access !== null && $access->usable() ? $access : null;
    }

    public function logout(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerate();
    }

    /**
     * Las personas de su ámbito (la plantilla sujeta al registro, o las elegidas).
     *
     * @return list<User>
     */
    public function people(InspectionAccess $access): array
    {
        return array_values(PeopleAccess::registerSubjects()
            ->when($access->scope_user_ids !== null, fn ($query) => $query->whereIn('id', $access->scope_user_ids ?? []))
            ->get()
            ->all());
    }

    /**
     * Las fechas de su ámbito, recortadas a hoy.
     *
     * @return array{0: string, 1: string}
     */
    public function period(InspectionAccess $access): array
    {
        $today = LocalTime::todayString();

        return [$access->scope_from->toDateString(), min($access->scope_to->toDateString(), $today)];
    }

    /**
     * Cada consulta de la Inspección queda en la auditoría.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(InspectionAccess $access, Request $request, string $what, array $properties = []): void
    {
        activity(self::LOG)
            ->performedOn($access)
            ->event('viewed')
            ->withProperties(['what' => $what, ...$properties, 'ip_hash' => RegisterHasher::ipHash($request->ip())])
            ->log('inspection.viewed');
    }

    private static function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
