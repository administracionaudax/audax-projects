<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\Task;
use Illuminate\Support\Facades\Gate;

/**
 * Tareas de «Mi espacio» (F-055 a F-063, D-151): tareas sugeridas por IA a partir de la última
 * weekly cerrada (siempre revisadas antes de crearlas con TaskWriter) y archivado personal.
 * Esqueleto del contrato 10.1 (10.6).
 */
class MySpaceTaskController extends Controller
{
    use PendingDelivery;

    /** Pedir sugerencias de tareas a la IA (Job en la cola `ai`, F-062). */
    public function suggest(): never
    {
        Gate::authorize('use-weeklies');

        $this->pending('10.6');
    }

    public function archive(Task $task): never
    {
        Gate::authorize('view', $task);

        $this->pending('10.6');
    }

    public function unarchive(Task $task): never
    {
        Gate::authorize('view', $task);

        $this->pending('10.6');
    }
}
