<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Tasks\AttachmentStorage;
use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\Help\HelpCenter;
use App\Domain\Weeklies\Help\TutorialVideoUploads;
use App\Domain\Weeklies\Suggestions\SuggestionBoardView;
use App\Enums\AppModule;
use App\Events\Weeklies\HelpCenterChanged;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SuggestionPost;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Centro de ayuda (/ayuda?pestana=general|tutoriales|preguntas|sugerencias, F-148 a F-158). Lo ve la
 * plantilla (`use-weeklies`); el contenido lo gestiona `manage-help` (= manage-weeklies, D-147).
 * Cada pestaña trae solo sus datos: las demás llegan a null sin consultas. La de sugerencias, solo
 * con el módulo `suggestions` encendido (D-151); si no, se abre la general.
 */
class HelpController extends Controller
{
    public const array TABS = ['general', 'tutoriales', 'preguntas', 'sugerencias'];

    public function index(Request $request, HelpCenter $center, SuggestionBoardView $suggestions): Response
    {
        return $this->page($request, $center, $suggestions);
    }

    /**
     * La página; con $post, la pestaña de sugerencias con esa sugerencia abierta (suggestions.show).
     */
    public function page(Request $request, HelpCenter $center, SuggestionBoardView $suggestions, ?SuggestionPost $post = null): Response
    {
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();
        $manage = Gate::allows('manage-help');
        $suggestionsOn = AppModules::visibleTo($user, AppModule::Suggestions);
        $tab = $request->string('pestana')->toString();
        $tab = in_array($tab, self::TABS, true) && ($tab !== 'sugerencias' || $suggestionsOn) ? $tab : 'general';
        $tab = $post !== null ? 'sugerencias' : $tab;

        if ($tab === 'general' || $tab === 'tutoriales') {
            $center->ensureCurrentRelease();
        }

        return Inertia::render('help/index', [
            'tab' => $tab,
            'can' => [
                'manage' => $manage,
                'suggestions' => $suggestionsOn,
            ],
            'settings' => $center->settings(),
            'updates' => $tab === 'general' ? $center->updates($user) : null,
            'releases' => $tab === 'tutoriales' || ($tab === 'general' && $manage) ? $center->releaseOptions() : null,
            'tutorials' => $tab === 'tutoriales' ? $center->tutorials() : null,
            'faq_sections' => $tab === 'preguntas' ? $center->faqSections() : null,
            'suggestions' => $tab === 'sugerencias' ? $suggestions->page($request, $user, $post) : null,
            'upload' => [
                'video_max_bytes' => TutorialVideoUploads::MAX_BYTES,
                'video_chunk_bytes' => TutorialVideoUploads::CHUNK_BYTES,
                'attachment_max_mb' => AttachmentStorage::maxMegabytes(),
            ],
        ]);
    }

    /** Manual en PDF (F-157), con ruta firmada. */
    public function manual(): BinaryFileResponse
    {
        Gate::authorize('use-weeklies');

        $manual = Setting::get('help_manual');
        abort_unless(is_array($manual) && isset($manual['path'], $manual['name']), 404);

        $disk = Storage::disk((string) ($manual['disk'] ?? AttachmentStorage::DISK));
        abort_unless($disk->exists((string) $manual['path']), 404);

        $name = (string) $manual['name'];
        $response = new BinaryFileResponse($disk->path((string) $manual['path']), 200, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=3600',
        ], public: false);
        $response->setContentDisposition('inline', $name, Str::ascii($name) !== '' ? (string) preg_replace('/[^A-Za-z0-9._-]/', '-', Str::ascii($name)) : 'manual.pdf');

        return $response;
    }

    /** Manual en PDF y enlace de soporte (F-157). */
    public function updateSettings(Request $request): RedirectResponse
    {
        Gate::authorize('manage-help');

        $data = $request->validate([
            'support_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'manual' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:'.AttachmentStorage::maxKilobytes()],
            'remove_manual' => ['nullable', 'boolean'],
        ], [
            'manual.mimetypes' => __('help.settings.manual_pdf'),
            'manual.max' => __('help.settings.manual_too_big', ['max' => AttachmentStorage::maxMegabytes()]),
        ]);

        /** @var User $user */
        $user = $request->user();
        $previous = Setting::get('help_manual');
        $manual = $previous;
        $file = $request->file('manual');

        if ($file instanceof UploadedFile) {
            $path = $file->storeAs('help/manual', Str::uuid().'.pdf', AttachmentStorage::DISK);
            abort_if($path === false, 500);
            $manual = [
                'disk' => AttachmentStorage::DISK,
                'path' => $path,
                'name' => AttachmentStorage::cleanName($file->getClientOriginalName()),
                'size' => (int) $file->getSize(),
            ];
        } elseif ($request->boolean('remove_manual')) {
            $manual = null;
        }

        $supportUrl = isset($data['support_url']) && $data['support_url'] !== '' ? (string) $data['support_url'] : null;
        Setting::set('help_support_url', $supportUrl);
        Setting::set('help_manual', $manual);

        if (is_array($previous) && isset($previous['path']) && $manual !== $previous) {
            Storage::disk((string) ($previous['disk'] ?? AttachmentStorage::DISK))->delete((string) $previous['path']);
        }

        activity('help')
            ->causedBy($user)
            ->event('settings_updated')
            ->withProperties([
                'support_url' => $supportUrl,
                'manual' => is_array($manual) ? $manual['name'] : null,
            ])
            ->log('settings_updated');

        HelpCenterChanged::dispatch('help');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('help.settings.saved')]);

        return back();
    }
}
