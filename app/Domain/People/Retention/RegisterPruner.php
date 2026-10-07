<?php

namespace App\Domain\People\Retention;

use App\Domain\People\MonthCloser;
use App\Domain\People\TimeBalanceLedger;
use App\Domain\Privacy\Retention\RetentionPruner;
use App\Enums\ClockEventKind;
use App\Models\EmploymentProfile;
use App\Models\RegisterCheckpoint;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Supresión del registro de jornada a partir del mes 49 (art. 34.9 ET: 4 años; AEPD: suprimir lo
 * que ya no hace falta; PLAN-FASE-11 §11.2; D-348). La llama `app:prune-data` con el plazo de
 * RetentionPolicy::PEOPLE_REGISTER (48 meses como mínimo: el ajuste no deja poner menos).
 *
 * - El plazo cuenta **desde el final del mes**: un fichaje de enero de 2026 se guarda hasta el
 *   31/01/2030 y se suprime desde el 01/02/2030. Con el corte de app:prune-data (ahora − N meses)
 *   se borra lo anterior al día 1 del mes de ese corte.
 * - **Nunca** de quien tiene la retención por litigio activa (`employment_profiles.legal_hold`,
 *   G.5): su registro queda bloqueado entero, y mientras haya alguna retención no se borran las
 *   anclas ni la auditoría del registro.
 * - De cada persona se borra un **tramo inicial** de su cadena (todo lo registrado antes del corte,
 *   sin dejar una anulación sin su fichaje ni una jornada partida) y se guarda su punto de control
 *   (RegisterCheckpoint): la cadena se sigue comprobando y numerando desde ahí.
 * - Con ella se van las correcciones, los cierres (y sus PDF), las decisiones de horas extra, los
 *   movimientos del saldo (el saldo que sumaban se arrastra con un movimiento de saldo inicial), los
 *   avisos de fichaje, las anclas, los ficheros anotados y la auditoría del registro anteriores al
 *   corte.
 *
 * Solo aquí se quita la protección de los *triggers* (RegisterGuards::withPruning), dentro de una
 * transacción.
 */
final class RegisterPruner implements RetentionPruner
{
    /** Auditoría del registro: se guarda lo mismo que el registro, aunque la general sea menor. */
    public const array ACTIVITY_LOGS = ['clock_corrections', 'employment_profiles', 'month_closes', 'people-register', 'people-exports', 'people_documents', 'inspection'];

    public function __construct(private readonly TimeBalanceLedger $ledger) {}

    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        $limit = $cutoff->setTimezone(LocalTime::timezone())->startOfMonth();
        $limitDate = $limit->toDateString();
        $limitInstant = $limit->utc();
        $held = EmploymentProfile::query()->where('legal_hold', true)->pluck('user_id')->all();

        return RegisterGuards::withPruning(function () use ($limitDate, $limitInstant, $held, $batchSize): int {
            $deleted = 0;

            $users = DB::table('clock_events')->where('recorded_at', '<', $limitInstant)->whereNotIn('user_id', $held)->distinct()->pluck('user_id')
                ->merge(DB::table('month_closes')->where('month', '<', $limitDate)->whereNotIn('user_id', $held)->distinct()->pluck('user_id'))
                ->merge(DB::table('time_balance_movements')->where('date', '<', $limitDate)->whereNotIn('user_id', $held)->distinct()->pluck('user_id'))
                ->merge(DB::table('overtime_decisions')->where('date', '<', $limitDate)->whereNotIn('user_id', $held)->distinct()->pluck('user_id'))
                ->merge(DB::table('clock_corrections')->where('date', '<', $limitDate)->whereNotIn('user_id', $held)->distinct()->pluck('user_id'))
                ->unique()
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($users as $userId) {
                $deleted += $this->pruneUser($userId, $limitDate, $limitInstant, $batchSize);
            }

            $deleted += DB::table('clock_reminders')->where('date', '<', $limitDate)->whereNotIn('user_id', $held)->delete();
            $deleted += DB::table('people_exports')->where('created_at', '<', $limitInstant)->delete();
            $deleted += DB::table('inspection_accesses')->where('valid_until', '<', $limitInstant)->delete();

            if ($held === []) {
                $deleted += DB::table('register_anchors')->where('date', '<', $limitDate)->delete();
                $deleted += DB::table('activity_log')->whereIn('log_name', self::ACTIVITY_LOGS)->where('created_at', '<', $limitInstant)->delete();
            }

            return $deleted;
        });
    }

    private function pruneUser(int $userId, string $limitDate, CarbonImmutable $limitInstant, int $batchSize): int
    {
        $deleted = 0;
        $last = $this->prefix($userId, $limitInstant);

        if ($last !== null) {
            $ids = DB::table('clock_events')->where('user_id', $userId)->where('seq', '<=', $last['seq'])->orderByDesc('seq')->pluck('id')->all();

            foreach (array_chunk($ids, max(1, $batchSize)) as $chunk) {
                // De la más nueva a la más antigua: una anulación se borra antes que su fichaje.
                foreach ($chunk as $id) {
                    $deleted += DB::table('clock_events')->where('id', $id)->delete();
                }
            }

            RegisterCheckpoint::query()->updateOrCreate(['user_id' => $userId], [
                'seq' => $last['seq'],
                'hash' => $last['hash'],
                'pruned_through' => CarbonImmutable::parse($limitDate)->subDay()->toDateString(),
            ]);
        }

        $deleted += DB::table('clock_corrections')
            ->where('user_id', $userId)
            ->where('date', '<', $limitDate)
            ->whereNotExists(fn ($query) => $query->from('clock_events')->whereColumn('clock_events.correction_id', 'clock_corrections.id'))
            ->delete();

        // El saldo que sumaban los movimientos que se van se arrastra (nadie pierde horas).
        $carried = (int) DB::table('time_balance_movements')->where('user_id', $userId)->where('date', '<', $limitDate)->sum('minutes');
        $deleted += DB::table('time_balance_movements')->where('user_id', $userId)->where('date', '<', $limitDate)->delete();
        $this->ledger->carryForward($userId, $carried, $limitDate);

        $decisions = DB::table('overtime_decisions')->where('user_id', $userId)->where('date', '<', $limitDate)->orderByDesc('id')->pluck('id')->all();
        foreach ($decisions as $id) {
            $deleted += DB::table('overtime_decisions')->where('id', $id)->delete();
        }

        $closes = DB::table('month_closes')->where('user_id', $userId)->where('month', '<', $limitDate)->get(['id', 'pdf_path']);
        foreach ($closes as $close) {
            Storage::disk(MonthCloser::DISK)->delete((string) $close->pdf_path);
            $deleted += DB::table('month_closes')->where('id', $close->id)->delete();
        }

        return $deleted;
    }

    /**
     * La última fila del tramo inicial que se puede suprimir: registrada antes del corte, que no
     * deje una jornada a medias (acaba en una salida o una anulación) y cuyas filas no anule nada
     * posterior al corte.
     *
     * @return array{seq: int, hash: string}|null
     */
    private function prefix(int $userId, CarbonImmutable $limitInstant): ?array
    {
        $rows = DB::table('clock_events')
            ->where('user_id', $userId)
            ->where('recorded_at', '<', $limitInstant)
            ->orderBy('seq')
            ->get(['id', 'seq', 'kind', 'hash'])
            ->all();

        if ($rows === []) {
            return null;
        }

        $last = (int) $rows[count($rows) - 1]->seq;
        $seqById = [];
        foreach ($rows as $row) {
            $seqById[(int) $row->id] = (int) $row->seq;
        }

        // Una anulación posterior al corte que apunta a un fichaje del tramo: el tramo acaba antes.
        $voided = DB::table('clock_events')
            ->where('user_id', $userId)
            ->where('seq', '>', $last)
            ->whereNotNull('voided_event_id')
            ->pluck('voided_event_id')
            ->all();

        foreach ($voided as $id) {
            if (isset($seqById[(int) $id])) {
                $last = min($last, $seqById[(int) $id] - 1);
            }
        }

        // Que no quede una jornada a medias: el tramo acaba en una salida o una anulación.
        $bySeq = [];
        foreach ($rows as $row) {
            $bySeq[(int) $row->seq] = $row;
        }

        while ($last > 0 && isset($bySeq[$last]) && ! in_array($bySeq[$last]->kind, [ClockEventKind::ClockOut->value, ClockEventKind::Void->value], true)) {
            $last--;
        }

        if ($last <= 0 || ! isset($bySeq[$last])) {
            return null;
        }

        return ['seq' => $last, 'hash' => (string) $bySeq[$last]->hash];
    }
}
