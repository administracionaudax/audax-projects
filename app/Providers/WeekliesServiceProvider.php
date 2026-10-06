<?php

namespace App\Providers;

use App\Domain\Weeklies\Ai\AiUsageRecorder;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\FakeSpeechSynthesizer;
use App\Domain\Weeklies\Ai\GeminiClient;
use App\Domain\Weeklies\Ai\GoogleTtsSynthesizer;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\SpeechSynthesizer;
use App\Domain\Weeklies\EloquentWeeklySubmissionWriter;
use App\Domain\Weeklies\LlmWeeklyReportGenerator;
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyReportGenerator;
use App\Domain\Weeklies\WeeklySubmissionWriter;
use App\Enums\WeeklyJobState;
use App\Events\Weeklies\WeeklyChanged;
use App\Models\Absence;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Illuminate\Support\ServiceProvider;

/**
 * La Weekly (Fase 10, D-145 y D-146): la IA externa detrás de sus interfaces. Con el driver `fake`
 * (tests y local sin claves) se usan los dobles; el resto de piezas del dominio se resuelven solas.
 */
class WeekliesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Con el driver fake: el doble vacío en los tests (cada test programa sus respuestas) y el de
        // prueba en local y en los E2E (10.3), que responde siempre.
        $this->app->singleton(LlmClient::class, fn (): LlmClient => config('services.gemini.driver') === 'fake'
            ? ($this->app->runningUnitTests() ? new FakeLlm : FakeLlm::demo())
            : GeminiClient::fromConfig($this->app->make(AiUsageRecorder::class)));

        $this->app->singleton(SpeechSynthesizer::class, fn (): SpeechSynthesizer => config('services.google_tts.driver') === 'fake'
            ? new FakeSpeechSynthesizer
            : GoogleTtsSynthesizer::fromConfig($this->app->make(AiUsageRecorder::class)));

        // La única forma de escribir una weekly (10.2).
        $this->app->bind(WeeklySubmissionWriter::class, EloquentWeeklySubmissionWriter::class);

        // El informe con Gemini (10.3).
        $this->app->bind(WeeklyReportGenerator::class, LlmWeeklyReportGenerator::class);
    }

    public function boot(): void
    {
        // Una ausencia que se aprueba, cambia o se borra puede eximir (o dejar de eximir) de la
        // weekly activa (F-098): el contador de «Mi espacio» de esa persona se recalcula (D-160).
        $forget = fn (Absence $absence) => MyWeeklyStatus::forgetActive((int) $absence->user_id);
        Absence::saved($forget);
        Absence::deleted($forget);

        // La semana activa en caché del contador (D-160): se olvida al abrir, cambiar o borrar una.
        WeeklyCycle::saved(fn () => MyWeeklyStatus::forgetActiveSnapshot());
        WeeklyCycle::deleted(fn () => MyWeeklyStatus::forgetActiveSnapshot());

        $this->bootLive();
    }

    /**
     * Tiempo real de la Weekly (10.9b, D-229): cada cambio que se ve en el resumen, el histórico o
     * el informe avisa por `weekly.changed` para que las páginas abiertas se pongan al día.
     */
    private function bootLive(): void
    {
        WeeklySubmission::created(function (WeeklySubmission $submission): void {
            if ($submission->submitted_at !== null) {
                WeeklyChanged::notify($submission->weekly_cycle_id, 'submission');
            }
        });
        WeeklySubmission::updated(function (WeeklySubmission $submission): void {
            if ($submission->wasChanged(['submitted_at', 'resubmitted_at'])) {
                WeeklyChanged::notify($submission->weekly_cycle_id, 'submission');
            }
        });

        $exemption = fn (WeeklyExemption $row) => WeeklyChanged::notify($row->weekly_cycle_id, 'exemption');
        WeeklyExemption::saved($exemption);
        WeeklyExemption::deleted($exemption);

        // Una ausencia y «Estoy fuera» eximen al vuelo en la semana activa.
        $active = fn () => WeeklyChanged::notify(MyWeeklyStatus::activeSnapshot()['id'] ?? null, 'exemption');
        Absence::saved($active);
        Absence::deleted($active);
        User::saved(function (User $user) use ($active): void {
            if ($user->wasChanged(['weekly_away_reason', 'weekly_away_until'])) {
                $active();
            }
        });

        WeeklyCycle::created(fn (WeeklyCycle $cycle) => WeeklyChanged::notify($cycle->id, 'cycle'));
        WeeklyCycle::updated(function (WeeklyCycle $cycle): void {
            $done = fn (string $state): bool => $cycle->wasChanged($state) && $cycle->{$state} === WeeklyJobState::Done;

            if ($cycle->wasChanged(['status', 'deadline_date', 'report_edited_at', 'expected_user_ids']) || $done('report_state') || $done('audio_state')) {
                WeeklyChanged::notify($cycle->id, $cycle->wasChanged(['report_state', 'audio_state', 'report_edited_at']) ? 'report' : 'cycle');
            }
        });
        WeeklyCycle::deleted(fn (WeeklyCycle $cycle) => WeeklyChanged::notify($cycle->id, 'cycle'));

        ClientSatisfactionSnapshot::created(fn (ClientSatisfactionSnapshot $snapshot) => WeeklyChanged::notify($snapshot->weekly_cycle_id, 'satisfaction'));
    }
}
