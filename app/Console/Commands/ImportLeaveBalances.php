<?php

namespace App\Console\Commands;

use App\Domain\Absences\LeaveLedger;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Saldo inicial de vacaciones y permisos desde Woffu (Fase 11, R3 para R5; PLAN-FASE-11 §13; D-363):
 * un CSV con «email;tipo;cantidad;caducidad» (separado por punto y coma, con cabecera opcional; el
 * tipo es la clave del catálogo, como `vacation` o `force_majeure`; la cantidad, en días o en horas,
 * como en el informe «Saldos» de Woffu; la caducidad, AAAA-MM-DD u omitida). Cada fila es un
 * movimiento «saldo inicial» con el motivo «Saldo inicial desde Woffu a dd/mm/aaaa», anotado en
 * nombre de la persona de RR. HH. que se indica. Con --dry-run solo dice qué haría.
 */
#[Signature('people:import-leave-balances {file : Ruta del CSV} {--date= : Fecha del corte (AAAA-MM-DD)} {--by= : Email de quien lo anota (RR. HH.)} {--dry-run}')]
#[Description('Carga el saldo inicial de vacaciones y permisos exportado de Woffu')]
class ImportLeaveBalances extends Command
{
    public function handle(LeaveLedger $ledger): int
    {
        $path = (string) $this->argument('file');
        $date = (string) $this->option('date');
        $actor = User::query()->where('email', (string) $this->option('by'))->first();

        if (! is_readable($path) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $actor === null) {
            $this->error('Indica un CSV legible, --date=AAAA-MM-DD y --by=email de RR. HH.');

            return self::FAILURE;
        }

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [] as $line) {
            $cells = array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($line, ';', '"', ''));

            if (count($cells) < 3 || ! str_contains((string) $cells[0], '@')) {
                continue;
            }

            $rows[] = ['email' => (string) $cells[0], 'type' => (string) $cells[1], 'amount' => (string) $cells[2], 'expires_on' => $cells[3] ?? null];
        }

        $result = $ledger->importOpening($actor, $rows, $date, (bool) $this->option('dry-run'));
        $this->table(['Línea', 'Persona', 'Tipo', 'Cantidad', 'Estado', 'Mensaje'], array_map(fn (array $row): array => [
            $row['line'], $row['email'], $row['type'], $row['amount'], $row['status'], $row['message'],
        ], $result));

        return in_array('error', array_column($result, 'status'), true) ? self::FAILURE : self::SUCCESS;
    }
}
