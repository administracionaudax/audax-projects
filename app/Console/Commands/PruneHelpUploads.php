<?php

namespace App\Console\Commands;

use App\Domain\Weeklies\Help\TutorialVideoUploads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Borra las subidas por trozos de vídeos de tutoriales que se quedaron a medias hace más de un día
 * (D-207): una pestaña cerrada o un corte de red dejan el trozo en help/uploads. Cada noche en
 * routes/console.php.
 */
#[Signature('help:prune-uploads')]
#[Description('Borra las subidas de vídeos de la ayuda que se quedaron a medias')]
class PruneHelpUploads extends Command
{
    public function handle(TutorialVideoUploads $uploads): int
    {
        $this->info('Subidas a medias borradas: '.$uploads->prune().'.');

        return self::SUCCESS;
    }
}
