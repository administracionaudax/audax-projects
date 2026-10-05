<?php

namespace App\Enums;

use App\Models\Client;
use App\Models\User;

/**
 * Resúmenes con IA que se guardan en ai_summaries (entrega 10.4, D-194): dos de la ficha de cliente y
 * dos de la ficha de persona. Cada uno tiene su función de «Uso de IA» (AiFeature).
 */
enum AiSummaryKind: string
{
    case ClientSummary = 'client_summary';
    case ClientTeamActivity = 'client_team_activity';
    case PersonPerformance = 'person_performance';
    case PersonClientActivity = 'person_client_activity';

    public function feature(): AiFeature
    {
        return match ($this) {
            self::ClientSummary => AiFeature::ClientSummary,
            self::ClientTeamActivity => AiFeature::TeamActivity,
            self::PersonPerformance => AiFeature::PersonPerformance,
            self::PersonClientActivity => AiFeature::PersonClientActivity,
        };
    }

    /** Clase del sujeto: un cliente o una persona. */
    public function subjectClass(): string
    {
        return match ($this) {
            self::ClientSummary, self::ClientTeamActivity => Client::class,
            self::PersonPerformance, self::PersonClientActivity => User::class,
        };
    }

    /** ¿Devuelve una frase por persona o por cliente (items) en lugar de un texto (content)? */
    public function hasItems(): bool
    {
        return $this === self::ClientTeamActivity || $this === self::PersonClientActivity;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
