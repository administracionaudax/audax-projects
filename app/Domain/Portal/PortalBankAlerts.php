<?php

namespace App\Domain\Portal;

use App\Models\HourBank;
use App\Models\HourBankAlert;
use App\Models\User;
use App\Notifications\Portal\ClientHourBankThreshold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Aviso opcional al cliente cuando su bolsa llega al 90 % y al 100 % (SPEC §11, D-065):
 * - solo si el cliente lo tiene activado (clients.portal_notify_thresholds),
 * - con lo que el cliente VE (PortalBankFigures): nunca le avisa por horas que aún no puede ver,
 * - cada umbral una sola vez por bolsa (hour_bank_alerts, clave client:90 / client:100),
 * - por email (cola mail) a los usuarios del portal activos de ese cliente.
 * Se comprueba al guardar o borrar una entrada de la bolsa y al aprobarla (PortalServiceProvider).
 */
final class PortalBankAlerts
{
    public const array THRESHOLDS = [90, 100];

    /** @var array<int, true> bolsas pendientes de comprobar al confirmar la transacción */
    private array $pending = [];

    /**
     * Apunta la bolsa para comprobarla una sola vez cuando termine la transacción en curso (una
     * aprobación de 40 entradas de la misma bolsa la comprueba una vez).
     */
    public function queue(?int $bankId): void
    {
        if ($bankId === null) {
            return;
        }

        $first = $this->pending === [];
        $this->pending[$bankId] = true;

        if ($first) {
            DB::afterCommit(fn () => $this->flush());
        }
    }

    public function flush(): void
    {
        $ids = array_keys($this->pending);
        $this->pending = [];

        foreach (HourBank::query()->whereKey($ids)->with('project.client')->get() as $bank) {
            $this->check($bank);
        }
    }

    public function check(HourBank $bank): void
    {
        $bank->loadMissing('project.client');
        $client = $bank->project->client;

        if ($client === null || ! $client->portal_notify_thresholds || $bank->total_minutes <= 0) {
            return;
        }

        $recipients = User::query()->where('client_id', $client->id)->where('is_active', true)->get();
        if ($recipients->isEmpty()) {
            return;
        }

        $scope = PortalScope::for($recipients->first());
        $figures = PortalBankFigures::one($scope, $bank);
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
