<?php

namespace App\Console\Commands;

use App\Domain\People\RegisterAnchors;
use App\Domain\People\RegisterIntegrity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Comprueba la cadena de huellas del registro de jornada, los sellos de las correcciones, las
 * horas extra, el saldo, los cierres y las anclas (D-332 y D-352): sale con error si algo no
 * cuadra. Con --nightly (cada noche a las 02:50 de Madrid, antes de la copia de seguridad) guarda
 * además el ancla del día y, si falla, avisa a los admins.
 */
#[Signature('people:verify-register {--user=* : Solo estas personas (id)} {--nightly : Guarda el ancla del día y avisa a los admins si falla}')]
#[Description('Comprueba que el registro de jornada no se ha alterado (cadena de huellas y ancla diaria)')]
class VerifyRegister extends Command
{
    public function handle(RegisterIntegrity $integrity, RegisterAnchors $anchors): int
    {
        if ($this->option('nightly')) {
            ['anchor' => $anchor, 'result' => $result] = $anchors->nightly();
            $this->line("Ancla del {$anchor->date->toDateString()}: {$anchor->digest}");
        } else {
            $users = array_values(array_map(intval(...), (array) $this->option('user')));
            $result = $integrity->verify($users === [] ? null : $users);
        }

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
