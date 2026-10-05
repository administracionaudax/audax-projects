<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Reports\Delivery\Documents\ReportDocuments;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Pdf\ReportHtml;
use App\Domain\Weeklies\Report\WeeklyClientUpdate;
use App\Domain\Weeklies\Report\WeeklyMilestone;
use App\Domain\Weeklies\Report\WeeklyReport;
use App\Domain\Weeklies\Report\WeeklyReportText;
use App\Domain\Weeklies\WeeklyCycleCloser;
use App\Domain\Weeklies\WeeklyJobProgress;
use App\Domain\Weeklies\WeeklyReportState;
use App\Enums\WeeklyClientStatus;
use App\Enums\WeeklyJobState;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Http\Requests\Weeklies\UpdateWeeklyReportRequest;
use App\Jobs\GenerateWeeklyReport;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Informe de la semana (F-072 a F-083, D-190 y D-192):
 * - generar o regenerar el texto con IA: encola GenerateWeeklyReport (cola `ai`) y vuelve enseguida;
 *   el progreso llega por Reverb (`weeklies.{id}`) o con `status`,
 * - editar el informe a mano,
 * - el estado del informe y del audio mientras se generan,
 * - el PDF, la impresión y la descarga en HTML (ReportKind::Weekly, con la hoja de Audax).
 */
class WeeklyReportController extends Controller
{
    use ExportsReports;

    /** Generar o regenerar el texto con IA (F-072). La semana cerrada no se regenera (F-089). */
    public function store(Request $request, WeeklyCycle $cycle): RedirectResponse|JsonResponse
    {
        Gate::authorize('generate', $cycle);

        if ($cycle->isClosed()) {
            throw ValidationException::withMessages(['report' => __('weeklies.report.closed')]);
        }

        if (WeeklyJobProgress::isRunning($cycle, WeeklyJobProgress::REPORT)) {
            throw ValidationException::withMessages(['report' => __('weeklies.report.busy')]);
        }

        /** @var User $user */
        $user = $request->user();
        $cycle->forceFill(['report_state' => WeeklyJobState::Queued, 'report_error' => null])->save();
        WeeklyJobProgress::update($cycle, WeeklyJobProgress::REPORT, WeeklyJobState::Queued);
        GenerateWeeklyReport::dispatch($cycle->id, $user->id);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($this->state($cycle->refresh()), 202);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => __('weeklies.report.queued')]);

        return back();
    }

    /** Editar el informe por cliente (F-077); el texto se vuelve a escribir con el informe. */
    public function update(UpdateWeeklyReportRequest $request, WeeklyCycle $cycle): RedirectResponse
    {
        $report = $cycle->reportData();

        if ($report === null) {
            throw ValidationException::withMessages(['report' => __('weeklies.report.empty_edit')]);
        }

        /** @var array{global_summary?: string|null, team_risks?: list<string|null>, client_updates: list<array<string, mixed>>} $data */
        $data = $request->validated();
        $updates = [];

        foreach ($data['client_updates'] as $index => $input) {
            $current = $this->match($report, $input, $index);
            $updates[] = new WeeklyClientUpdate(
                clientId: $current->clientId ?? (is_numeric($input['client_id'] ?? null) ? (int) $input['client_id'] : null),
                clientName: $current->clientName ?? trim((string) $input['client_name']),
                status: WeeklyClientStatus::from((string) $input['status']),
                executiveSummary: trim((string) ($input['executive_summary'] ?? '')),
                nextSteps: self::strings($input['next_steps'] ?? []),
                milestones: array_values(array_filter(array_map(
                    fn (mixed $milestone): WeeklyMilestone => WeeklyMilestone::fromArray(is_array($milestone) ? $milestone : []),
                    is_array($input['milestones'] ?? null) ? $input['milestones'] : [],
                ), fn (WeeklyMilestone $milestone): bool => $milestone->label !== '')),
                tags: array_key_exists('tags', $input) ? self::strings($input['tags']) : ($current->tags ?? []),
                satisfactionScore: $current?->satisfactionScore,
                hasReports: $current->hasReports ?? true,
                projects: $current->projects ?? [],
            );
        }

        $edited = new WeeklyReport(
            globalSummary: array_key_exists('global_summary', $data) ? trim((string) $data['global_summary']) : $report->globalSummary,
            teamRisks: array_key_exists('team_risks', $data) ? self::strings($data['team_risks']) : $report->teamRisks,
            clientUpdates: $updates,
        );

        /** @var User $user */
        $user = $request->user();
        $cycle->forceFill([
            'report' => $edited->toArray(),
            'report_text' => WeeklyReportText::markdown($cycle, $edited),
            'report_edited_at' => now(),
            'report_edited_by' => $user->id,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.report.saved')]);

        return back();
    }

    /** Estado del informe y del audio mientras se generan (sin Reverb, la página lo pregunta). */
    public function status(WeeklyCycle $cycle): JsonResponse
    {
        Gate::authorize('view', $cycle);

        return response()->json($this->state($cycle));
    }

    /**
     * PDF (?formato=pdf, por defecto), impresión (?formato=imprimir), Excel y CSV, y la descarga en
     * HTML de WeeklySync (?formato=html). Con ?mios=1, solo mis proyectos (F-080).
     */
    public function pdf(Request $request, WeeklyCycle $cycle): Response
    {
        Gate::authorize('view', $cycle);

        if ($request->query('formato') === 'html') {
            /** @var User $user */
            $user = $request->user();
            $document = app(ReportDocuments::class)->for(ReportKind::Weekly)->pdf($this->reportRequestFrom($request, ReportKind::Weekly, ['cycle' => $cycle->id]), $user);
            $html = app(ReportHtml::class)->render($document);

            return response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'weekly-'.strtolower($cycle->number).'.html'),
            ]);
        }

        return $this->exportResponse($request, ReportKind::Weekly, ['cycle' => $cycle->id], ExportFormat::Pdf) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(WeeklyCycle $cycle): array
    {
        $submitted = WeeklySubmission::query()->submitted()->where('weekly_cycle_id', $cycle->id)->count();

        return [
            'report_state' => $cycle->report_state?->value,
            'report_error' => $cycle->report_error,
            'report_progress' => WeeklyJobProgress::detail($cycle->id, WeeklyJobProgress::REPORT),
            'audio_state' => $cycle->audio_state?->value,
            'audio_error' => $cycle->audio_error,
            'audio_progress' => WeeklyJobProgress::detail($cycle->id, WeeklyJobProgress::AUDIO),
            'has_report' => $cycle->report !== null,
            'has_audio' => WeeklyCycleCloser::hasAudio($cycle),
            'submitted_count' => $submitted,
            'submission_count_at_generation' => $cycle->submission_count_at_generation,
            'stale' => WeeklyReportState::isStale($cycle, $submitted),
            'report_generated_at' => $cycle->report_generated_at?->toIso8601String(),
            'audio_generated_at' => $cycle->audio_generated_at?->toIso8601String(),
        ];
    }

    /**
     * El cliente del informe que corresponde a una fila del formulario: por su id o, si no lo tiene,
     * por su posición.
     *
     * @param  array<string, mixed>  $input
     */
    private function match(WeeklyReport $report, array $input, int $index): ?WeeklyClientUpdate
    {
        if (is_numeric($input['client_id'] ?? null)) {
            return $report->clientUpdate((int) $input['client_id']);
        }

        $candidate = $report->clientUpdates[$index] ?? null;

        return $candidate !== null && $candidate->clientId === null ? $candidate : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', is_array($values) ? $values : []),
            fn (string $value): bool => $value !== '',
        ));
    }
}
