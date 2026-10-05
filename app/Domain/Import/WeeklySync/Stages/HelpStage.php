<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncDump;
use App\Domain\Import\WeeklySync\WeeklySyncFiles;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Domain\Import\WeeklySync\WeeklySyncText;
use App\Domain\Weeklies\Help\HelpCenter;
use App\Domain\Weeklies\Help\TutorialVideoUploads;
use App\Models\Attachment;
use App\Models\HelpFaq;
use App\Models\HelpFaqSection;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpReleaseChange;
use App\Models\HelpTutorial;
use App\Models\HelpUpdateLike;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Centro de ayuda (D-149 y D-218): manual y soporte, novedades (versiones con sus cambios,
 * actualizaciones puntuales y «me gusta»), tutoriales con su vídeo y preguntas frecuentes por
 * secciones. Los textos en Markdown pasan a HTML saneado (RichText). Lo que ya se haya escrito en
 * Audax (el resumen de una versión, el manual, el enlace de soporte o el vídeo de un tutorial
 * subido aquí) no se sobrescribe.
 */
final class HelpStage
{
    public const string MANUAL_PATH = 'help/manual/weeklysync-manual.pdf';

    public function run(WeeklySyncContext $context): void
    {
        DB::transaction(function () use ($context): void {
            $this->settings($context);
            $this->releases($context);
            $this->manualUpdates($context);
            $this->likes($context);
        });

        DB::transaction(function () use ($context): void {
            $this->tutorials($context);
        });

        DB::transaction(function () use ($context): void {
            $this->faqs($context);
        });
    }

    private function settings(WeeklySyncContext $context): void
    {
        $row = $context->rows('help_settings')[0] ?? null;

        if ($row === null) {
            return;
        }

        $changed = false;
        $url = WeeklySyncContext::nullableStr($row['support_url'] ?? null);
        $current = Setting::get('help_support_url');

        if ($url !== null && preg_match('#^(https?://|mailto:)#i', $url) === 1 && ($current === null || $current === '')) {
            Setting::set('help_support_url', Str::limit($url, 500, ''));
            $changed = true;
        }

        $source = WeeklySyncDump::storagePath($row['manual_path'] ?? null, WeeklySyncDump::HELP_BUCKET);
        $manual = Setting::get('help_manual');
        $ours = ! is_array($manual) || ($manual['path'] ?? null) === self::MANUAL_PATH;

        if ($source !== null && $ours && ($copy = $context->files->copy(WeeklySyncDump::HELP_BUCKET, $source, self::MANUAL_PATH)) !== null) {
            $name = WeeklySyncContext::nullableStr($row['manual_file_name'] ?? null) ?? basename($source);
            $value = ['disk' => $copy['disk'], 'path' => $copy['path'], 'name' => Str::limit($name, 200, ''), 'size' => $copy['size']];

            if ($manual !== $value) {
                Setting::set('help_manual', $value);
                $changed = true;
            }
        }

        $context->report->count('help_settings', $changed ? Report::UPDATED : Report::UNCHANGED);
    }

    private function releases(WeeklySyncContext $context): void
    {
        $imported = array_flip($context->refs->all('release'));

        foreach ($context->rows('help_releases') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $key = [
                'major_version' => (int) ($row['major_version'] ?? 1),
                'month_number' => (int) ($row['month_number'] ?? 1),
                'week_of_month' => (int) ($row['week_of_month'] ?? 1),
            ];

            $local = $context->refs->find('release', $id);
            $release = $local !== null ? HelpRelease::query()->find($local) : null;
            $release ??= HelpRelease::query()->where($key)->first();
            $created = $release === null;
            $release ??= new HelpRelease;

            $release->fill($key + ['is_hidden' => ($row['is_hidden'] ?? false) === true]);
            $summary = WeeklySyncText::plain($row['summary'] ?? '');
            $ownSummary = ! $created && ! isset($imported[$release->id])
                && trim((string) $release->summary) !== '' && $release->summary !== HelpCenter::defaultSummary();

            if (! $ownSummary) {
                $release->summary = $summary;
            }

            $this->save($context, 'help_releases', $release, $created, $row);
            $context->refs->put('release', $id, 'help_release', $release->id);
            $context->releases[$id] = $release->id;
        }

        foreach ($context->rows('help_release_changes') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $release = $context->releases[WeeklySyncContext::id($row['release_id'] ?? null)] ?? null;
            $description = WeeklySyncText::plain($row['description'] ?? '');

            if ($release === null || $description === '') {
                $context->report->skip('help_release_changes', 'Cambios sin versión o vacíos');

                continue;
            }

            $local = $context->refs->find('release_change', $id);
            $change = $local !== null ? HelpReleaseChange::query()->find($local) : null;
            $created = $change === null;
            $change ??= new HelpReleaseChange;
            $change->fill(['help_release_id' => $release, 'description' => $description, 'position' => (int) ($row['sort_order'] ?? 0)]);

            $this->save($context, 'help_release_changes', $change, $created, $row);
            $context->refs->put('release_change', $id, 'help_release_change', $change->id);
        }
    }

    private function manualUpdates(WeeklySyncContext $context): void
    {
        foreach ($context->rows('help_manual_updates') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $local = $context->refs->find('manual_update', $id);
            $update = $local !== null ? HelpManualUpdate::query()->find($local) : null;
            $created = $update === null;
            $update ??= new HelpManualUpdate;

            $update->fill([
                'published_on' => WeeklySyncContext::date($row['published_on'] ?? null) ?? WeeklySyncContext::instant($row['created_at'] ?? null)?->toDateString() ?? now()->toDateString(),
                'title' => WeeklySyncText::plain($row['title'] ?? '', 255),
                'subtitle' => WeeklySyncText::plain($row['subtitle'] ?? '', 255),
                'body' => WeeklySyncText::rich(WeeklySyncContext::str($row['content_markdown'] ?? '')) ?? '',
                'created_by' => $context->user($row['created_by'] ?? null),
            ]);

            $this->save($context, 'help_manual_updates', $update, $created, $row);
            $context->refs->put('manual_update', $id, 'help_manual_update', $update->id);
            $context->manualUpdates[$id] = $update->id;
        }
    }

    private function likes(WeeklySyncContext $context): void
    {
        foreach ($context->rows('help_update_likes') as $row) {
            $user = $context->user($row['user_id'] ?? null);
            $release = $context->releases[WeeklySyncContext::id($row['release_id'] ?? null)] ?? null;
            $update = $context->manualUpdates[WeeklySyncContext::id($row['manual_update_id'] ?? null)] ?? null;
            $likeable = $release !== null ? new HelpRelease : ($update !== null ? new HelpManualUpdate : null);

            if ($user === null || $likeable === null) {
                $context->report->skip('help_likes', '«Me gusta» de personas o novedades que no se importan');

                continue;
            }

            $like = HelpUpdateLike::query()->firstOrNew([
                'likeable_type' => $likeable->getMorphClass(),
                'likeable_id' => $release ?? $update,
                'user_id' => $user,
            ]);

            if ($like->exists) {
                $context->report->count('help_likes', Report::UNCHANGED);

                continue;
            }

            if (($at = WeeklySyncContext::instant($row['created_at'] ?? null)) !== null) {
                $like->created_at = $at;
            }
            $like->save();
            $context->report->count('help_likes', Report::CREATED);
        }
    }

    private function tutorials(WeeklySyncContext $context): void
    {
        foreach ($context->rows('help_tutorials') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $local = $context->refs->find('tutorial', $id);
            $tutorial = $local !== null ? HelpTutorial::query()->find($local) : null;
            $created = $tutorial === null;
            $tutorial ??= new HelpTutorial;

            $description = WeeklySyncText::plain($row['description'] ?? '');
            $tutorial->fill([
                'title' => WeeklySyncText::plain($row['title'] ?? '', 255) ?: 'Tutorial',
                'description' => $description !== '' ? $description : null,
                'help_release_id' => $context->releases[WeeklySyncContext::id($row['release_id'] ?? null)] ?? null,
                'position' => (int) ($row['sort_order'] ?? 0),
            ]);

            $dirty = $created || $tutorial->isDirty();
            $this->save($context, null, $tutorial, $created, $row);
            $context->refs->put('tutorial', $id, 'help_tutorial', $tutorial->id);

            $videoChanged = $this->video($context, $tutorial, $id, $row);

            $context->report->count('help_tutorials', match (true) {
                $created => Report::CREATED,
                $dirty || $videoChanged => Report::UPDATED,
                default => Report::UNCHANGED,
            });
        }
    }

    /**
     * El vídeo del tutorial (F-155): un Attachment en help/tutorials, como los subidos en Audax.
     *
     * @param  array<string, mixed>  $row
     */
    private function video(WeeklySyncContext $context, HelpTutorial $tutorial, string $id, array $row): bool
    {
        $source = WeeklySyncDump::storagePath($row['video_path'] ?? null, WeeklySyncDump::HELP_BUCKET);

        if ($source === null) {
            return false;
        }

        $local = $context->refs->find('tutorial_video', $id);
        $attachment = $local !== null ? Attachment::query()->find($local) : null;
        $current = $tutorial->exists ? $tutorial->video()->first() : null;

        if ($current !== null && $current->id !== $attachment?->id) {
            $context->report->warn("El tutorial «{$tutorial->title}» ya tiene un vídeo subido en Audax: se conserva.");

            return false;
        }

        $extension = WeeklySyncFiles::extension($source, 'mp4');
        $copy = $context->files->copy(WeeklySyncDump::HELP_BUCKET, $source, TutorialVideoUploads::VIDEOS."/weeklysync-{$id}.{$extension}");

        if ($copy === null) {
            return false;
        }

        if (! isset(TutorialVideoUploads::MIMES[$copy['mime']])) {
            $context->report->warn("El vídeo del tutorial «{$tutorial->title}» no es un vídeo que se pueda reproducir ({$copy['mime']}): no se adjunta.");

            return false;
        }

        $created = $attachment === null;
        $attachment ??= new Attachment;
        $attachment->fill([
            'project_id' => null,
            'user_id' => null,
            'disk' => $copy['disk'],
            'path' => $copy['path'],
            'original_name' => Str::limit(WeeklySyncContext::nullableStr($row['video_file_name'] ?? null) ?? basename($source), 255, ''),
            'mime' => $copy['mime'],
            'size' => $copy['size'],
            'thumbnail_path' => null,
        ]);
        $attachment->attachable()->associate($tutorial);

        if (! $created && ! $attachment->isDirty()) {
            return false;
        }

        $attachment->save();
        $context->refs->put('tutorial_video', $id, 'attachment', $attachment->id);

        return true;
    }

    private function faqs(WeeklySyncContext $context): void
    {
        $sections = [];

        foreach ($context->rows('help_faq_sections') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $local = $context->refs->find('faq_section', $id);
            $section = $local !== null ? HelpFaqSection::query()->find($local) : null;
            $created = $section === null;
            $section ??= new HelpFaqSection;
            $section->fill(['name' => WeeklySyncText::plain($row['name'] ?? '', 255) ?: 'General', 'position' => (int) ($row['sort_order'] ?? 0)]);

            $this->save($context, 'help_faq_sections', $section, $created, $row);
            $context->refs->put('faq_section', $id, 'help_faq_section', $section->id);
            $sections[$id] = $section->id;
        }

        foreach ($context->rows('help_faqs') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $question = WeeklySyncText::plain($row['question'] ?? '');
            $answer = WeeklySyncText::rich(WeeklySyncContext::str($row['answer'] ?? ''));

            if ($question === '' || $answer === null) {
                $context->report->skip('help_faqs', 'Preguntas sin pregunta o sin respuesta');

                continue;
            }

            $section = $sections[WeeklySyncContext::id($row['section_id'] ?? null)] ?? null;
            $section ??= HelpFaqSection::query()->firstOrCreate(['name' => 'General'], ['position' => 0])->id;

            $local = $context->refs->find('faq', $id);
            $faq = $local !== null ? HelpFaq::query()->find($local) : null;
            $created = $faq === null;
            $faq ??= new HelpFaq;
            $faq->fill([
                'help_faq_section_id' => $section,
                'question' => $question,
                'answer' => $answer,
                'position' => (int) ($row['sort_order'] ?? 0),
            ]);

            $this->save($context, 'help_faqs', $faq, $created, $row);
            $context->refs->put('faq', $id, 'help_faq', $faq->id);
        }
    }

    /**
     * Guarda si hace falta (con la fecha de creación de WeeklySync al crear) y cuenta.
     *
     * @param  array<string, mixed>  $row
     */
    private function save(WeeklySyncContext $context, ?string $type, Model $model, bool $created, array $row): void
    {
        if ($created && ($at = WeeklySyncContext::instant($row['created_at'] ?? null)) !== null) {
            $model->setAttribute('created_at', $at);
        }

        $outcome = $created ? Report::CREATED : ($model->isDirty() ? Report::UPDATED : Report::UNCHANGED);

        if ($outcome !== Report::UNCHANGED) {
            $model->save();
        }

        if ($type !== null) {
            $context->report->count($type, $outcome);
        }
    }
}
