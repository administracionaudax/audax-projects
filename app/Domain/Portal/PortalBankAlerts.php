<?php

namespace App\Domain\Portal;

use App\Models\Client;
use App\Models\HourBank;
use App\Models\HourBankAlert;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Portal\ClientHourBankThreshold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Aviso opcional al cliente cuando su bolsa llega al 90 % y al 100 % (SPEC §11, D-065):
 * - solo si el cliente está activo y lo tiene activado (clients.portal_notify_thresholds),
 * - solo de bolsas abiertas (activas o agotadas): nunca de una cerrada o renovada,
 * - con lo que el cliente VE (PortalBankFigures): nunca le avisa por horas que aún no puede ver,
 * - cada umbral una sola vez por bolsa (hour_bank_alerts, clave client:90 / client:100),
 * - por email (cola mail) a los usuarios del portal activos de ese cliente.
 * Se comprueba al guardar o borrar una entrada de la bolsa y al aprobarla (PortalServiceProvider),
 * cuando la escritura interna ya está confirmada: un aviso NUNCA la rompe (un fallo se registra
 * con report() y la petición sigue su curso).
 */
final class PortalBankAlerts
{
    public const array THRESHOLDS = [90, 100];

    /** @var array<int, true> bolsas pendientes de comprobar al confirmar la transacción */
    private array $pending = [];

    /**
     * Apunta la bolsa para comprobarla una sola vez cuando termine la transacción en curso (una
     * aprobación de 40 entradas de la misma bolsa la comprueba una vez). Si la transacción se
     * deshace, no hay nada que comprobar.
     */
    public function queue(?int $bankId): void
    {
        if ($bankId === null) {
            return;
        }

        $first = $this->pending === [];
        $this->pending[$bankId] = true;

        if ($first) {
            DB::afterRollBack(fn () => $this->pending = []);
            DB::afterCommit(fn () => $this->flush());
        }
    }

    /**
     * Comprueba las bolsas apuntadas. Corre tras confirmar la escritura interna, así que un fallo
     * aquí devolvería un error con el cambio ya guardado (y reintentarlo lo duplicaría): cada bolsa
     * se comprueba por separado y un fallo solo se registra.
     */
    public function flush(): void
    {
        $ids = array_keys($this->pending);
        $this->pending = [];

        if ($ids === []) {
            return;
        }

        try {
            $banks = HourBank::query()->whereKey($ids)->with('project')->get();
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        foreach ($banks as $bank) {
            try {
                $this->check($bank);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function check(HourBank $bank): void
    {
        // Una bolsa cerrada o renovada ya no se agota ni se renueva (como los avisos internos,
        // HourBankLedger): aprobar sus horas (D-053) no le dice al cliente «hablemos para renovarla».
        if (! $bank->status->acceptsTime() || $bank->total_minutes <= 0) {
            return;
        }

        // El cliente del proyecto de la bolsa: ninguno si el proyecto es interno o está en la
        // papelera (el portal no enseña sus bolsas).
        $client = Client::query()
            ->whereIn('id', Project::query()->whereKey($bank->project_id)->select('client_id'))
            ->first();

        if ($client === null || ! $client->is_active || ! $client->portal_notify_thresholds) {
            return;
        }

        $recipients = User::query()->where('client_id', $client->id)->where('is_active', true)->get();
        if ($recipients->isEmpty()) {
            return;
        }

        // El alcance sale del cliente, no de un destinatario: nunca lanza el 403 del portal.
        $figures = PortalBankFigures::one(PortalScope::forClient($client), $bank);
        $percent = (int) floor($figures['percent'] * 100);

        foreach (self::THRESHOLDS as $threshold) {
            if ($percent < $threshold) {
                continue;
            }

            $alert = HourBankAlert::query()->createOrFirst(
                ['hour_bank_id' => $bank->id, 'key' => "client:{$threshold}"],
                ['kind' => HourBankAlert::KIND_CLIENT_THRESHOLD, 'threshold' => $threshold, 'notified_on' => CarbonImmutable::today('Europe/Madrid')->toDateString()],
            );

            if ($alert->wasRecentlyCreated) {
                Notification::send($recipients, new ClientHourBankThreshold($bank, $threshold, $figures));
            }
        }
    }
}
