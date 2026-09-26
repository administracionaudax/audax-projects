<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /buscar?q= → {"results":[{"type","id","title","subtitle","url"}]} (máx. 20), respetando permisos.
 * Menos de 2 caracteres → lista vacía. Los clientes no llegan aquí (middleware internal).
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.GlobalSearch::MAX_RESULTS],
        ]);

        /** @var User $user */
        $user = $request->user();

        $results = $search->search(
            $user,
            (string) ($validated['q'] ?? ''),
            (int) ($validated['limit'] ?? GlobalSearch::MAX_RESULTS),
        );

        return response()->json(['results' => $results]);
    }
}
