<?php

namespace App\Domain\Chat\Transcription;

use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Jobs\TranscribeAudioMessage;
use App\Models\Attachment;
use App\Models\AudioTranscription;
use App\Models\Message;
use App\Models\User;
use App\Notifications\Chat\TranscriptionsFailing;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Notification;

/**
 * Garantía de SPEC §12: TODO audio acaba con su transcripción.
 * - forAudio(): al crear el mensaje de audio se crea su transcripción (pending) y se encola,
 * - requeue(): la revisión cada 15 minutos vuelve a encolar las pendientes atascadas, las que se
 *   quedaron «transcribiendo» (worker caído o tiempo agotado) y las fallidas; tras MAX_ATTEMPTS
 *   intentos avisa al admin (una vez) y sigue reintentando como mucho cada hora hasta
 *   GIVE_UP_ATTEMPTS: a partir de ahí ya no se relanza sola (un audio que agota siempre el tiempo
 *   no ocupa para siempre el único proceso de transcripción) y solo el admin la relanza (D-116),
 * - backfill(): transcribe cualquier audio que no tenga texto (restauraciones, cambio de motor),
 * - retry(): el admin la relanza a mano.
 */
final class AudioTranscriptions
{
    public const int MAX_ATTEMPTS = 9;

    /**
     * Intentos tras los que la revisión deja de relanzarla sola (tres rondas más, de una hora,
     * después del aviso). El admin puede seguir relanzándola a mano.
     */
    public const int GIVE_UP_ATTEMPTS = 18;

    /**
     * @param  int|null  $declaredDurationMs  la que envía el navegador; el job la cambia por la que
     *                                        mide el transcriptor
     */
    public function forAudio(Message $message, Attachment $audio, ?int $declaredDurationMs = null): AudioTranscription
    {
        $transcription = AudioTranscription::query()->firstOrCreate(
            ['message_id' => $message->id],
            ['attachment_id' => $audio->id, 'status' => TranscriptionStatus::Pending, 'audio_duration_ms' => $declaredDurationMs],
        );

        $this->dispatch($transcription);

        return $transcription;
    }

    public function dispatch(AudioTranscription $transcription): void
    {
        $transcription->forceFill(['queued_at' => now()])->save();

        TranscribeAudioMessage::dispatch($transcription->id);
    }

    public function retry(AudioTranscription $transcription): void
    {
        if ($transcription->status === TranscriptionStatus::Done) {
            return;
        }

        $transcription->forceFill(['status' => TranscriptionStatus::Pending, 'admin_notified_at' => null])->save();
        $this->dispatch($transcription);
    }

    /**
     * @return array{requeued: int, notified: int}
     */
    public function requeue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $requeued = 0;
        $failing = [];

        $stale = AudioTranscription::query()
            ->where(function ($query) use ($now): void {
                $query->where(fn ($q) => $q->where('status', TranscriptionStatus::Pending)
                    ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', $now->subMinutes(15))))
                    ->orWhere(fn ($q) => $q->where('status', TranscriptionStatus::Processing)
                        ->where('started_at', '<', $now->subMinutes(50)))
                    ->orWhere(fn ($q) => $q->where('status', TranscriptionStatus::Failed)
                        ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', $now->subMinutes(15))));
            })
            ->orderBy('id')
            ->get();

        foreach ($stale as $transcription) {
            if ($transcription->attempts >= self::MAX_ATTEMPTS) {
                if ($transcription->admin_notified_at === null) {
                    $failing[] = $transcription;
                }

                if ($transcription->attempts >= self::GIVE_UP_ATTEMPTS) {
                    // Ya no se relanza sola: queda fallida (si se quedó «en curso», también) y
                    // espera a que el admin la relance desde /admin/transcripciones.
                    if ($transcription->status !== TranscriptionStatus::Failed) {
                        $transcription->forceFill([
                            'status' => TranscriptionStatus::Failed,
                            'last_error' => $transcription->last_error ?? __('chat_media.transcription.interrupted'),
                        ])->save();
                    }

                    continue;
                }

                // Tras agotar los intentos, se sigue probando, pero como mucho una vez por hora.
                if ($transcription->queued_at !== null && $transcription->queued_at->gt($now->subHour())) {
                    continue;
                }
            }

            // Fallida o interrumpida: ya no hay ningún job vivo para ella, pero si el worker se
            // cayó a mitad su candado de job único (uniqueFor, 2 h) seguiría puesto y el nuevo
            // intento no llegaría a la cola. Las pendientes, no: pueden estar esperando su turno.
            if ($transcription->status !== TranscriptionStatus::Pending) {
                (new UniqueLock(app(CacheRepository::class)))->release(new TranscribeAudioMessage($transcription->id));
            }

            $this->dispatch($transcription);
            $requeued++;
        }

        if ($failing !== []) {
            $admins = User::role('admin')->where('is_active', true)->get();
            Notification::send($admins, new TranscriptionsFailing(count($failing)));
            AudioTranscription::query()->whereKey(array_map(fn (AudioTranscription $t) => $t->id, $failing))
                ->update(['admin_notified_at' => $now]);
        }

        return ['requeued' => $requeued, 'notified' => count($failing)];
    }

    /**
     * @return int transcripciones encoladas
     */
    public function backfill(bool $force = false): int
    {
        $count = 0;

        Message::query()->where('type', MessageType::Audio)
            ->whereDoesntHave('transcription')
            ->with(['attachments' => fn ($query) => $query->orderBy('id')])
            ->chunkById(200, function ($messages) use (&$count): void {
                foreach ($messages as $message) {
                    $audio = $message->attachments->first();
                    if ($audio !== null) {
                        $this->forAudio($message, $audio);
                        $count++;
                    }
                }
            });

        $pending = AudioTranscription::query()->when(! $force, fn ($query) => $query->where('status', '!=', TranscriptionStatus::Done));
        foreach ($pending->orderBy('id')->lazyById(200) as $transcription) {
            if ($force) {
                $transcription->forceFill(['status' => TranscriptionStatus::Pending])->save();
            }
            $this->dispatch($transcription);
            $count++;
        }

        return $count;
    }
}
