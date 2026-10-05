<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use App\Models\SuggestionPost;
use Illuminate\Support\Facades\Gate;

/**
 * Tableros y categorías de las sugerencias (F-160): crear, editar, ocultar y reordenar; solo quien
 * gestiona. Esqueleto del contrato 10.1 (10.7).
 */
class SuggestionBoardController extends Controller
{
    use PendingDelivery;

    public function storeBoard(): never
    {
        $this->manage();
    }

    public function updateBoard(SuggestionBoard $board): never
    {
        $this->manage();
    }

    public function destroyBoard(SuggestionBoard $board): never
    {
        $this->manage();
    }

    public function storeCategory(SuggestionBoard $board): never
    {
        $this->manage();
    }

    public function updateCategory(SuggestionCategory $category): never
    {
        $this->manage();
    }

    public function destroyCategory(SuggestionCategory $category): never
    {
        $this->manage();
    }

    private function manage(): never
    {
        Gate::authorize('manageBoards', SuggestionPost::class);

        $this->pending('10.7');
    }
}
