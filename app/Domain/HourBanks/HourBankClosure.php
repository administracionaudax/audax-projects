<?php

namespace App\Domain\HourBanks;

use App\Enums\HourBankStatus;
use App\Models\HourBank;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierre manual y reapertura de una bolsa (SPEC §8.9, D-035):
 * - cerrar registra quién, cuándo y el saldo no consumido (closed_remaining_minutes); la bolsa deja
 *   de admitir horas,
 * - reabrir (solo admin, lo decide la política) solo vale para una bolsa cerrada que no se haya
 *   renovado: vuelve a «activa» y HourBankLedger decide si en realidad está agotada.
 */
final class HourBankClosure
{
    public function __construct(private readonly HourBankLedger $ledger) {}

    public function close(HourBank $bank, User $closedBy): HourBank
    {
        return DB::transaction(function () use ($bank, $closedBy): HourBank {
            $locked = $this->ledger->lock($bank);

            if (! $locked->status->acceptsTime()) {
                throw ValidationException::withMessages([
                    'hour_bank' => __('hour_banks.errors.not_open', [
                        'bank' => $locked->name,
                        'status' => mb_strtolower($locked->status->label()),
                    ]),
                ]);
            }

            $locked->fill([
                'status' => HourBankStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $closedBy->id,
                'closed_remaining_minutes' => $locked->remaining_minutes,
            ])->save();

            $bank->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    public function reopen(HourBank $bank): HourBank
    {
        return DB::transaction(function () use ($bank): HourBank {
            $locked = $this->ledger->lock($bank);

            if ($locked->status !== HourBankStatus::Closed) {
                throw ValidationException::withMessages([
                    'hour_bank' => __('hour_banks.errors.not_closed'),
                ]);
            }

            $locked->fill([
                'status' => HourBankStatus::Active,
                'closed_at' => null,
                'closed_by' => null,
                'closed_remaining_minutes' => null,
            ])->save();

            // Activa o agotada según su consumo (y avisos pendientes, si los hubiera).
            $this->ledger->recalculate($locked);

            $bank->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
