<?php

namespace App\Enums;

/**
 * Estado de un trabajo largo de IA de una semana (informe o audio, D-146: siempre en la cola `ai`).
 * La interfaz muestra «Generando…» mientras está en cola o en marcha.
 */
enum WeeklyJobState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    public function label(): string
    {
        return __("weeklies.enums.job_state.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
