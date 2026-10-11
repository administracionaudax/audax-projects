<?php

namespace App\Console\Commands;

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedConnection;
use App\Domain\Billing\Recurring\HoldedRecurringImporter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa las recurrentes de Holded en pausa (PLAN-EMISION §7.2; E3). Solo lee de Holded (la API v2
 * con la clave de D-399, que tiene `sales:all.read`) y nunca escribe en él. Con --dry-run enseña lo
 * que haría sin crear nada. Las ya importadas no se tocan; las que no casan un cliente o no tienen
 * una periodicidad que se entienda, se dicen y no se importan.
 */
#[Signature('app:import-holded-recurring {--dry-run : Enseña lo que importaría, sin crear nada} {--por= : Email de quien queda como autor (por defecto, el primer admin)}')]
#[Description('Importa en pausa las facturas recurrentes de Holded (solo lectura)')]
class ImportHoldedRecurring extends Command
{
    public function handle(HoldedRecurringImporter $importer): int
    {
        if (! HoldedConnection::configured() && ! HoldedConnection::fake()) {
            $this->error((string) __('invoicing_work.import.no_api', ['reason' => __('billing.holded.errors.not_configured')]));

            return self::FAILURE;
        }

        $email = $this->option('por');
        $by = is_string($email) && $email !== ''
            ? User::query()->where('email', mb_strtolower($email))->first()
            : User::role(Role::Admin->value)->where('is_active', true)->orderBy('id')->first();
        if ($by === null) {
            $this->error('No hay un admin (o no existe el usuario de --por) para dejar como autor.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        try {
            $rows = $importer->import(app(HoldedApi::class), $by, $dryRun);
        } catch (Throwable $error) {
            $this->error((string) __('invoicing_work.import.no_api', ['reason' => $error->getMessage()]));

            return self::FAILURE;
        }

        $this->info((string) __('invoicing_work.import.title'));
        $columns = array_values(array_map(fn (mixed $column): string => is_string($column) ? $column : '', (array) __('invoicing_work.import.columns')));
        $this->table($columns, array_map(fn (array $row): array => [
            $row['name'],
            $row['client'] ?? '—',
            $row['periodicity'] ?? '—',
            $row['next'] ?? '—',
            number_format((float) $row['subtotal'], 2, ',', '.').' €',
            trim(implode(' ', [(string) __('invoicing_work.import.'.$row['status']), ...$row['problems']])),
        ], $rows));

        foreach ($rows as $row) {
            foreach ($row['warnings'] as $warning) {
                $this->warn($row['name'].': '.$warning);
            }
        }

        $count = fn (string $status): int => count(array_filter($rows, fn (array $row): bool => $row['status'] === $status));
        if ($dryRun) {
            $this->info((string) __('invoicing_work.import.dry_run'));
        }
        $this->info((string) __('invoicing_work.import.done', [
            'created' => $dryRun ? $count('would_create') : $count('created'),
            'existing' => $count('existing'),
            'failed' => $count('failed'),
        ]));

        return self::SUCCESS;
    }
}
