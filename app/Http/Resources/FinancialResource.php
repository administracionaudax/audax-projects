<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Base de los Resources con datos económicos: solo se incluyen si quien pide puede verlos
 * (view-financials, SPEC §5). Nunca se envían ocultos al navegador.
 */
abstract class FinancialResource extends JsonResource
{
    protected function canSeeFinancials(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && Gate::forUser($user)->allows('view-financials');
    }
}
