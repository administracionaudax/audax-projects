<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Han cambiado miembros o gestores de un proyecto, o responsables de un departamento (lo avisa
 * User::forgetMemberships). Lo escuchan quienes guardan cálculos que dependen del alcance de cada
 * persona, como la caché de los informes (ReportsServiceProvider, INT-03).
 */
final class MembershipsChanged
{
    use Dispatchable;
}
