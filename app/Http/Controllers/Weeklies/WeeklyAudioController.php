<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\WeeklyJobProgress;
use App\Enums\WeeklyJobState;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateWeeklyAudio;
use App\Models\User;
use App\Models\WeeklyAudioSection;
use App\Models\WeeklyCycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Audio del informe (F-084 a F-087, D-190):
 * - generar o regenerar por secciones: encola GenerateWeeklyAudio (cola `ai`); hace falta el texto,
 * - cada sección (el reproductor de cada cliente) con URL firmada y permiso de ver la semana,
 * - el audio completo: en línea para el reproductor principal o, con ?descargar=1, como descarga.
 * Los MP3 están en el disco privado y se sirven con soporte de Range (saltar a un punto del audio),
 * como los audios del chat.
 */
class WeeklyAudioController extends Controller
{
    public function store(Request $request, WeeklyCycle $cycle): RedirectResponse
    {
        Gate::authorize('generate', $cycle);

        if ($cycle->report === null) {
            throw ValidationException::withMessages(['audio' => __('weeklies.errors.audio_needs_report')]);
        }

        if (WeeklyJobProgress::isRunning($cycle, WeeklyJobProgress::AUDIO)) {
            throw ValidationException::withMessages(['audio' => __('weeklies.audio.busy')]);
        }

        /** @var User $user */
        $user = $request->user();
        $cycle->forceFill(['audio_state' => WeeklyJobState::Queued, 'audio_error' => null])->save();
        WeeklyJobProgress::update($cycle, WeeklyJobProgress::AUDIO, WeeklyJobState::Queued);
        GenerateWeeklyAudio::dispatch($cycle->id, $user->id);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('weeklies.audio.queued')]);

        return back();
    }

    public function show(WeeklyCycle $cycle, WeeklyAudioSection $section): BinaryFileResponse
    {
        Gate::authorize('view', $cycle);
        abort_unless($section->weekly_cycle_id === $cycle->id && $section->disk !== null && $section->path !== null, 404);

        return $this->file($section->disk, $section->path, "weekly-{$cycle->number}-{$section->key}.mp3", inline: true);
    }

    public function download(Request $request, WeeklyCycle $cycle): BinaryFileResponse
    {
        Gate::authorize('view', $cycle);
        abort_if($cycle->audio_disk === null || $cycle->audio_path === null, 404);

        return $this->file($cycle->audio_disk, $cycle->audio_path, "Weekly-{$cycle->number}-Audio.mp3", inline: ! $request->boolean('descargar'));
    }

    private function file(string $disk, string $path, string $name, bool $inline): BinaryFileResponse
    {
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        $response = new BinaryFileResponse($storage->path($path), 200, [
            'Content-Type' => 'audio/mpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=3600',
        ], public: false);

        $response->setContentDisposition($inline ? 'inline' : 'attachment', $name, (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $name));

        return $response;
    }
}
