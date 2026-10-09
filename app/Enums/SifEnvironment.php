<?php

namespace App\Enums;

/** Instalación del sistema de facturación (D-420): la de producción (F y CN) o la de pruebas (PRU). */
enum SifEnvironment: string
{
    case Production = 'production';
    case Test = 'test';
}
