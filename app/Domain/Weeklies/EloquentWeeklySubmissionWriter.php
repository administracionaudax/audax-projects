<?php

namespace App\Domain\Weeklies;

use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Implementación de WeeklySubmissionWriter (entrega 10.2): las reglas 1 a 6 de la interfaz. La 7
 * (sin apuntes con texto no se envía) la valida antes el FormRequest.
 *
 * Todo en una transacción que vuelve a leer la semana bloqueándola: si alguien la cierra (10.3) a
 * la vez, o se escribe antes del cierre o se rechaza con CYCLE_CLOSED, nunca a medias.
 */
final class EloquentWeeklySubmissionWriter implements WeeklySubmissionWriter
{
    public function __construct(private readonly WeeklyEligibility $eligibility) {}

    public function saveDraft(User $author, WeeklyCycle $cycle, WeeklyDraftData $draft): WeeklySubmission
    {
        return $this->write($author, $cycle, $draft, submit: false);
    }

    public function submit(User $author, WeeklyCycle $cycle, WeeklyDraftData $draft): WeeklySubmission
    {
        return $this->write($author, $cycle, $draft, submit: true);
    }

    private function write(User $author, WeeklyCycle $cycle, WeeklyDraftData $draft, bool $submit): WeeklySubmission
    {
        return DB::transaction(function () use ($author, $cycle, $draft, $submit): WeeklySubmission {
            // Regla 1: la semana, releída y bloqueada, sigue activa.
            $current = WeeklyCycle::query()->whereKey($cycle->id)->lockForUpdate()->first();

            if ($current === null || ! $current->isActive()) {
                throw new WeeklyRuleViolation(WeeklyRuleViolation::CYCLE_CLOSED);
            }

            // Regla 2: le toca esa semana y no está exento (F-054).
            $roster = $this->eligibility->rosterForUser($current, $author);

            if (! $roster->participates($author->id)) {
                throw new WeeklyRuleViolation(WeeklyRuleViolation::NOT_PARTICIPANT);
            }

            if ($roster->isExempt($author->id)) {
                throw new WeeklyRuleViolation(WeeklyRuleViolation::EXEMPT);
            }

            // Regla 3: una fila por persona y semana (índice único; createOrFirst cubre la carrera).
            $submission = WeeklySubmission::query()->createOrFirst([
                'weekly_cycle_id' => $current->id,
                'user_id' => $author->id,
            ]);

            // Regla 4: los apuntes se sustituyen enteros.
            $this->replaceEntries($submission, $draft);

            $now = CarbonImmutable::now();

            if ($submit) {
                // Regla 6: el primer envío decide la puntualidad; al reenviar se conserva.
                if ($submission->submitted_at === null) {
                    $submission->submitted_at = $now;
                } else {
                    $submission->resubmitted_at = $now;
                }
            } else {
                // Regla 5: guardar sin enviar.
                $submission->draft_saved_at = $now;
            }

            $submission->save();

            return $submission->load('entries.client');
        });
    }

    private function replaceEntries(WeeklySubmission $submission, WeeklyDraftData $draft): void
    {
        $entries = $draft->filled();
        $projectIds = array_values(array_unique(array_filter(array_map(fn (WeeklyEntryData $entry): ?int => $entry->projectId, $entries))));
        $projectClients = $projectIds === [] ? [] : Project::query()
            ->withTrashed()
            ->whereKey($projectIds)
            ->pluck('client_id', 'id')
            ->map(fn ($clientId): ?int => $clientId === null ? null : (int) $clientId)
            ->all();

        $submission->entries()->delete();

        foreach ($entries as $position => $entry) {
            $projectId = $entry->projectId;

            // Un proyecto de otro cliente (o inexistente) se descarta.
            if ($projectId !== null && (! array_key_exists($projectId, $projectClients) || $projectClients[$projectId] !== $entry->clientId)) {
                $projectId = null;
            }

            $submission->entries()->create([
                'client_id' => $entry->clientId,
                'project_id' => $projectId,
                'body' => trim($entry->body),
                'source' => $entry->source,
                'position' => $position,
            ]);
        }
    }
}
