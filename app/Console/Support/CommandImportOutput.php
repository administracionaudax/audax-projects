<?php

namespace App\Console\Support;

use App\Domain\Import\ClickUp\ImportOutput;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Progreso de un volcado o una importación en la consola: etapas y barra de progreso.
 */
final class CommandImportOutput implements ImportOutput
{
    private ?ProgressBar $bar = null;

    public function __construct(private readonly OutputStyle $output) {}

    public function stage(string $label): void
    {
        $this->output->writeln("  <fg=gray>›</> {$label}");
    }

    public function progressStart(int $max): void
    {
        $this->bar = $this->output->createProgressBar($max);
        $this->bar->start();
    }

    public function progressAdvance(int $steps = 1): void
    {
        $this->bar?->advance($steps);
    }

    public function progressFinish(): void
    {
        $this->bar?->finish();
        $this->bar = null;
        $this->output->newLine();
    }
}
