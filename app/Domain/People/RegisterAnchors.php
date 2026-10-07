<?php

namespace App\Domain\People;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\ClockEvent;
use App\Models\RegisterAnchor;
use App\Models\RegisterCheckpoint;
use App\Models\User;
use App\Notifications\People\RegisterIntegrityBroken;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * La comprobación nocturna y el ancla diaria del registro (PLAN-FASE-11 §11.1.3 y §11.1.4; D-352):
 *
 * - Cada noche (`people:verify-register --nightly`, a las 02:50 de Madrid, antes de la copia de
 *   seguridad) se comprueba la cadena entera (RegisterIntegrity) y se guarda el **ancla** del día:
 *   la última fila de la cadena de cada persona y un resumen encadenado con el del día anterior.
 * - El ancla se escribe también en un fichero (`storage/app/private/people/anclas/AAAA-MM-DD.json`)
 *   que se lleva la copia nocturna, sale en la pantalla de la Inspección y va en cada exportación
 *   para la ITSS: ni quien tenga acceso a la base de datos puede reescribir el pasado y recalcular
 *   todas las huellas sin que el ancla de un día anterior deje de coincidir.
 * - Si la comprobación falla, aviso a los admins (obligatorio, app y email).
 */
final class RegisterAnchors
{
    public function __construct(
        private readonly RegisterIntegrity $integrity,
        private readonly RegisterHasher $hasher,
    ) {}

    /**
     * Comprueba la cadena y guarda el ancla del día (una por día: si ya está, la devuelve).
     *
     * @return array{anchor: RegisterAnchor, result: array{ok: bool, events: int, corrections: int, problems: list<string>}}
     */
    public function nightly(?CarbonImmutable $now = null, bool $notify = true): array
    {
        $now ??= CarbonImmutable::now();
        $result = $this->integrity->verify();
        $anchor = $this->anchor(LocalTime::dateOf($now), $result);

        if (! $result['ok'] && $notify && AppModules::enabled(AppModule::People)) {
            PeopleNotifier::send(User::query()->active()->role('admin')->get(), new RegisterIntegrityBroken($result['problems']));
        }

        activity('people-register')
            ->event('verified')
            ->withProperties(['ok' => $result['ok'], 'events' => $result['events'], 'problems' => array_slice($result['problems'], 0, 20), 'anchor' => $anchor->digest])
            ->log('register.verified');

        return ['anchor' => $anchor, 'result' => $result];
    }

    /**
     * @param  array{ok: bool, events: int, corrections: int, problems: list<string>}  $result
     */
    public function anchor(string $date, array $result): RegisterAnchor
    {
        return DB::transaction(function () use ($date, $result): RegisterAnchor {
            $existing = RegisterAnchor::query()->where('date', $date)->first();

            if ($existing !== null) {
                return $existing;
            }

            $heads = $this->heads();
            $previous = RegisterAnchor::query()->where('date', '<', $date)->orderByDesc('date')->value('digest') ?? RegisterHasher::GENESIS;
            $events = ClockEvent::query()->count();

            $anchor = new RegisterAnchor;
            $anchor->forceFill([
                'date' => $date,
                'heads' => $heads,
                'events_count' => $events,
                'prev_digest' => $previous,
                'digest' => $this->hasher->anchorDigest($date, (string) $previous, $events, $heads),
                'verified_ok' => $result['ok'],
                'problems' => $result['problems'] === [] ? null : array_slice($result['problems'], 0, 50),
                'created_at' => CarbonImmutable::now(),
            ])->save();

            Storage::disk('local')->put("people/anclas/{$date}.json", (string) json_encode([
                'date' => $date,
                'digest' => $anchor->digest,
                'prev_digest' => $anchor->prev_digest,
                'events_count' => $events,
                'heads' => $heads,
                'verified_ok' => $result['ok'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $anchor;
        });
    }

    /**
     * La última fila de la cadena de cada persona (o su punto de control, si se suprimió todo).
     *
     * @return array<int, array{seq: int, hash: string}>
     */
    public function heads(): array
    {
        $heads = [];

        foreach (RegisterCheckpoint::query()->get() as $checkpoint) {
            $heads[$checkpoint->user_id] = ['seq' => $checkpoint->seq, 'hash' => $checkpoint->hash];
        }

        $last = ClockEvent::query()
            ->select('user_id', DB::raw('max(seq) as seq'))
            ->groupBy('user_id')
            ->pluck('seq', 'user_id');

        foreach ($last as $userId => $seq) {
            $hash = ClockEvent::query()->where('user_id', $userId)->where('seq', $seq)->value('hash');
            $heads[(int) $userId] = ['seq' => (int) $seq, 'hash' => (string) $hash];
        }

        ksort($heads);

        return $heads;
    }

    /** El ancla más reciente. */
    public static function latest(): ?RegisterAnchor
    {
        return RegisterAnchor::query()->orderByDesc('date')->first();
    }
}
