<?php

namespace App\Console\Commands;

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedConnection;
use App\Domain\Billing\Holded\HoldedRequestFailed;
use App\Domain\Billing\Holded\HoldedSync;
use App\Domain\Billing\Holded\HoldedSyncBusy;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sincronización de solo lectura con Holded (Fase 12, F1; D-387): contactos, facturas, rectificativas,
 * cobros, PDF y enlaces con proyectos y bolsas. Programada cada noche a las 02:30 de Madrid
 * (routes/console.php). Con el módulo `billing` apagado o sin clave, no hace nada (salvo --forzar,
 * que ignora el módulo). Nunca escribe en Holded.
 */
#[Signature('app:holded-sync {--sin-pdf : No descarga los PDF} {--forzar : Aunque el módulo Facturación esté apagado} {--programada : La lanza el programador}')]
#[Description('Lee de Holded las facturas, rectificativas, cobros, contactos y PDF (solo lectura)')]
class HoldedSyncCommand extends Command
{
    public function handle(HoldedSync $sync): int
    {
        if (! $this->option('forzar') && ! AppModules::enabled(AppModule::Billing)) {
            $this->info('Módulo Facturación apagado: no se sincroniza.');

            return self::SUCCESS;
        }

        if (! HoldedConnection::configured()) {
            $this->warn((string) __('billing.holded.errors.not_configured'));

            return $this->option('programada') ? self::SUCCESS : self::FAILURE;
        }

        try {
            $run = $sync->run(app(HoldedApi::class), $this->option('programada') ? 'schedule' : 'command', null, ! $this->option('sin-pdf'));
        } catch (HoldedSyncBusy $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        } catch (HoldedRequestFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $stats = $run->stats ?? [];
        ksort($stats);
        $this->table(['Dato', 'Valor'], array_map(fn (string $key, int $value): array => [$key, $value], array_keys($stats), $stats));
        $this->info('Sincronización con Holded terminada.');

        return self::SUCCESS;
    }
}
