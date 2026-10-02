<?php

namespace App\Console\Commands;

use App\Domain\Chat\Transcription\AudioTranscriptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transcriptions:requeue')]
#[Description('Vuelve a encolar los audios sin transcribir (pendientes atascados, interrumpidos y fallidos) y avisa al admin de los que siguen fallando')]
class RequeueTranscriptions extends Command
{
    public function handle(AudioTranscriptions $transcriptions): int
    {
        $result = $transcriptions->requeue();
        $this->info("Encoladas: {$result['requeued']} · avisos al admin: {$result['notified']}");

        return self::SUCCESS;
    }
}
