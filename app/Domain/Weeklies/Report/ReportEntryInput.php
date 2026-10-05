<?php

namespace App\Domain\Weeklies\Report;

/**
 * Un apunte de un cliente para el informe: la posición del envío entre los de la semana (por orden
 * de envío, desde 1), su autor, cuándo se envió (ISO) y el texto.
 */
final readonly class ReportEntryInput
{
    public function __construct(
        public int $sequence,
        public string $authorName,
        public ?string $submittedAt,
        public string $text,
    ) {}
}
