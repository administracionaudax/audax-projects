<?php

namespace App\Console\Commands;

use App\Domain\Chat\Transcription\AudioTranscriptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transcriptions:backfill {--force : Vuelve a transcribir también los que ya tienen texto (cambio de motor o de modelo)}')]
#[Description('Transcribe cualquier audio del chat que no tenga texto (por ejemplo, tras una restauración)')]
class BackfillTranscriptions extends Command
{
    public function handle(AudioTranscriptions $transcriptions): int
    {
        $count = $transcriptions->backfill((bool) $this->option('force'));
        $this->info("Audios encolados: {$count}");

        return self::SUCCESS;
    }
}
