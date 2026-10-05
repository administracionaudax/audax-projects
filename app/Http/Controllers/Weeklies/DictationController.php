<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\Dictation\DictationText;
use App\Enums\DictationContext;
use App\Enums\TranscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\StoreDictationRequest;
use App\Http\Resources\Weeklies\DictationResource;
use App\Jobs\TranscribeDictation;
use App\Models\Dictation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Dictado de la weekly (F-049, F-050, F-171 y F-172, D-152): sube el audio grabado en el navegador,
 * que se transcribe con el Whisper del servidor en la cola `transcriptions` (TranscribeDictation), y
 * consulta su estado mientras la interfaz muestra «Transcribiendo…» (sondeo).
 *
 * Un audio demasiado corto (menos de 0,7 s o de 1,5 KB, F-050) no se transcribe: queda hecho al
 * momento, sin texto y con el aviso too_short. El audio se guarda en el disco local (privado) solo
 * hasta que se transcribe.
 */
class DictationController extends Controller
{
    public const string DISK = 'local';

    public function store(StoreDictationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $audio = $request->audioFile();
        $duration = $request->integer('duration_ms');
        $size = (int) $audio?->getSize();

        $dictation = new Dictation([
            'user_id' => $user->id,
            'context' => DictationContext::WeeklyEntry,
            'weekly_cycle_id' => $request->integer('weekly_cycle_id'),
            'client_id' => $request->filled('client_id') ? $request->integer('client_id') : null,
            'mime' => $audio?->getMimeType(),
            'size' => $size,
            'audio_duration_ms' => $duration,
        ]);

        if ($audio === null || DictationText::isTooShort($duration, $size)) {
            $dictation->forceFill([
                'status' => TranscriptionStatus::Done,
                'text' => '',
                'warning' => DictationText::WARNING_TOO_SHORT,
                'transcribed_at' => now(),
            ])->save();

            return response()->json(['dictation' => DictationResource::make($dictation)], 201);
        }

        $extension = mb_strtolower($audio->getClientOriginalExtension() ?: 'webm');
        $path = $audio->storeAs('dictations/'.$user->id, Str::uuid()->toString().'.'.$extension, self::DISK);

        $dictation->forceFill(['disk' => self::DISK, 'path' => $path ?: null])->save();

        TranscribeDictation::dispatch($dictation->id);

        return response()->json(['dictation' => DictationResource::make($dictation->fresh() ?? $dictation)], 201);
    }

    public function show(Dictation $dictation): JsonResponse
    {
        Gate::authorize('view', $dictation);

        return response()->json(['dictation' => DictationResource::make($dictation)]);
    }
}
