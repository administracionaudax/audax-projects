<?php

namespace App\Models;

use App\Enums\SifEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Instalación del sistema informático de facturación (PLAN-EMISION §4.1 y §2.7.4; D-420): NIF +
 * código del sistema + número de instalación identifican un sistema, con su propia cadena de
 * registros. Producción lleva F y CN; pruebas, PRU y PRUCN. El número no se reutiliza nunca.
 *
 * @property int $id
 * @property string $sif_code
 * @property string $sif_name
 * @property string $installation_number
 * @property SifEnvironment $environment
 * @property string $mode
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $locked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['sif_code', 'sif_name', 'installation_number', 'environment', 'mode', 'started_at', 'ended_at'])]
class SifInstallation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => SifEnvironment::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
        ];
    }
}
