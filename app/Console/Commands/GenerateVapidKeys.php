<?php

namespace App\Console\Commands;

use App\Broadcasting\WebPushConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Claves VAPID de Web Push (D-072). Se generan EN EL SERVIDOR y se imprimen para copiarlas en
 * shared/.env: el comando no escribe ningún fichero, así que nunca acaban en Git. Con --check
 * comprueba las que ya hay en la configuración (sin mostrarlas).
 */
#[Signature('push:vapid-keys {--check : Solo comprueba si las claves de la configuración son válidas}')]
#[Description('Genera un par de claves VAPID para los avisos del navegador y las imprime (no las guarda)')]
class GenerateVapidKeys extends Command
{
    public function handle(): int
    {
        if ((bool) $this->option('check')) {
            if (WebPushConfig::enabled()) {
                $this->info(__('realtime.vapid.valid'));

                return self::SUCCESS;
            }

            $this->warn(__('realtime.vapid.invalid'));

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();

        $this->info(__('realtime.vapid.generated'));
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $from = config('mail.from.address');
        $this->line('VAPID_SUBJECT="mailto:'.(is_string($from) && $from !== '' ? $from : 'no-responder@audaxstudio.com').'"');
        $this->newLine();
        $this->warn(__('realtime.vapid.warning'));

        return self::SUCCESS;
    }
}
