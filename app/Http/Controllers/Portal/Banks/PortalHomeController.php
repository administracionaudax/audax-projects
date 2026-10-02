<?php

namespace App\Http\Controllers\Portal\Banks;

use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Portal\PortalBankFigures;
use App\Domain\Portal\PortalScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio del portal (SPEC §11, D-064): las bolsas activas del cliente (activa o agotada) con su
 * barra, cifras, estado y fechas; un resumen arriba (horas de este mes y bolsas cerca del límite
 * desde el primer umbral configurado) y el histórico de bolsas cerradas y renovadas. Todo sale de
 * PortalScope y las cifras de PortalBankFigures (solo las horas que ve el cliente): sin importes.
 * Un usuario sin cliente, desactivado o de un cliente desactivado recibe 403 (D-063).
 *
 * Consultas fijas (sin N+1): el cliente, las bolsas con sus proyectos, sus cifras y las horas del mes.
 */
class PortalHomeController extends Controller
{
    public function __invoke(Request $request, HourBankLedger $ledger): Response
    {
        /** @var User $user */
        $user = $request->user();
        $scope = PortalScope::for($user);

        $banks = PortalBankData::banks($scope);
        $figures = PortalBankFigures::many($scope, $banks);
        $thresholds = $ledger->thresholds();
        $first = PortalBankData::firstThreshold($thresholds);

        $ids = [];
        $open = [];
        $history = [];
        $nearLimit = 0;

        foreach ($banks as $bank) {
            $ids[] = $bank->id;
            $item = PortalBankData::item($bank, $figures[$bank->id]);

            if (! PortalBankData::isOpen($bank)) {
                $history[] = $item;

                continue;
            }

            $open[] = $item;
            $total = $item['figures']['total_minutes'];
            if ($total <= 0 || $item['figures']['within_minutes'] * 100 >= $first * $total) {
                $nearLimit++;
            }
        }

        // Activas: por proyecto y de la más antigua a la más reciente. Histórico: la más reciente primero.
        usort($open, fn (array $a, array $b): int => [$a['project']['code'], $a['start_date'], $a['id']] <=> [$b['project']['code'], $b['start_date'], $b['id']]);
        usort($history, fn (array $a, array $b): int => [$b['end_date'] ?? $b['start_date'], $b['id']] <=> [$a['end_date'] ?? $a['start_date'], $a['id']]);

        return Inertia::render('portal/home', [
            'client' => ['name' => $scope->client->name],
            'visibility' => $scope->client->portal_entry_visibility->value,
            'thresholds' => $thresholds,
            'summary' => [
                ...$this->month($scope, $ids),
                'open_count' => count($open),
                'near_limit_count' => $nearLimit,
                'first_threshold' => $first,
            ],
            'banks' => $open,
            'history' => $history,
        ]);
    }

    /**
     * Horas de este mes (Europe/Madrid) en las bolsas del cliente, de las que ve, con el exceso que
     * ve (D-092). Sin bolsas, ni se consultan.
     *
     * @param  list<int>  $bankIds
     * @return array{month: string, month_minutes: int, month_overage_minutes: int}
     */
    private function month(PortalScope $scope, array $bankIds): array
    {
        $today = LocalTime::today();
        $hours = PortalBankFigures::between($scope, $bankIds, $today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString());

        return [
            'month' => $today->startOfMonth()->toDateString(),
            'month_minutes' => $hours['minutes'],
            'month_overage_minutes' => $hours['overage_minutes'],
        ];
    }
}
