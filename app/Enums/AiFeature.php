<?php

namespace App\Enums;

/**
 * Para qué se usa la IA (D-146, columna ai_usage.feature y página «Uso de IA»).
 */
enum AiFeature: string
{
    case WeeklyReport = 'weekly_report';
    case Satisfaction = 'satisfaction';
    case AudioScript = 'audio_script';
    case Speech = 'speech';
    case DictationTranscription = 'dictation_transcription';
    case TranscriptCleanup = 'transcript_cleanup';
    case SuggestedTasks = 'suggested_tasks';
    case ClientSummary = 'client_summary';
    case TeamActivity = 'team_activity';
    case PersonPerformance = 'person_performance';
    case PersonClientActivity = 'person_client_activity';
    case Assistant = 'assistant';

    public function label(): string
    {
        return __("weeklies.enums.ai_feature.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
