<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centro de ayuda (/ayuda?pestana=general|tutoriales|preguntas|sugerencias, F-148 a F-158). Lo ve la
 * plantilla; el contenido lo gestiona `manage-help` (= manage-weeklies, D-147). Esqueleto del
 * contrato 10.1 (10.7): la página existe; el resto responde 501.
 */
class HelpController extends Controller
{
    use PendingDelivery;

    public const array TABS = ['general', 'tutoriales', 'preguntas', 'sugerencias'];

    public function index(Request $request): Response
    {
        Gate::authorize('use-weeklies');

        $tab = $request->string('pestana')->toString();

        return Inertia::render('help/index', [
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'general',
            'can' => ['manage' => Gate::allows('manage-help')],
        ]);
    }

    /** Manual en PDF (F-157). */
    public function manual(): never
    {
        Gate::authorize('use-weeklies');

        $this->pending('10.7');
    }

    /** Manual y enlace de soporte (F-157). */
    public function updateSettings(): never
    {
        Gate::authorize('manage-help');

        $this->pending('10.7');
    }
}
