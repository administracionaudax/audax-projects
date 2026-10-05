<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\HelpFaq;
use App\Models\HelpFaqSection;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpTutorial;
use Illuminate\Support\Facades\Gate;

/**
 * Contenido del centro de ayuda (F-150 a F-156): novedades y sus cambios, actualizaciones manuales,
 * tutoriales en vídeo, secciones y preguntas frecuentes, y los «me gusta». Gestiona `manage-help`;
 * los «me gusta» y ver un vídeo, cualquiera de la plantilla. Esqueleto del contrato 10.1 (10.7).
 */
class HelpContentController extends Controller
{
    use PendingDelivery;

    public function storeRelease(): never
    {
        $this->manage();
    }

    public function updateRelease(HelpRelease $release): never
    {
        $this->manage();
    }

    public function destroyRelease(HelpRelease $release): never
    {
        $this->manage();
    }

    public function storeUpdate(): never
    {
        $this->manage();
    }

    public function updateUpdate(HelpManualUpdate $manualUpdate): never
    {
        $this->manage();
    }

    public function destroyUpdate(HelpManualUpdate $manualUpdate): never
    {
        $this->manage();
    }

    /** «Me gusta» de una novedad o actualización, alternando (F-153). */
    public function like(): never
    {
        Gate::authorize('use-weeklies');

        $this->pending('10.7');
    }

    public function storeTutorial(): never
    {
        $this->manage();
    }

    public function updateTutorial(HelpTutorial $tutorial): never
    {
        $this->manage();
    }

    public function destroyTutorial(HelpTutorial $tutorial): never
    {
        $this->manage();
    }

    public function reorderTutorials(): never
    {
        $this->manage();
    }

    /** El vídeo de un tutorial, con ruta firmada (F-155). */
    public function video(HelpTutorial $tutorial): never
    {
        Gate::authorize('use-weeklies');

        $this->pending('10.7');
    }

    public function storeSection(): never
    {
        $this->manage();
    }

    public function updateSection(HelpFaqSection $faqSection): never
    {
        $this->manage();
    }

    public function destroySection(HelpFaqSection $faqSection): never
    {
        $this->manage();
    }

    public function storeFaq(): never
    {
        $this->manage();
    }

    public function updateFaq(HelpFaq $faq): never
    {
        $this->manage();
    }

    public function destroyFaq(HelpFaq $faq): never
    {
        $this->manage();
    }

    private function manage(): never
    {
        Gate::authorize('manage-help');

        $this->pending('10.7');
    }
}
