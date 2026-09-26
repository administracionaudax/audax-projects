<?php

namespace App\Domain\HourBanks;

use App\Enums\HourBankStatus;
use App\Models\HourBank;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Borrado de una bolsa (solo admin, lo decide HourBankPolicy::delete), sin romper el histórico de
 * renovaciones (SPEC §8.8):
 * - solo una bolsa sin horas ni tareas,
 * - una bolsa que ya se ha renovado no se borra: su renovación quedaría sin la anterior,
 * - borrar la bolsa nueva de una renovación deshace la renovación: la anterior deja de estar
 *   «renovada» y HourBankLedger decide si queda activa o agotada (como al reabrir).
 * Todo en una transacción, con las bolsas bloqueadas.
 */
final class HourBankDeletion
{
    public function __construct(private readonly HourBankLedger $ledger) {}

    /**
     * @return HourBank|null la bolsa anterior, si ha vuelto a abrirse
     *
     * @throws ValidationException
     */
    public function delete(HourBank $bank): ?HourBank
    {
        return DB::transaction(function () use ($bank): ?HourBank {
            $locked = $this->ledger->lock($bank);

            $error = match (true) {
                $locked->timeEntries()->exists() => 'hour_banks.errors.has_time',
                $locked->tasks()->exists() => 'hour_banks.errors.has_tasks',
                $locked->renewal()->exists() => 'hour_banks.errors.has_renewal',
                default => null,
            };

            if ($error !== null) {
                throw ValidationException::withMessages(['hour_bank' => __($error)]);
            }

            $previous = $locked->renewed_from_id !== null
                ? HourBank::query()->whereKey($locked->renewed_from_id)->lockForUpdate()->first()
                : null;

            $locked->delete();

            if ($previous === null || $previous->status !== HourBankStatus::Renewed) {
                return null;
            }

            $previous->status = HourBankStatus::Active;
            $previous->save();

            // Activa o agotada según su consumo.
            return $this->ledger->recalculate($previous);
        });
    }
}
