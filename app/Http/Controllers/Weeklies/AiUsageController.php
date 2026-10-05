<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use Illuminate\Support\Facades\Gate;

/**
 * «Uso de IA» (F-173 y F-180): llamadas, tokens, caracteres y coste de 30 días por función y modelo,
 * de ai_usage. Solo admins. Esqueleto del contrato 10.1 (10.3).
 */
class AiUsageController extends Controller
{
    use PendingDelivery;

    public function __invoke(): never
    {
        Gate::authorize('view-ai-usage');

        $this->pending('10.3');
    }
}
