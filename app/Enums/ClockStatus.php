<?php

namespace App\Enums;

/**
 * Estado del registro de una persona ahora mismo (el botón de la cabecera, D-333):
 * - `off`: no tiene jornada abierta (no ha fichado hoy, o la abierta se quedó sin salida),
 * - `working`: trabajando desde la entrada o la vuelta de la pausa,
 * - `paused`: en la pausa de la comida,
 * - `closed`: ha fichado la salida hoy (puede volver a entrar: jornada partida).
 */
enum ClockStatus: string
{
    case Off = 'off';
    case Working = 'working';
    case Paused = 'paused';
    case Closed = 'closed';
}
