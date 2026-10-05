<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\Dictation;
use Illuminate\Support\Facades\Gate;

/**
 * Dictado (F-049, F-050, F-060, F-171 y F-172, D-152): subir el audio (se transcribe con Whisper en
 * la cola `transcriptions`) y consultar su estado. Esqueleto del contrato 10.1 (10.2).
 */
class DictationController extends Controller
{
    use PendingDelivery;

    public function store(): never
    {
        Gate::authorize('create', Dictation::class);

        $this->pending('10.2');
    }

    public function show(Dictation $dictation): never
    {
        Gate::authorize('view', $dictation);

        $this->pending('10.2');
    }
}
