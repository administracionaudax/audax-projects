<?php

namespace App\Domain\Weeklies\Audio;

use App\Domain\Weeklies\Ai\GoogleTtsSynthesizer;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Ai\SpeechFailed;
use App\Domain\Weeklies\Ai\SpeechRequest;
use App\Domain\Weeklies\Ai\SpeechSynthesizer;
use App\Enums\AiFeature;
use App\Enums\WeeklyJobState;
use App\Models\User;
use App\Models\WeeklyAudioSection;
use App\Models\WeeklyCycle;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * El audio del informe (F-084 a F-087, D-146 y D-190), como `ws:App.tsx` handleGenerateReportAudio:
 * el guion por secciones (WeeklyAudioScripts), cada sección locutada aparte (SpeechSynthesizer:
 * Google TTS en trozos de 4.500 bytes, MP3) y, además, el audio completo con todas unidas, para el
 * reproductor principal y la descarga. Todo en el disco privado (`local`): el navegador lo recibe por
 * rutas con permiso (la sección, con URL firmada).
 *
 * Las secciones nuevas sustituyen a las anteriores solo cuando todas están locutadas: si algo falla,
 * el audio que había se conserva. Los MP3 anteriores se borran al final.
 */
final class WeeklyAudioGenerator
{
    public const string DISK = 'local';

    public function __construct(
        private readonly WeeklyAudioScripts $scripts,
        private readonly SpeechSynthesizer $speech,
    ) {}

    /**
     * @param  (Closure(int, int, string): void)|null  $progress  (hechos, total, paso: scripts|speech)
     *
     * @throws LlmException
     */
    public function generate(WeeklyCycle $cycle, ?User $user = null, ?Closure $progress = null): void
    {
        $report = $cycle->reportData() ?? throw new RuntimeException('La semana no tiene informe.');
        $sections = $this->scripts->build($cycle, $report, $cycle->report_text, $user);
        $total = count($sections);
        $disk = Storage::disk(self::DISK);
        $written = [];
        $rows = [];
        $audio = [];

        if ($progress !== null) {
            $progress(0, $total, 'speech');
        }

        try {
            foreach ($sections as $position => $section) {
                try {
                    $speech = $this->speech->synthesize(new SpeechRequest(
                        text: $section['script'],
                        user: $user,
                        subject: $cycle,
                        feature: AiFeature::Speech,
                        operation: 'weekly_section',
                        metadata: ['weekly_cycle_id' => $cycle->id, 'section' => $section['key']],
                    ));
                } catch (LlmException $e) {
                    throw SpeechFailed::from($e);
                }
                $path = "weeklies/{$cycle->id}/audio/{$section['key']}-".Str::lower((string) Str::ulid()).'.mp3';
                $disk->put($path, $speech->audio);
                $written[] = $path;
                $audio[] = $speech->audio;
                $rows[] = [
                    'key' => $section['key'],
                    'kind' => $section['kind'],
                    'client_id' => $section['client_id'],
                    'position' => $position,
                    'script' => $section['script'],
                    'disk' => self::DISK,
                    'path' => $path,
                    'mime' => $speech->mime,
                    'size' => $speech->size(),
                    'duration_ms' => Mp3Duration::milliseconds($speech->audio),
                    'voice' => $speech->voice,
                    'generated_at' => now(),
                ];

                if ($progress !== null) {
                    $progress($position + 1, $total, 'speech');
                }
            }

            $full = "weeklies/{$cycle->id}/audio/weekly-".Str::lower((string) Str::ulid()).'.mp3';
            $disk->put($full, GoogleTtsSynthesizer::mergeMp3($audio));
            $written[] = $full;
        } catch (\Throwable $e) {
            $disk->delete($written);

            throw $e;
        }

        $old = $cycle->audioSections()->get(['disk', 'path'])
            ->map(fn (WeeklyAudioSection $section): array => [$section->disk, $section->path])
            ->push([$cycle->audio_disk, $cycle->audio_path])
            ->filter(fn (array $file): bool => $file[0] !== null && $file[1] !== null)
            ->values()
            ->all();

        DB::transaction(function () use ($cycle, $rows, $full, $user): void {
            $cycle->audioSections()->delete();

            foreach ($rows as $row) {
                $cycle->audioSections()->create($row);
            }

            $cycle->forceFill([
                'audio_disk' => self::DISK,
                'audio_path' => $full,
                'audio_state' => WeeklyJobState::Done,
                'audio_error' => null,
                'audio_generated_at' => now(),
                'audio_generated_by' => $user?->id,
            ])->save();
        });

        foreach ($old as [$oldDisk, $oldPath]) {
            Storage::disk((string) $oldDisk)->delete((string) $oldPath);
        }
    }
}
