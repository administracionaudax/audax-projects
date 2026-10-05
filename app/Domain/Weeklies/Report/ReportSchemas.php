<?php

namespace App\Domain\Weeklies\Report;

/**
 * Esquemas de respuesta (responseSchema, OpenAPI de Gemini) de las llamadas del informe y de la
 * satisfacción. Son las mismas formas JSON que piden los prompts del original; con el esquema,
 * Gemini no puede devolver otra cosa.
 */
final class ReportSchemas
{
    /**
     * Update de un cliente (lote, fusión y traducción).
     *
     * @return array<string, mixed>
     */
    public static function clientUpdate(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'clientName' => ['type' => 'STRING'],
                'executiveSummary' => ['type' => 'STRING'],
                'status' => ['type' => 'STRING', 'enum' => [ReportPipeline::ON_TRACK, ReportPipeline::RISK, ReportPipeline::BLOCKED]],
                'nextSteps' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'milestones' => ['type' => 'ARRAY', 'items' => [
                    'type' => 'OBJECT',
                    'properties' => ['date' => ['type' => 'STRING'], 'label' => ['type' => 'STRING']],
                    'required' => ['date', 'label'],
                ]],
                'tags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['clientName', 'executiveSummary', 'status', 'nextSteps', 'milestones', 'tags'],
            'propertyOrdering' => ['clientName', 'executiveSummary', 'status', 'nextSteps', 'milestones', 'tags'],
        ];
    }

    /**
     * Resumen global y riesgos del equipo.
     *
     * @return array<string, mixed>
     */
    public static function globalSummary(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'globalSummary' => ['type' => 'STRING'],
                'teamRisks' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['globalSummary', 'teamRisks'],
            'propertyOrdering' => ['globalSummary', 'teamRisks'],
        ];
    }

    /**
     * Guion del audio por secciones (generate-audio-tts, modo `scripts`).
     *
     * @return array<string, mixed>
     */
    public static function narration(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'intro' => ['type' => 'STRING'],
                'clients' => ['type' => 'ARRAY', 'items' => [
                    'type' => 'OBJECT',
                    'properties' => ['clientKey' => ['type' => 'STRING'], 'script' => ['type' => 'STRING']],
                    'required' => ['clientKey', 'script'],
                ]],
                'outro' => ['type' => 'STRING'],
            ],
            'required' => ['intro', 'clients', 'outro'],
            'propertyOrdering' => ['intro', 'clients', 'outro'],
        ];
    }

    /**
     * Delta de satisfacción de un cliente al cerrar (close-week-and-update-satisfaction).
     *
     * @return array<string, mixed>
     */
    public static function satisfaction(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'recommendedDelta' => ['type' => 'NUMBER'],
                'sentiment' => ['type' => 'STRING', 'enum' => ['MUY_POSITIVO', 'POSITIVO', 'LIGERAMENTE_POSITIVO', 'NEUTRAL', 'LIGERAMENTE_NEGATIVO', 'NEGATIVO', 'MUY_NEGATIVO']],
                'evidenceLevel' => ['type' => 'STRING', 'enum' => ['NONE', 'LOW', 'MEDIUM', 'HIGH']],
                'explicitClientImpact' => ['type' => 'BOOLEAN'],
                'confidence' => ['type' => 'NUMBER'],
                'reasoning' => ['type' => 'STRING'],
            ],
            'required' => ['recommendedDelta', 'sentiment', 'evidenceLevel', 'explicitClientImpact', 'confidence', 'reasoning'],
            'propertyOrdering' => ['recommendedDelta', 'sentiment', 'evidenceLevel', 'explicitClientImpact', 'confidence', 'reasoning'],
        ];
    }
}
