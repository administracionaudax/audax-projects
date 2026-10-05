<?php

namespace App\Domain\Weeklies\Help;

use App\Http\Controllers\Projects\ProjectController;
use App\Models\Attachment;
use App\Models\HelpFaq;
use App\Models\HelpFaqSection;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpReleaseChange;
use App\Models\HelpTutorial;
use App\Models\HelpUpdateLike;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Datos del centro de ayuda (F-148 a F-158) para la página /ayuda, una pestaña cada vez:
 * - General: el manual y el enlace de soporte (F-157) y las novedades (F-150 a F-153): versiones
 *   automáticas y actualizaciones a mano, con su estado y sus «me gusta»,
 * - Tutoriales (F-155): los vídeos en su orden, con su URL firmada,
 * - Preguntas frecuentes (F-156): secciones con sus preguntas.
 * Las versiones ocultas no salen en el listado (WeeklySync tampoco las enseñaba); quien gestiona
 * las ve en el selector de versiones para volver a mostrarlas.
 */
final class HelpCenter
{
    /** Horas que vale la URL firmada de un vídeo o del manual. */
    public const int SIGNED_HOURS = 6;

    /**
     * La versión de esta semana, si aún no existe (como `ensureCurrentAutomaticRelease` de
     * WeeklySync, al abrir la ayuda): una sola consulta, sin pisar una que ya esté.
     */
    public function ensureCurrentRelease(?CarbonImmutable $today = null): void
    {
        $version = HelpReleaseCalendar::versionFor($today ?? LocalTime::today());
        $now = now();

        HelpRelease::query()->insertOrIgnore([$version + [
            'summary' => self::defaultSummary(),
            'is_hidden' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);
    }

    public static function defaultSummary(): string
    {
        return (string) __('help.releases.default_summary');
    }

    /**
     * @return array{support_url: string|null, manual: array{name: string, size: int, url: string}|null}
     */
    public function settings(): array
    {
        $manual = Setting::get('help_manual');
        $url = Setting::get('help_support_url');

        return [
            'support_url' => is_string($url) && $url !== '' ? $url : null,
            'manual' => is_array($manual) && isset($manual['path'], $manual['name'])
                ? [
                    'name' => (string) $manual['name'],
                    'size' => (int) ($manual['size'] ?? 0),
                    'url' => URL::temporarySignedRoute('help.manual', now()->addHours(self::SIGNED_HOURS), absolute: false),
                ]
                : null,
        ];
    }

    /**
     * Las novedades, ordenadas y con su estado (F-150 a F-153). Cuatro consultas.
     *
     * @return list<array<string, mixed>>
     */
    public function updates(User $viewer, ?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $default = self::defaultSummary();

        $releases = HelpRelease::query()
            ->where('is_hidden', false)
            ->with('changes')
            ->orderByDesc('major_version')->orderByDesc('month_number')->orderByDesc('week_of_month')
            ->get();
        $manual = HelpManualUpdate::query()->orderByDesc('published_on')->orderByDesc('id')->get();
        $likes = $this->likes($releases, $manual);

        $entries = [];

        foreach ($releases as $release) {
            $changes = $release->changes;
            $hasContent = HelpReleaseCalendar::hasContent($release->summary, $changes->count(), $default);
            $label = HelpReleaseCalendar::label($release->major_version, $release->month_number, $release->week_of_month);
            $likers = $likes[$release->getMorphClass()][$release->id] ?? [];

            $entries[] = [
                'key' => "release:{$release->id}",
                'kind' => 'release',
                'id' => $release->id,
                'published_on' => HelpReleaseCalendar::publishedOn($release->major_version, $release->month_number, $release->week_of_month),
                'in_progress' => HelpReleaseCalendar::isInProgress($release->major_version, $release->month_number, $release->week_of_month, $hasContent, $today),
                'title' => __('help.releases.title', ['version' => $label]),
                'subtitle' => $this->releaseSubtitle($release->summary, $changes),
                'version' => $label,
                'release' => $this->releaseData($release),
                'manual' => null,
                'likes' => $likers,
                'liked_by_me' => in_array($viewer->id, array_column($likers, 'id'), true),
            ];
        }

        foreach ($manual as $update) {
            $likers = $likes[$update->getMorphClass()][$update->id] ?? [];

            $entries[] = [
                'key' => "update:{$update->id}",
                'kind' => 'manual',
                'id' => $update->id,
                'published_on' => $update->published_on->toDateString(),
                'in_progress' => false,
                'title' => $update->title,
                'subtitle' => $update->subtitle,
                'version' => null,
                'release' => null,
                'manual' => [
                    'id' => $update->id,
                    'published_on' => $update->published_on->toDateString(),
                    'title' => $update->title,
                    'subtitle' => $update->subtitle,
                    'body' => $update->body,
                ],
                'likes' => $likers,
                'liked_by_me' => in_array($viewer->id, array_column($likers, 'id'), true),
            ];
        }

        return array_map(function (array $entry): array {
            unset($entry['in_progress']);

            return $entry;
        }, HelpReleaseCalendar::withStatuses($entries));
    }

    /**
     * Versiones para elegir (tutoriales) y gestionar: todas, también las ocultas, la más nueva primero.
     *
     * @return list<array{id: int, version: string, month_number: int, week_of_month: int, major_version: int, is_hidden: bool}>
     */
    public function releaseOptions(): array
    {
        return array_values(HelpRelease::query()
            ->orderByDesc('major_version')->orderByDesc('month_number')->orderByDesc('week_of_month')
            ->get()
            ->map(fn (HelpRelease $release): array => [
                'id' => $release->id,
                'version' => $release->versionLabel(),
                'major_version' => $release->major_version,
                'month_number' => $release->month_number,
                'week_of_month' => $release->week_of_month,
                'is_hidden' => $release->is_hidden,
            ])
            ->all());
    }

    /**
     * Los tutoriales en su orden, con la versión y el vídeo (URL firmada). Dos consultas.
     *
     * @return list<array<string, mixed>>
     */
    public function tutorials(): array
    {
        return array_values(HelpTutorial::query()
            ->with(['release', 'video'])
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (HelpTutorial $tutorial): array => self::tutorialData($tutorial))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function tutorialData(HelpTutorial $tutorial): array
    {
        $video = $tutorial->video;

        return [
            'id' => $tutorial->id,
            'title' => $tutorial->title,
            'description' => $tutorial->description,
            'help_release_id' => $tutorial->help_release_id,
            'version' => $tutorial->release?->versionLabel(),
            'position' => $tutorial->position,
            'video_url' => $video instanceof Attachment
                ? URL::temporarySignedRoute('help.tutorials.video', now()->addHours(self::SIGNED_HOURS), ['tutorial' => $tutorial->id], absolute: false)
                : null,
            'video_name' => $video?->original_name,
            'video_size' => $video?->size,
            'video_mime' => $video?->mime,
            'created_at' => $tutorial->created_at?->toIso8601String(),
            'updated_at' => $tutorial->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Las secciones con sus preguntas, en su orden. Dos consultas.
     *
     * @return list<array<string, mixed>>
     */
    public function faqSections(): array
    {
        return array_values(HelpFaqSection::query()
            ->with('faqs')
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (HelpFaqSection $section): array => [
                'id' => $section->id,
                'name' => $section->name,
                'position' => $section->position,
                'faqs' => $section->faqs->map(fn (HelpFaq $faq): array => [
                    'id' => $faq->id,
                    'help_faq_section_id' => $faq->help_faq_section_id,
                    'question' => $faq->question,
                    'answer' => $faq->answer,
                    'position' => $faq->position,
                ])->values()->all(),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, HelpReleaseChange>  $changes
     */
    private function releaseSubtitle(string $summary, Collection $changes): string
    {
        if (trim($summary) !== '') {
            return trim($summary);
        }

        if ($changes->isEmpty()) {
            return (string) __('help.releases.in_progress_subtitle');
        }

        return $changes->take(2)->map(fn (HelpReleaseChange $change): string => trim($change->description))->implode(' · ');
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseData(HelpRelease $release): array
    {
        return [
            'id' => $release->id,
            'major_version' => $release->major_version,
            'month_number' => $release->month_number,
            'week_of_month' => $release->week_of_month,
            'version' => $release->versionLabel(),
            'summary' => $release->summary,
            'is_hidden' => $release->is_hidden,
            'changes' => $release->changes->map(fn (HelpReleaseChange $change): array => [
                'id' => $change->id,
                'description' => $change->description,
                'position' => $change->position,
            ])->values()->all(),
        ];
    }

    /**
     * Los «me gusta» de las novedades con quién los ha dado, en el orden en que se dieron. Dos consultas.
     *
     * @param  Collection<int, HelpRelease>  $releases
     * @param  Collection<int, HelpManualUpdate>  $manual
     * @return array<string, array<int, list<array{id: int, name: string, avatar: string|null}>>>
     */
    private function likes(Collection $releases, Collection $manual): array
    {
        if ($releases->isEmpty() && $manual->isEmpty()) {
            return [];
        }

        $releaseType = (new HelpRelease)->getMorphClass();
        $updateType = (new HelpManualUpdate)->getMorphClass();

        $rows = HelpUpdateLike::query()
            ->with(['user' => fn ($query) => $query->select(ProjectController::USER_SUMMARY_COLUMNS)])
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('likeable_type', $releaseType)->whereIn('likeable_id', $releases->modelKeys()))
                ->orWhere(fn ($q) => $q->where('likeable_type', $updateType)->whereIn('likeable_id', $manual->modelKeys())))
            ->orderBy('id')
            ->get();

        $likes = [];

        foreach ($rows as $row) {
            $likes[$row->likeable_type][$row->likeable_id][] = [
                'id' => $row->user->id,
                'name' => $row->user->name,
                'avatar' => $row->user->avatar_url,
            ];
        }

        return $likes;
    }
}
