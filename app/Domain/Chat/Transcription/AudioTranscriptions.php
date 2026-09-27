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
use Illuminate\Support\Facades\Notification;

/**
 * Garantía de SPEC §12: TODO audio acaba con su transcripción.
 * - forAudio(): al crear el mensaje de audio se crea su transcripción (pending) y se encola,
 * - requeue(): la revisión cada 15 minutos vuelve a encolar las pendientes atascadas, las que se
 *   quedaron «transcribiendo» (worker caído) y las fallidas; tras MAX_ATTEMPTS intentos avisa al
 *   admin (una vez) y sigue reintentando cada hora,
 * - backfill(): transcribe cualquier audio que no tenga texto (restauraciones, cambio de motor),
 * - retry(): el admin la relanza a mano.
 */
final class AudioTranscriptions
{
    public const int MAX_ATTEMPTS = 9;

    public function forAudio(Message $message, Attachment $audio): AudioTranscription
    {
        $transcription = AudioTranscription::query()->firstOrCreate(
            ['message_id' => $message->id],
            ['attachment_id' => $audio->id, 'status' => TranscriptionStatus::Pending],
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
                // Tras agotar los intentos, se sigue probando, pero como mucho una vez por hora.
                if ($transcription->queued_at !== null && $transcription->queued_at->gt($now->subHour())) {
                    continue;
                }
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
