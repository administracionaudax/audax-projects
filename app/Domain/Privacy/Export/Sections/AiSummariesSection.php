<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Enums\AiSummaryKind;
use App\Models\AiSummary;
use App\Models\Client;
use App\Models\User;

/**
 * Los resúmenes hechos con IA sobre ti (Fase 10, D-147 y D-194): tu desempeño, tu actividad por
 * cliente y, del análisis del equipo de cada cliente, la frase que habla de ti. Aunque tú no los
 * puedas ver en la app (solo el admin y tus responsables), son datos sobre ti y van aquí.
 */
final class AiSummariesSection extends Section
{
    public function key(): string
    {
        return 'resumenes-ia';
    }

    protected function textKey(): string
    {
        return 'ai_summaries';
    }

    protected function columnKeys(): array
    {
        return ['id', 'kind', 'about', 'content', 'model', 'generated_at'];
    }

    public function rows(User $user): iterable
    {
        $own = AiSummary::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->whereIn('kind', [AiSummaryKind::PersonPerformance->value, AiSummaryKind::PersonClientActivity->value])
            ->orderBy('id')
            ->get();

        // El análisis del equipo de un cliente guarda una frase por persona (id → frase).
        $teams = AiSummary::query()
            ->where('kind', AiSummaryKind::ClientTeamActivity->value)
            ->where('subject_type', (new Client)->getMorphClass())
            ->orderBy('id')
            ->get()
            ->filter(fn (AiSummary $summary): bool => self::sentenceFor($summary, $user->id) !== null);

        $clientIds = $teams->pluck('subject_id')->all();

        foreach ($own as $summary) {
            foreach (array_keys($summary->items ?? []) as $clientId) {
                $clientIds[] = (int) $clientId;
            }
        }

        /** @var array<int, string> $clients */
        $clients = $clientIds === [] ? [] : Client::query()->whereKey(array_unique($clientIds))->pluck('name', 'id')->all();

        foreach ($own as $summary) {
            $content = $summary->content;

            if ($summary->kind === AiSummaryKind::PersonClientActivity) {
                $lines = [];

                foreach ($summary->items ?? [] as $clientId => $sentence) {
                    $lines[] = ($clients[(int) $clientId] ?? '—').': '.$sentence;
                }

                $content = implode("\n", $lines);
            }

            yield [
                'id' => $summary->id,
                'kind' => self::text("privacy.export.weeklies.ai_kinds.{$summary->kind->value}"),
                'about' => null,
                'content' => $content,
                'model' => $summary->model,
                'generated_at' => self::instant($summary->generated_at),
            ];
        }

        foreach ($teams as $summary) {
            yield [
                'id' => $summary->id,
                'kind' => self::text("privacy.export.weeklies.ai_kinds.{$summary->kind->value}"),
                'about' => $clients[$summary->subject_id] ?? null,
                'content' => self::sentenceFor($summary, $user->id),
                'model' => $summary->model,
                'generated_at' => self::instant($summary->generated_at),
            ];
        }
    }

    /** La frase de un resumen con una por persona (items: id → frase) que habla de $userId. */
    private static function sentenceFor(AiSummary $summary, int $userId): ?string
    {
        foreach ($summary->items ?? [] as $id => $sentence) {
            if ((int) $id === $userId) {
                return $sentence;
            }
        }

        return null;
    }
}
