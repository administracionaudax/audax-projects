<?php

namespace App\Domain\HourBanks;

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\HourBankAlert;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Support\Duration;
use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Motor de consumo y exceso de las bolsas de horas (SPEC §8, D-019, D-035). Es la ÚNICA pieza
 * que escribe consumed_minutes, overage_minutes y el paso active ↔ exhausted.
 *
 * Reglas:
 * - Consumo = suma de minutes de TODAS las entradas de la bolsa, en cualquier estado (SPEC §8.4).
 * - Exceso por entrada (D-019): las entradas bloqueadas conservan su overage_minutes y reservan su
 *   parte dentro de la bolsa; el saldo restante se reparte entre las no bloqueadas en orden
 *   cronológico (date, created_at, id). Una entrada que cruza el límite queda con la parte que no
 *   cabe como exceso. Así la suma de lo que va "dentro" nunca supera el total de la bolsa.
 * - Si un admin edita los minutos, la fecha o la bolsa de una entrada bloqueada (SPEC §7), esa
 *   entrada se recalcula una vez como si no estuviera bloqueada ($reprice): su exceso vuelve a
 *   quedar entre 0 y sus minutos. Las demás bloqueadas no cambian. Como red de seguridad, el
 *   exceso de una bloqueada nunca se toma fuera de ese rango.
 * - Política: `inherit` sigue el ajuste allow_hour_bank_overage. Con `block`, una imputación que no
 *   cabe se rechaza indicando el saldo (sin recortarla); el exceso ya existente se conserva.
 * - Avisos: cada umbral una sola vez por bolsa; el exceso, como máximo una vez al día.
 */
final class HourBankLedger
{
    public function effectivePolicy(HourBank $bank): OveragePolicy
    {
        if ($bank->overage_policy !== OveragePolicy::Inherit) {
            return $bank->overage_policy;
        }

        return (bool) Setting::get('allow_hour_bank_overage', true) ? OveragePolicy::Allow : OveragePolicy::Block;
    }

    /**
     * Minutos que caben aún en la bolsa, sin contar $excluding (la entrada que se está editando).
     */
    public function available(HourBank $bank, ?TimeEntry $excluding = null): int
    {
        $consumed = (int) TimeEntry::query()
            ->where('hour_bank_id', $bank->id)
            ->when($excluding?->exists, fn ($query) => $query->whereKeyNot($excluding?->id))
            ->sum('minutes');

        return max($bank->total_minutes - $consumed, 0);
    }

    /**
     * Minutos de una imputación nueva de $minutes que irían como exceso (para el aviso).
     */
    public function projectedOverage(HourBank $bank, int $minutes, ?TimeEntry $excluding = null): int
    {
        return max($minutes - $this->available($bank, $excluding), 0);
    }

    /**
     * Con política efectiva `block`, rechaza la imputación que no cabe (SPEC §8.6).
     * Llamar con la bolsa bloqueada (lock()) dentro de la transacción de escritura.
     *
     * @throws ValidationException
     */
    public function assertFits(HourBank $bank, int $minutes, ?TimeEntry $excluding = null, string $field = 'minutes'): void
    {
        if ($this->effectivePolicy($bank) !== OveragePolicy::Block) {
            return;
        }

        // Editar una entrada sin aumentar sus minutos nunca se bloquea: el exceso existente se conserva.
        if ($excluding?->exists
            && (int) $excluding->getOriginal('hour_bank_id') === $bank->id
            && $minutes <= (int) $excluding->getOriginal('minutes')) {
            return;
        }

        $available = $this->available($bank, $excluding);

        if ($minutes > $available) {
            throw ValidationException::withMessages([
                $field => __('time.errors.bank_blocked', [
                    'bank' => $bank->name,
                    'available' => Duration::format($available),
                ]),
            ]);
        }
    }

    /**
     * Bloquea la fila de la bolsa hasta el final de la transacción en curso (evita que dos
     * imputaciones simultáneas superen el saldo de una bolsa `block`).
     */
    public function lock(HourBank $bank): HourBank
    {
        /** @var HourBank */
        return HourBank::query()->withTrashed()->whereKey($bank->id)->lockForUpdate()->firstOrFail();
    }

    public function recalculateById(?int $bankId, bool $notify = true, ?int $reprice = null): void
    {
        if ($bankId === null) {
            return;
        }

        $bank = HourBank::query()->withTrashed()->find($bankId);

        if ($bank !== null) {
            $this->recalculate($bank, $notify, $reprice);
        }
    }

    /**
     * Recalcula el exceso de cada entrada, la caché de la bolsa y su estado, y emite los avisos.
     * Actualiza también la instancia recibida. $reprice: una entrada bloqueada que un admin acaba
     * de editar y cuyo exceso se recalcula en esta pasada.
     */
    public function recalculate(HourBank $bank, bool $notify = true, ?int $reprice = null): HourBank
    {
        return DB::transaction(function () use ($bank, $notify, $reprice): HourBank {
            $locked = $this->lock($bank);
            $previousOverage = $locked->overage_minutes;

            $entries = TimeEntry::query()
                ->where('hour_bank_id', $locked->id)
                ->orderBy('date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'minutes', 'overage_minutes', 'status']);

            $keepsOverage = fn (TimeEntry $entry): bool => $entry->status === TimeEntryStatus::Locked && $entry->id !== $reprice;
            // Exceso de una bloqueada, siempre entre 0 y sus minutos.
            $lockedOverage = fn (TimeEntry $entry): int => min(max($entry->overage_minutes, 0), $entry->minutes);

            $reserved = 0;
            foreach ($entries as $entry) {
                if ($keepsOverage($entry)) {
                    $reserved += $entry->minutes - $lockedOverage($entry);
                }
            }

            $capacity = max($locked->total_minutes - $reserved, 0);
            $used = 0;
            $consumed = 0;
            $overage = 0;
            /** @var array<int, list<int>> $changes overage => ids */
            $changes = [];

            foreach ($entries as $entry) {
                $consumed += $entry->minutes;

                if ($keepsOverage($entry)) {
                    $entryOverage = $lockedOverage($entry);
                    $overage += $entryOverage;

                    if ($entryOverage !== $entry->overage_minutes) {
                        $changes[$entryOverage][] = $entry->id;
                    }

                    continue;
                }

                $inBank = min($entry->minutes, max($capacity - $used, 0));
                $entryOverage = $entry->minutes - $inBank;
                $used += $inBank;
                $overage += $entryOverage;

                if ($entryOverage !== $entry->overage_minutes) {
                    $changes[$entryOverage][] = $entry->id;
                }
            }

            foreach ($changes as $entryOverage => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    // Actualización masiva: no dispara eventos del modelo (ni recálculos en bucle).
                    TimeEntry::query()->whereKey($chunk)->update(['overage_minutes' => $entryOverage]);
                }
            }

            $locked->forceFill([
                'consumed_minutes' => $consumed,
                'overage_minutes' => $overage,
            ]);

            if ($locked->status->acceptsTime()) {
                $locked->status = $consumed >= $locked->total_minutes ? HourBankStatus::Exhausted : HourBankStatus::Active;
            }

            $locked->save();

            if ($notify) {
                $this->recordAlerts($locked, $previousOverage);
            }

            $bank->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Umbrales configurados, en %, ordenados y sin repetir (por defecto 75, 90 y 100).
     *
     * @return list<int>
     */
    public function thresholds(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) Setting::get('hour_bank_alert_thresholds', [75, 90, 100]);

        $thresholds = array_values(array_unique(array_filter(
            array_map(fn (mixed $value): int => (int) $value, $configured),
            fn (int $value): bool => $value > 0 && $value <= 1000,
        )));
        sort($thresholds);

        return $thresholds;
    }

    private function recordAlerts(HourBank $bank, int $previousOverage): void
    {
        if (! $bank->status->acceptsTime()) {
            return;
        }

        $pct = $bank->total_minutes > 0 ? $bank->consumed_minutes * 100 / $bank->total_minutes : 100;
        $today = LocalTime::todayString();
        $reached = null;

        foreach ($this->thresholds() as $threshold) {
            if ($pct < $threshold) {
                break;
            }

            $alert = HourBankAlert::query()->createOrFirst(
                ['hour_bank_id' => $bank->id, 'key' => "threshold:{$threshold}"],
                ['kind' => HourBankAlert::KIND_THRESHOLD, 'threshold' => $threshold, 'notified_on' => $today],
            );

            if ($alert->wasRecentlyCreated) {
                $reached = $threshold;
            }
        }

        if ($reached !== null) {
            HourBankThresholdReached::dispatch($bank, $reached);
        }

        if ($bank->overage_minutes > $previousOverage) {
            $alert = HourBankAlert::query()->createOrFirst(
                ['hour_bank_id' => $bank->id, 'key' => "overage:{$today}"],
                ['kind' => HourBankAlert::KIND_OVERAGE, 'notified_on' => $today],
            );

            if ($alert->wasRecentlyCreated) {
                HourBankOverageRecorded::dispatch($bank, $bank->overage_minutes - $previousOverage);
            }
        }
    }
}
