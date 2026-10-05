<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Tasks\AttachmentStorage;
use App\Domain\Weeklies\Help\TutorialVideoUploads;
use App\Events\Weeklies\HelpCenterChanged;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\HelpFaq;
use App\Models\HelpFaqSection;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpTutorial;
use App\Models\HelpUpdateLike;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Contenido del centro de ayuda (F-150 a F-156): novedades y sus cambios, actualizaciones a mano,
 * tutoriales en vídeo (subidos por trozos, D-207), secciones y preguntas frecuentes, y los «me gusta».
 * Gestiona `manage-help`; los «me gusta» y ver un vídeo, cualquiera de la plantilla. Cada cambio
 * avisa a las páginas abiertas (HelpCenterChanged, F-170).
 */
class HelpContentController extends Controller
{
    // --- Novedades: versiones automáticas (F-150) -------------------------------------------

    public function storeRelease(Request $request): RedirectResponse
    {
        $this->manage();
        $data = $this->validateRelease($request);

        $this->saveRelease(new HelpRelease, $data);

        return $this->done('help', 'help.releases.created');
    }

    public function updateRelease(Request $request, HelpRelease $release): RedirectResponse
    {
        $this->manage();
        $data = $this->validateRelease($request, $release);

        $this->saveRelease($release, $data);

        return $this->done('help', 'help.releases.updated');
    }

    /** «Eliminar versión» oculta la versión, como en WeeklySync: sus cambios y tutoriales se conservan. */
    public function destroyRelease(HelpRelease $release): RedirectResponse
    {
        $this->manage();

        $release->update(['is_hidden' => true]);

        return $this->done('help', 'help.releases.hidden');
    }

    // --- Novedades: actualizaciones a mano (F-151) -------------------------------------------

    public function storeUpdate(Request $request): RedirectResponse
    {
        $this->manage();

        /** @var User $user */
        $user = $request->user();
        HelpManualUpdate::query()->create($this->validateUpdate($request) + ['created_by' => $user->id]);

        return $this->done('help', 'help.updates.created');
    }

    public function updateUpdate(Request $request, HelpManualUpdate $manualUpdate): RedirectResponse
    {
        $this->manage();

        $manualUpdate->update($this->validateUpdate($request));

        return $this->done('help', 'help.updates.updated');
    }

    public function destroyUpdate(HelpManualUpdate $manualUpdate): RedirectResponse
    {
        $this->manage();

        DB::transaction(function () use ($manualUpdate): void {
            $manualUpdate->likes()->delete();
            $manualUpdate->delete();
        });

        return $this->done('help', 'help.updates.deleted');
    }

    /** «Me gusta» de una novedad o actualización, alternando (F-153). */
    public function like(Request $request): RedirectResponse
    {
        Gate::authorize('use-weeklies');

        $data = $request->validate([
            'kind' => ['required', Rule::in(['release', 'manual'])],
            'id' => ['required', 'integer'],
        ]);

        /** @var Model $target */
        $target = $data['kind'] === 'release'
            ? HelpRelease::query()->where('is_hidden', false)->findOrFail($data['id'])
            : HelpManualUpdate::query()->findOrFail($data['id']);

        /** @var User $user */
        $user = $request->user();
        $existing = HelpUpdateLike::query()
            ->where('likeable_type', $target->getMorphClass())
            ->where('likeable_id', $target->getKey())
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            try {
                HelpUpdateLike::query()->create([
                    'likeable_type' => $target->getMorphClass(),
                    'likeable_id' => $target->getKey(),
                    'user_id' => $user->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Dos clics a la vez: ya está.
            }
        }

        HelpCenterChanged::dispatch('help');

        return back();
    }

    // --- Tutoriales (F-155) ------------------------------------------------------------------

    /** Empieza la subida por trozos de un vídeo (D-207). */
    public function startUpload(Request $request, TutorialVideoUploads $uploads): JsonResponse
    {
        $this->manage();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.TutorialVideoUploads::MAX_BYTES],
        ], [
            'size.max' => __('help.tutorials.too_big'),
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json($uploads->start($user, (string) $data['name'], (int) $data['size']), 201);
    }

    /** Un trozo del vídeo. */
    public function uploadChunk(Request $request, string $upload, TutorialVideoUploads $uploads): JsonResponse
    {
        $this->manage();

        $request->validate([
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:'.intdiv(TutorialVideoUploads::CHUNK_BYTES, 1024)],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var UploadedFile $chunk */
        $chunk = $request->file('chunk');

        return response()->json(['received' => $uploads->append($upload, $user, $request->integer('offset'), $chunk)]);
    }

    public function storeTutorial(Request $request, TutorialVideoUploads $uploads): RedirectResponse
    {
        $this->manage();
        $data = $this->validateTutorial($request, required: true);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($data, $uploads, $user): void {
            $tutorial = HelpTutorial::query()->create([
                'title' => $data['title'],
                'description' => $data['description'],
                'help_release_id' => $data['help_release_id'],
                'position' => (int) HelpTutorial::query()->max('position') + 1,
            ]);

            $uploads->attach((string) $data['upload'], $user, $tutorial);
        });

        return $this->done('help', 'help.tutorials.created');
    }

    public function updateTutorial(Request $request, HelpTutorial $tutorial, TutorialVideoUploads $uploads): RedirectResponse
    {
        $this->manage();
        $data = $this->validateTutorial($request, required: false);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($tutorial, $data, $uploads, $user): void {
            $tutorial->update([
                'title' => $data['title'],
                'description' => $data['description'],
                'help_release_id' => $data['help_release_id'],
            ]);

            if (($data['upload'] ?? null) !== null) {
                $uploads->attach((string) $data['upload'], $user, $tutorial);
            }
        });

        return $this->done('help', 'help.tutorials.updated');
    }

    public function destroyTutorial(HelpTutorial $tutorial, AttachmentStorage $storage): RedirectResponse
    {
        $this->manage();

        DB::transaction(function () use ($tutorial, $storage): void {
            Attachment::query()->whereMorphedTo('attachable', $tutorial)->get()->each(fn (Attachment $video) => $storage->delete($video));
            $tutorial->delete();
        });

        return $this->done('help', 'help.tutorials.deleted');
    }

    public function reorderTutorials(Request $request): RedirectResponse
    {
        $this->manage();

        $this->reorder(HelpTutorial::query()->pluck('id')->all(), $this->ids($request), HelpTutorial::class);

        return $this->done('help', 'help.tutorials.reordered');
    }

    /**
     * El vídeo de un tutorial (ruta firmada), con Range: el navegador lo reproduce a trozos y puede
     * saltar a cualquier punto sin descargarlo entero.
     */
    public function video(HelpTutorial $tutorial): BinaryFileResponse
    {
        Gate::authorize('use-weeklies');

        $video = $tutorial->video()->first();
        abort_if($video === null, 404);

        $disk = Storage::disk($video->disk);
        abort_unless($disk->exists($video->path), 404);

        $response = new BinaryFileResponse($disk->path($video->path), 200, [
            'Content-Type' => $video->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=3600',
        ], public: false);

        $response->setContentDisposition(
            'inline',
            $video->original_name,
            str_replace(['%', '/', '\\'], '', Str::ascii($video->original_name)) ?: 'video',
        );

        return $response;
    }

    // --- Preguntas frecuentes (F-156) --------------------------------------------------------

    public function storeSection(Request $request): RedirectResponse
    {
        $this->manage();
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        HelpFaqSection::query()->create([
            'name' => trim((string) $data['name']),
            'position' => (int) HelpFaqSection::query()->max('position') + 1,
        ]);

        return $this->done('help', 'help.faq.section_created');
    }

    public function updateSection(Request $request, HelpFaqSection $faqSection): RedirectResponse
    {
        $this->manage();
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $faqSection->update(['name' => trim((string) $data['name'])]);

        return $this->done('help', 'help.faq.section_updated');
    }

    /** Una sección con preguntas no se borra (antes se mueven o se borran sus preguntas). */
    public function destroySection(HelpFaqSection $faqSection): RedirectResponse
    {
        $this->manage();

        if ($faqSection->faqs()->exists()) {
            throw ValidationException::withMessages(['section' => __('help.faq.section_not_empty')]);
        }

        $faqSection->delete();

        return $this->done('help', 'help.faq.section_deleted');
    }

    public function reorderSections(Request $request): RedirectResponse
    {
        $this->manage();

        $this->reorder(HelpFaqSection::query()->pluck('id')->all(), $this->ids($request), HelpFaqSection::class);

        return $this->done('help', 'help.faq.sections_reordered');
    }

    public function storeFaq(Request $request): RedirectResponse
    {
        $this->manage();
        $data = $this->validateFaq($request);

        HelpFaq::query()->create($data + [
            'position' => (int) HelpFaq::query()->where('help_faq_section_id', $data['help_faq_section_id'])->max('position') + 1,
        ]);

        return $this->done('help', 'help.faq.created');
    }

    public function updateFaq(Request $request, HelpFaq $faq): RedirectResponse
    {
        $this->manage();
        $data = $this->validateFaq($request);

        // Al cambiar de sección, al final de la nueva.
        if ($data['help_faq_section_id'] !== $faq->help_faq_section_id) {
            $data['position'] = (int) HelpFaq::query()->where('help_faq_section_id', $data['help_faq_section_id'])->max('position') + 1;
        }

        $faq->update($data);

        return $this->done('help', 'help.faq.updated');
    }

    public function destroyFaq(HelpFaq $faq): RedirectResponse
    {
        $this->manage();

        $faq->delete();

        return $this->done('help', 'help.faq.deleted');
    }

    public function reorderFaqs(Request $request): RedirectResponse
    {
        $this->manage();
        $section = $request->validate(['help_faq_section_id' => ['required', 'integer', 'exists:help_faq_sections,id']])['help_faq_section_id'];

        $this->reorder(
            HelpFaq::query()->where('help_faq_section_id', $section)->pluck('id')->all(),
            $this->ids($request),
            HelpFaq::class,
        );

        return $this->done('help', 'help.faq.reordered');
    }

    // --- Comunes ------------------------------------------------------------------------------

    private function manage(): void
    {
        Gate::authorize('manage-help');
    }

    /**
     * @param  'help'|'suggestions'  $scope
     */
    private function done(string $scope, string $message): RedirectResponse
    {
        HelpCenterChanged::dispatch($scope);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }

    /**
     * @return list<int>
     */
    private function ids(Request $request): array
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        return array_values(array_map('intval', $data['ids']));
    }

    /**
     * Guarda el orden: la lista tiene que ser exactamente la que hay (otra persona puede haber
     * añadido o borrado algo mientras tanto; entonces se pide recargar).
     *
     * @param  array<mixed>  $existing
     * @param  list<int>  $ids
     * @param  class-string<Model>  $model
     */
    private function reorder(array $existing, array $ids, string $model): void
    {
        $current = array_map('intval', $existing);
        sort($current);
        $sorted = $ids;
        sort($sorted);

        if ($current !== $sorted) {
            throw ValidationException::withMessages(['ids' => __('help.reorder_stale')]);
        }

        DB::transaction(function () use ($ids, $model): void {
            foreach ($ids as $position => $id) {
                $model::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    /**
     * @return array{major_version: int, month_number: int, week_of_month: int, summary: string, is_hidden: bool|null, changes: list<array{id: int|null, description: string}>|null}
     */
    private function validateRelease(Request $request, ?HelpRelease $release = null): array
    {
        $data = $request->validate([
            'major_version' => ['required', 'integer', 'min:1', 'max:99'],
            'month_number' => ['required', 'integer', 'min:1', 'max:12'],
            'week_of_month' => ['required', 'integer', 'min:1', 'max:5'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'is_hidden' => ['nullable', 'boolean'],
            'changes' => ['nullable', 'array', 'max:100'],
            'changes.*.id' => ['nullable', 'integer'],
            'changes.*.description' => ['required', 'string', 'max:2000'],
        ]);

        $duplicate = HelpRelease::query()
            ->where('major_version', $data['major_version'])
            ->where('month_number', $data['month_number'])
            ->where('week_of_month', $data['week_of_month'])
            ->when($release !== null, fn ($query) => $query->whereKeyNot($release?->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['week_of_month' => __('help.releases.duplicate')]);
        }

        return [
            'major_version' => (int) $data['major_version'],
            'month_number' => (int) $data['month_number'],
            'week_of_month' => (int) $data['week_of_month'],
            'summary' => trim((string) ($data['summary'] ?? '')),
            'is_hidden' => array_key_exists('is_hidden', $data) && $data['is_hidden'] !== null ? (bool) $data['is_hidden'] : null,
            'changes' => array_key_exists('changes', $data) && is_array($data['changes'])
                ? array_values(array_map(fn (array $change): array => [
                    'id' => isset($change['id']) ? (int) $change['id'] : null,
                    'description' => trim((string) $change['description']),
                ], $data['changes']))
                : null,
        ];
    }

    /**
     * Guarda la versión y, si llegan, sus cambios en ese orden: los que ya existen se actualizan,
     * los nuevos se crean y los que faltan se borran («reordenar cambios», F-150).
     *
     * @param  array{major_version: int, month_number: int, week_of_month: int, summary: string, is_hidden: bool|null, changes: list<array{id: int|null, description: string}>|null}  $data
     */
    private function saveRelease(HelpRelease $release, array $data): void
    {
        DB::transaction(function () use ($release, $data): void {
            $release->fill([
                'major_version' => $data['major_version'],
                'month_number' => $data['month_number'],
                'week_of_month' => $data['week_of_month'],
                'summary' => $data['summary'],
            ]);

            if ($data['is_hidden'] !== null) {
                $release->is_hidden = $data['is_hidden'];
            }

            $release->save();

            if ($data['changes'] === null) {
                return;
            }

            $existing = $release->changes()->get()->keyBy('id');
            $kept = [];

            foreach ($data['changes'] as $position => $change) {
                $model = $change['id'] !== null ? $existing->get($change['id']) : null;

                if ($model !== null) {
                    $model->update(['description' => $change['description'], 'position' => $position + 1]);
                    $kept[] = $model->id;
                } else {
                    $kept[] = $release->changes()->create(['description' => $change['description'], 'position' => $position + 1])->id;
                }
            }

            $release->changes()->whereNotIn('id', $kept)->delete();
        });
    }

    /**
     * @return array{published_on: string, title: string, subtitle: string, body: string}
     */
    private function validateUpdate(Request $request): array
    {
        $data = $request->validate([
            'published_on' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:200'],
            'subtitle' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:'.RichText::MAX_LENGTH],
        ]);

        return [
            'published_on' => (string) $data['published_on'],
            'title' => trim((string) $data['title']),
            'subtitle' => trim((string) $data['subtitle']),
            'body' => (string) RichText::sanitize($data['body'] ?? null),
        ];
    }

    /**
     * @return array{title: string, description: string|null, help_release_id: int|null, upload: string|null}
     */
    private function validateTutorial(Request $request, bool $required): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'help_release_id' => ['nullable', 'integer', 'exists:help_releases,id'],
            'upload' => [$required ? 'required' : 'nullable', 'string', 'uuid'],
        ], [
            'upload.required' => __('help.tutorials.video_required'),
        ]);

        $description = trim((string) ($data['description'] ?? ''));

        return [
            'title' => trim((string) $data['title']),
            'description' => $description === '' ? null : $description,
            'help_release_id' => isset($data['help_release_id']) ? (int) $data['help_release_id'] : null,
            'upload' => isset($data['upload']) ? (string) $data['upload'] : null,
        ];
    }

    /**
     * @return array{help_faq_section_id: int, question: string, answer: string}
     */
    private function validateFaq(Request $request): array
    {
        $data = $request->validate([
            'help_faq_section_id' => ['required', 'integer', 'exists:help_faq_sections,id'],
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:'.RichText::MAX_LENGTH],
        ]);

        $answer = RichText::sanitize((string) $data['answer']);

        if ($answer === null) {
            throw ValidationException::withMessages(['answer' => __('help.faq.answer_required')]);
        }

        return [
            'help_faq_section_id' => (int) $data['help_faq_section_id'],
            'question' => trim((string) $data['question']),
            'answer' => $answer,
        ];
    }
}
