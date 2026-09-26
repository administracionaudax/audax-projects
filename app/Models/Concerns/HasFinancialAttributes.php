<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Datos económicos (tarifas, precios, costes) ocultos en la serialización salvo para quien
 * tiene view-financials (SPEC §5). Cada modelo declara FINANCIAL_ATTRIBUTES.
 *
 * Los Resources (app/Http/Resources) aplican la misma regla de forma explícita; este trait es la
 * red de seguridad por si un modelo se serializa entero (toArray, props de Inertia…).
 */
trait HasFinancialAttributes
{
    public function initializeHasFinancialAttributes(): void
    {
        $this->makeHidden(static::FINANCIAL_ATTRIBUTES);
    }

    /**
     * Muestra los datos económicos solo si $viewer puede verlos.
     */
    public function revealFinancialsTo(?User $viewer): static
    {
        if ($viewer !== null && Gate::forUser($viewer)->allows('view-financials')) {
            $this->makeVisible(static::FINANCIAL_ATTRIBUTES);
        }

        return $this;
    }
}
