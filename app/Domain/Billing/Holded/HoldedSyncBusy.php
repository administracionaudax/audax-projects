<?php

namespace App\Domain\Billing\Holded;

use RuntimeException;

/** Ya hay una sincronización con Holded en marcha (Fase 12, D-387). */
final class HoldedSyncBusy extends RuntimeException {}
