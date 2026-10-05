<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Models\AiUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Histórico de uso de IA (`ai_usage_events` → `ai_usage`, D-220), para que «Uso de IA» enseñe
 * también el gasto de WeeklySync. Cada función de WeeklySync va a la función de Audax que la
 * sustituye; las que ya no existen (el OCR del estado de proyectos y de las bolsas, D-148) no entran
 * y su coste sale en el informe.
 */
final class AiUsageStage
{
    public const int CHUNK = 500;

    /**
     * `feature` de WeeklySync → función de Audax (por prefijo, en este orden).
     *
     * @var array<string, AiFeature>
     */
    private const array FEATURES = [
        'weekly_report_' => AiFeature::WeeklyReport,
        'close_week_satisfaction' => AiFeature::Satisfaction,
        'client_satisfaction' => AiFeature::Satisfaction,
        'weekly_audio_script' => AiFeature::AudioScript,
        'audio_summary_script' => AiFeature::AudioScript,
        'weekly_audio_tts' => AiFeature::Speech,
        'audio_summary_tts' => AiFeature::Speech,
        'audio_transcription' => AiFeature::TranscriptCleanup,
        'task_extraction' => AiFeature::SuggestedTasks,
        'client_summary' => AiFeature::ClientSummary,
        'team_activity' => AiFeature::TeamActivity,
        'performance_summary' => AiFeature::PersonPerformance,
        'user_client_activity' => AiFeature::PersonClientActivity,
        'knowledge_base' => AiFeature::Assistant,
    ];

    public function run(WeeklySyncContext $context): void
    {
        $rows = $context->rows('ai_usage_events');
        $existing = $context->refs->all('ai_usage');
        $dropped = '0';

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::transaction(function () use ($context, $chunk, $existing, &$dropped): void {
                foreach ($chunk as $row) {
                    $id = WeeklySyncContext::id($row['id'] ?? null);
                    $feature = self::feature(WeeklySyncContext::str($row['feature'] ?? ''));

                    if ($feature === null) {
                        $context->report->skip('ai_usage', 'Uso de IA de funciones que ya no existen (OCR)');
                        $cost = $row['estimated_cost_usd'] ?? null;
                        $dropped = bcadd($dropped, is_numeric($cost) ? number_format((float) $cost, 6, '.', '') : '0', 6);

                        continue;
                    }

                    if (isset($existing[$id])) {
                        // El histórico no cambia: lo importado una vez se queda como está.
                        $context->report->count('ai_usage', Report::UNCHANGED);

                        continue;
                    }

                    $usage = new AiUsage([
                        'user_id' => $context->user($row['user_id'] ?? null),
                        'provider' => self::provider(WeeklySyncContext::str($row['provider'] ?? '')),
                        'model' => Str::limit(WeeklySyncContext::str($row['model'] ?? '') ?: 'desconocido', 64, ''),
                        'feature' => $feature,
                        'operation' => Str::limit(WeeklySyncContext::str($row['function_name'] ?? '') ?: WeeklySyncContext::str($row['feature'] ?? ''), 64, ''),
                        'status' => WeeklySyncContext::str($row['status'] ?? '') === 'error' ? AiUsage::STATUS_ERROR : AiUsage::STATUS_SUCCESS,
                        'latency_ms' => self::int($row['latency_ms'] ?? null),
                        'prompt_tokens' => self::int($row['prompt_tokens'] ?? null),
                        'response_tokens' => self::int($row['response_tokens'] ?? null),
                        'total_tokens' => self::int($row['total_tokens'] ?? null),
                        'character_count' => self::int($row['character_count'] ?? null),
                        'estimated_cost_usd' => is_numeric($row['estimated_cost_usd'] ?? null) ? number_format((float) $row['estimated_cost_usd'], 6, '.', '') : null,
                        'metadata' => ['source' => 'weeklysync', 'feature' => WeeklySyncContext::str($row['feature'] ?? '')],
                    ]);
                    $usage->created_at = WeeklySyncContext::instant($row['created_at'] ?? null) ?? now()->toImmutable();
                    $usage->save();

                    $context->refs->put('ai_usage', $id, 'ai_usage', (int) $usage->id);
                    $context->report->count('ai_usage', Report::CREATED);
                }
            });
        }

        if (bccomp($dropped, '0', 6) > 0) {
            $context->report->warn("Coste de IA de WeeklySync que no entra en «Uso de IA» (funciones de OCR que ya no existen): {$dropped} USD.");
        }
    }

    public static function feature(string $feature): ?AiFeature
    {
        foreach (self::FEATURES as $prefix => $local) {
            if (str_starts_with($feature, $prefix)) {
                return $local;
            }
        }

        return null;
    }

    private static function provider(string $provider): AiProvider
    {
        return str_contains(strtolower($provider), 'tts') ? AiProvider::GoogleTts : AiProvider::Gemini;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) round((float) $value)) : null;
    }
}
