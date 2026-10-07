<?php

namespace App\Console\Commands;

use App\Domain\People\RegisterIntegrity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Comprueba la cadena de huellas del registro de jornada y los sellos de las correcciones (D-332):
 * sale con error si algo no cuadra. R2 la programará cada noche con aviso a los admins.
 */
#[Signature('people:verify-register {--user=* : Solo estas personas (id)}')]
#[Description('Comprueba que el registro de jornada no se ha alterado (cadena de huellas)')]
class VerifyRegister extends Command
{
    public function handle(RegisterIntegrity $integrity): int
    {
        $users = array_values(array_map(intval(...), (array) $this->option('user')));
        $result = $integrity->verify($users === [] ? null : $users);

        if ($result['ok']) {
            $this->info("Registro íntegro: {$result['events']} filas y {$result['corrections']} correcciones decididas comprobadas.");

            return self::SUCCESS;
        }

        foreach ($result['problems'] as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }
}
