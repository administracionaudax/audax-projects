<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\SuggestionPost;
use Illuminate\Support\Facades\Gate;

/**
 * Sugerencias (F-159 a F-168): detalle, crear, editar, borrar, votar, estado con nota oficial y orden
 * en el roadmap. El listado (Feedback y Roadmap) va en /ayuda?pestana=sugerencias. Esqueleto del
 * contrato 10.1 (10.7).
 */
class SuggestionController extends Controller
{
    use PendingDelivery;

    public function show(SuggestionPost $post): never
    {
        Gate::authorize('view', $post);

        $this->pending('10.7');
    }

    public function store(): never
    {
        Gate::authorize('create', SuggestionPost::class);

        $this->pending('10.7');
    }

    public function update(SuggestionPost $post): never
    {
        Gate::authorize('update', $post);

        $this->pending('10.7');
    }

    public function destroy(SuggestionPost $post): never
    {
        Gate::authorize('delete', $post);

        $this->pending('10.7');
    }

    public function vote(SuggestionPost $post): never
    {
        Gate::authorize('vote', $post);

        $this->pending('10.7');
    }

    public function status(SuggestionPost $post): never
    {
        Gate::authorize('moderate', $post);

        $this->pending('10.7');
    }

    public function position(SuggestionPost $post): never
    {
        Gate::authorize('moderate', $post);

        $this->pending('10.7');
    }
}
