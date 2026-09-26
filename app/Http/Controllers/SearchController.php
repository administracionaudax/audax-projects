<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /buscar?q= → {"results":[{"type","id","title","subtitle","url"}]} (máx. 20), respetando permisos.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // CONTRATO: implementar App\Search\GlobalSearch con fuentes por permisos (agente backend).
        return response()->json(['results' => []]);
    }
}
