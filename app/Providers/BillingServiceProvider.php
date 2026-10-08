<?php

namespace App\Providers;

use App\Domain\Billing\Holded\FakeHolded;
use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedConnection;
use App\Domain\Billing\Holded\HttpHoldedClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Facturación (Fase 12, F1; D-384): la API de Holded. Con HOLDED_DRIVER=fake, un Holded vacío en los
 * tests (cada test pone sus datos) y, en local, uno coherente con los datos de ejemplo
 * (FakeHolded::fromDatabase). Si no, el cliente real con la clave del .env (si falta, lanza
 * HoldedRequestFailed::notConfigured al pedirlo; la sincronización lo comprueba antes).
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HoldedApi::class, fn (): HoldedApi => HoldedConnection::fake()
            ? ($this->app->runningUnitTests() ? new FakeHolded : FakeHolded::fromDatabase())
            : HttpHoldedClient::fromConfig($this->app->make(HttpFactory::class)));
    }
}
