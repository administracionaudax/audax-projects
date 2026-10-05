<?php

namespace App\Http\Controllers\Weeklies\Concerns;

/**
 * Esqueleto del contrato 10.1: la ruta, su nombre y su autorización ya existen; la acción llega en
 * la entrega indicada. Hasta entonces responde 501 (después de autorizar: un 403 sigue siendo 403).
 */
trait PendingDelivery
{
    protected function pending(string $delivery): never
    {
        abort(501, __('weeklies.not_implemented')." ({$delivery})");
    }
}
