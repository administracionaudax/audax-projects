<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Support\Facades\Gate;

/**
 * Mi weekly de una semana (F-044 a F-054): autoguardar el borrador y enviar o reenviar, siempre con
 * WeeklySubmissionWriter. Esqueleto del contrato 10.1 (10.2).
 */
class MyWeeklyController extends Controller
{
    use PendingDelivery;

    public function draft(WeeklyCycle $cycle): never
    {
        Gate::authorize('create', [WeeklySubmission::class, $cycle]);

        $this->pending('10.2');
    }

    public function submit(WeeklyCycle $cycle): never
    {
        Gate::authorize('create', [WeeklySubmission::class, $cycle]);

        $this->pending('10.2');
    }
}
