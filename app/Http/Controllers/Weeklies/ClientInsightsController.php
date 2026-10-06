<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\Ai\AiDailyLimitReached;
use App\Domain\Weeklies\Insights\AiSummaries;
use App\Enums\AiSummaryKind;
use App\Http\Controllers\Controller;
use App\Models\AiSummary;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Ficha de cliente de la Weekly (F-129 y F-131, D-194): pedir el «Resumen del cliente (IA)» y
 * «Analizar actividad del equipo». Se generan en la cola `ai` y se guardan hasta que alguien los
 * regenera (AiSummaries). Los puede pedir quien ve el cliente y usa la Weekly (D-021: los reportes
 * enviados los ve toda la plantilla). Las pestañas se sirven en la ficha /clientes/{client}.
 */
class ClientInsightsController extends Controller
{
    public function summary(Request $request, Client $client, AiSummaries $summaries): JsonResponse|RedirectResponse
    {
        return $this->request($request, $client, AiSummaryKind::ClientSummary, $summaries);
    }

    public function teamActivity(Request $request, Client $client, AiSummaries $summaries): JsonResponse|RedirectResponse
    {
        return $this->request($request, $client, AiSummaryKind::ClientTeamActivity, $summaries);
    }

    private function request(Request $request, Client $client, AiSummaryKind $kind, AiSummaries $summaries): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $client);
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();
        $busy = ($existing = $summaries->find($kind, $client)) !== null && AiSummaries::isBusy($existing);

        try {
            $summary = $summaries->request($kind, $client, $user);
        } catch (AiDailyLimitReached $e) {
            return self::limitReached($request, $e);
        }

        return self::respond($request, $summary, $busy);
    }

    /** D-222: 429 con el mensaje para quien lo pide en JSON; si no, vuelve con un aviso de error. */
    public static function limitReached(Request $request, AiDailyLimitReached $e): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

        return back();
    }

    /** JSON 202 para quien lo pide así; si no, vuelve a la ficha con un aviso. */
    public static function respond(Request $request, AiSummary $summary, bool $busy): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['summary' => AiSummaries::present($summary)], 202);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($busy ? 'weeklies.insights.already_running' : 'weeklies.insights.requested')]);

        return back();
    }
}
