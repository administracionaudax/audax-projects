<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\Dictation;
use App\Models\User;

/**
 * Tus dictados (Fase 10, D-152): para qué eran, su estado, la transcripción literal, el texto limpio
 * y el aviso (sin voz, demasiado corto). El audio nunca se guarda: se borra al transcribirlo.
 */
final class DictationsSection extends Section
{
    public function key(): string
    {
        return 'dictados';
    }

    protected function textKey(): string
    {
        return 'dictations';
    }

    protected function columnKeys(): array
    {
        return ['id', 'context', 'week', 'client', 'status', 'raw_text', 'text', 'warning', 'audio_duration_ms', 'created_at', 'transcribed_at'];
    }

    public function rows(User $user): iterable
    {
        $dictations = Dictation::query()
            ->where('user_id', $user->id)
            ->with(['cycle:id,number', 'client:id,name'])
            ->orderBy('id')
            ->get();

        foreach ($dictations as $dictation) {
            yield [
                'id' => $dictation->id,
                'context' => $dictation->context->label(),
                'week' => $dictation->cycle?->number,
                'client' => $dictation->client?->name,
                'status' => self::text("privacy.export.weeklies.dictation_status.{$dictation->status->value}"),
                'raw_text' => $dictation->raw_text,
                'text' => $dictation->text,
                'warning' => $dictation->warning,
                'audio_duration_ms' => $dictation->audio_duration_ms,
                'created_at' => self::instant($dictation->created_at),
                'transcribed_at' => self::instant($dictation->transcribed_at),
            ];
        }
    }
}
