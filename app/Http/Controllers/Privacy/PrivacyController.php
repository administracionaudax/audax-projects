<?php

namespace App\Http\Controllers\Privacy;

use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Privacy\PrivacySettings;
use App\Domain\Privacy\RetentionPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /privacidad (SPEC §15, D-075): el texto informativo para la plantilla (markdown que el navegador
 * pinta saneado), los plazos de conservación vigentes y la lectura de la versión vigente. Solo
 * internos: los clientes del portal no llegan aquí (middleware internal).
 */
class PrivacyController extends Controller
{
    public function show(Request $request, PrivacyNotice $notice, PrivacySettings $settings, RetentionPolicy $policy): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('privacy/show', [
            'notice' => [
                'markdown' => $notice->text(),
                'version' => $notice->version(),
                'is_draft' => $notice->isDraft(),
            ],
            'acknowledgement' => [
                'needed' => $notice->needsAcknowledgement($user),
                'version' => $user->privacy_acknowledged_version,
                'at' => $user->privacy_acknowledged_at?->toIso8601ZuluString(),
            ],
            'retention' => $settings->retention(),
            'exportDays' => $policy->exportDays(),
        ]);
    }

    /**
     * «He leído la información»: registra la lectura de la versión vigente (users.privacy_acknowledged_*)
     * y la deja en la auditoría (log privacy), que guarda el historial de todas las versiones leídas.
     */
    public function acknowledge(Request $request, PrivacyNotice $notice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($notice->needsAcknowledgement($user)) {
            $notice->acknowledge($user);

            activity('privacy')
                ->performedOn($user)
                ->causedBy($user)
                ->event('acknowledged')
                ->withProperties(['version' => $notice->version()])
                ->log('privacy_notice.acknowledged');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('privacy.notice.acknowledged', ['version' => $notice->version()])]);

        return to_route('privacy.show');
    }
}
