<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstNonTestDatabase();

        // Los tests de backend no dependen de que exista public/build (se compila en la CI).
        $this->withoutVite();
    }

    /**
     * RefreshDatabase migra DENTRO de parent::setUp(), antes de que setUp() llegue a la guarda.
     * Por eso la guarda se ejecuta también aquí: justo después de cargar la configuración y
     * antes de que ningún trait toque la base.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * Los tests nunca deben tocar la base de desarrollo o producción (D-002):
     * solo se permiten bases cuyo nombre termine en "_test" o SQLite en memoria. Con Pest en
     * paralelo, Laravel crea una por proceso con el sufijo «_test_N» (audax_projects_test_test_3):
     * también valen.
     */
    private function guardAgainstNonTestDatabase(?Application $app = null): void
    {
        $config = ($app ?? $this->app)->make('config');
        $connection = $config->get('database.default');
        $database = (string) $config->get("database.connections.{$connection}.database");

        if ($database !== ':memory:' && preg_match('/_test(_test_\d+)?$/', $database) !== 1) {
            throw new RuntimeException("Tests abortados: la base activa [{$database}] no es de test.");
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
