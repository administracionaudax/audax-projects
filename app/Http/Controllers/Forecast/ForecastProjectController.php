<?php

namespace App\Http\Controllers\Forecast;

use App\Domain\Forecast\EstimateVsActual;
use App\Domain\Forecast\ForecastImpact;
use App\Domain\Forecast\ForecastPresenter;
use App\Domain\Forecast\ForecastProjectWriter;
use App\Http\Requests\Forecast\ForecastProjectRequest;
use App\Models\ForecastProject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Proyectos previstos (docs/PLAN-CARGAS.md §5.2 y §5.3, D-281): la lista (`/prevision/proyectos`,
 * ?estado=active|lost|linked|all), la ficha (`/prevision/proyectos/{id}`, con el impacto «sin / con»
 * y, si está vinculado, «estimado frente a real», los dos en una petición aparte) y alta, edición,
 * borrado, confirmar, perdido y reabrir. Todo con ForecastProjectWriter.
 */
class ForecastProjectController extends ForecastController
{
    public function __construct(private readonly ForecastProjectWriter $writer) {}

    /** GET /prevision/proyectos */
    public function index(Request $request, ForecastPresenter $presenter): Response
    {
        $this->authorize('viewAny', ForecastProject::class);

        /** @var User $user */
        $user = $request->user();
        $filter = $request->query('estado');
        $filter = is_string($filter) && in_array($filter, ForecastPresenter::FILTERS, true) ? $filter : 'active';

        return Inertia::render('forecast/projects/index', [
            'projects' => $presenter->list($filter),
            'filters' => ['status' => $filter],
            'clients' => Inertia::defer(fn (): array => $presenter->clients()),
            'can' => ['create' => $user->can('create', ForecastProject::class)],
        ]);
    }

    /** POST /prevision/proyectos */
    public function store(ForecastProjectRequest $request): RedirectResponse
    {
        $this->authorize('create', ForecastProject::class);

        /** @var User $user */
        $user = $request->user();
        $forecast = $this->writer->create($request->forecastData(), $user);
        $this->toast(__('forecast.flash.created'));

        return to_route('forecast.projects.show', $forecast);
    }

    /** GET /prevision/proyectos/{forecast} */
    public function show(Request $request, ForecastProject $forecast, ForecastPresenter $presenter, ForecastImpact $impact, EstimateVsActual $estimate): Response
    {
        $this->authorize('view', $forecast);

        /** @var User $user */
        $user = $request->user();
        $data = $presenter->show($user, $forecast);

        return Inertia::render('forecast/projects/show', [
            ...$data,
            'impact' => Inertia::defer(fn (): ?array => $impact->for($forecast), 'analysis'),
            'estimate' => Inertia::defer(fn (): ?array => $estimate->for($forecast), 'analysis'),
            'history' => Inertia::defer(fn (): array => $presenter->history($user, $forecast), 'analysis'),
            'options' => Inertia::defer(fn (): array => [
                ...$presenter->options(),
                'clients' => $presenter->clients(),
                'link_candidates' => $user->can('link', $forecast) ? $presenter->linkCandidates($user, $forecast) : [],
            ], 'options'),
        ]);
    }

    /** PUT /prevision/proyectos/{forecast} */
    public function update(ForecastProjectRequest $request, ForecastProject $forecast): RedirectResponse
    {
        $this->authorize('update', $forecast);

        /** @var User $user */
        $user = $request->user();
        $this->writer->update($forecast, $request->forecastData(), $user);
        $this->toast(__('forecast.flash.updated'));

        return back();
    }

    /** DELETE /prevision/proyectos/{forecast} */
    public function destroy(ForecastProject $forecast): RedirectResponse
    {
        $this->authorize('delete', $forecast);

        $this->writer->delete($forecast);
        $this->toast(__('forecast.flash.deleted'), 'info');

        return to_route('forecast.projects.index');
    }

    /** POST /prevision/proyectos/{forecast}/confirmar */
    public function confirm(ForecastProject $forecast): RedirectResponse
    {
        $this->authorize('confirm', $forecast);

        $this->writer->confirm($forecast);
        $this->toast(__('forecast.flash.confirmed'));

        return back();
    }

    /** POST /prevision/proyectos/{forecast}/perdido {reason?} */
    public function lose(Request $request, ForecastProject $forecast): RedirectResponse
    {
        $this->authorize('lose', $forecast);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:'.ForecastProject::REASON_MAX]], [], ['reason' => __('forecast.attributes.reason')]);
        $this->writer->lose($forecast, (string) ($data['reason'] ?? ''));
        $this->toast(__('forecast.flash.lost'), 'info');

        return back();
    }

    /** POST /prevision/proyectos/{forecast}/reabrir */
    public function reopen(ForecastProject $forecast): RedirectResponse
    {
        $this->authorize('reopen', $forecast);

        $this->writer->reopen($forecast);
        $this->toast(__('forecast.flash.reopened'));

        return back();
    }
}
