<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tu uso de la IA (Fase 10, D-225): cada llamada a Gemini o a Google TTS que pediste tú y las que
 * trataron sobre ti (los resúmenes de tu desempeño o de tu actividad por cliente), con la fecha, la
 * función, el modelo y el estado. Nunca hay textos: ai_usage no guarda ni el prompt ni la respuesta.
 */
final class AiUsageSection extends Section
{
    public function key(): string
    {
        return 'uso-ia';
    }

    protected function textKey(): string
    {
        return 'ai_usage';
    }

    protected function columnKeys(): array
    {
        return ['id', 'relation', 'feature', 'operation', 'provider', 'model', 'status', 'subject', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        $morph = $user->getMorphClass();
        $usage = AiUsage::query()
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)
                ->orWhere(fn (Builder $about) => $about->where('subject_type', $morph)->where('subject_id', $user->id)))
            ->orderBy('id')
            ->lazyById(500);

        foreach ($usage as $row) {
            $mine = $row->user_id === $user->id;
            $about = $row->subject_type === $morph && (int) $row->subject_id === $user->id;
            $feature = AiFeature::tryFrom((string) $row->getRawOriginal('feature'));

            yield [
                'id' => $row->id,
                'relation' => self::text('privacy.export.weeklies.ai_usage_relation.'.($mine && $about ? 'both' : ($mine ? 'mine' : 'about'))),
                'feature' => $feature?->label() ?? (string) $row->getRawOriginal('feature'),
                'operation' => $row->operation,
                'provider' => (string) $row->getRawOriginal('provider'),
                'model' => $row->model,
                'status' => $row->status,
                'subject' => $row->subject_type === null ? null : $row->subject_type.' #'.$row->subject_id,
                'created_at' => self::instant($row->created_at),
            ];
        }
    }
}
