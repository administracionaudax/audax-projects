<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Resources\Weeklies\AiUsageResource;
use App\Models\AiUsage;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Uso de IA» (F-173 y F-180, D-193): llamadas, errores, tokens, caracteres y coste estimado de la IA
 * externa (Gemini y Google TTS) de los últimos 7, 30 o 90 días, por función y por modelo, el coste
 * por día y las últimas llamadas, de ai_usage. Solo admins (gate view-ai-usage). Nunca enseña el
 * texto enviado: ai_usage no lo guarda.
 *
 * Los costes se suman en la base de datos y llegan como texto con 6 decimales (USD).
 */
class AiUsageController extends Controller
{
    public const array RANGES = [7, 30, 90];

    public const int RECENT = 50;

    public function __invoke(Request $request): Response
    {
        Gate::authorize('view-ai-usage');

        $days = in_array((int) $request->query('dias'), self::RANGES, true) ? (int) $request->query('dias') : 30;
        $from = LocalTime::today()->subDays($days - 1)->startOfDay()->utc();
        $scope = fn (): Builder => AiUsage::query()->where('created_at', '>=', $from);
        $sums = 'COUNT(*) as calls, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as errors, COALESCE(SUM(prompt_tokens), 0) as prompt_tokens, '
            .'COALESCE(SUM(response_tokens), 0) as response_tokens, COALESCE(SUM(total_tokens), 0) as total_tokens, '
            .'COALESCE(SUM(character_count), 0) as characters, COALESCE(SUM(estimated_cost_usd), 0) as cost_usd';

        $totals = $scope()->toBase()->selectRaw($sums, [AiUsage::STATUS_ERROR])->first();
        $byFeature = $scope()->toBase()->selectRaw('feature, '.$sums, [AiUsage::STATUS_ERROR])->groupBy('feature')->orderByDesc('cost_usd')->orderBy('feature')->get();
        $byModel = $scope()->toBase()->selectRaw('provider, model, '.$sums, [AiUsage::STATUS_ERROR])->groupBy('provider', 'model')->orderByDesc('cost_usd')->orderBy('model')->get();

        $byDay = [];
        foreach ($scope()->get(['created_at', 'estimated_cost_usd']) as $row) {
            if ($row->created_at === null) {
                continue;
            }
            $day = LocalTime::dateOf($row->created_at);
            $byDay[$day] ??= ['date' => $day, 'calls' => 0, 'cost' => '0.000000'];
            $byDay[$day]['calls']++;
            $cost = $row->estimated_cost_usd;
            $byDay[$day]['cost'] = bcadd($byDay[$day]['cost'], is_numeric($cost) ? number_format((float) $cost, 6, '.', '') : '0', 6);
        }
        ksort($byDay);

        return Inertia::render('admin/ai-usage', [
            'range' => ['days' => $days, 'options' => self::RANGES],
            'totals' => self::row($totals),
            'by_feature' => $byFeature->map(fn (object $row): array => ['feature' => (string) $row->feature, ...self::row($row)])->values()->all(),
            'by_model' => $byModel->map(fn (object $row): array => ['provider' => (string) $row->provider, 'model' => (string) $row->model, ...self::row($row)])->values()->all(),
            'by_day' => array_values(array_map(fn (array $day): array => ['date' => $day['date'], 'calls' => $day['calls'], 'cost_usd' => $day['cost']], $byDay)),
            'recent' => AiUsageResource::collection($scope()->with('user')->latest('id')->limit(self::RECENT)->get()),
        ]);
    }

    /**
     * @return array{calls: int, errors: int, prompt_tokens: int, response_tokens: int, total_tokens: int, characters: int, cost_usd: string}
     */
    private static function row(?object $row): array
    {
        return [
            'calls' => (int) ($row->calls ?? 0),
            'errors' => (int) ($row->errors ?? 0),
            'prompt_tokens' => (int) ($row->prompt_tokens ?? 0),
            'response_tokens' => (int) ($row->response_tokens ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'characters' => (int) ($row->characters ?? 0),
            'cost_usd' => number_format((float) ($row->cost_usd ?? 0), 6, '.', ''),
        ];
    }
}
