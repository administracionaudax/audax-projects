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
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklySubmissionWriter;
use App\Models\Absence;
use App\Models\WeeklyCycle;
use Illuminate\Support\ServiceProvider;

/**
 * La Weekly (Fase 10, D-145 y D-146): la IA externa detrás de sus interfaces. Con el driver `fake`
 * (tests y local sin claves) se usan los dobles; el resto de piezas del dominio se resuelven solas.
 */
class WeekliesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, fn (): LlmClient => config('services.gemini.driver') === 'fake'
            ? new FakeLlm
            : GeminiClient::fromConfig($this->app->make(AiUsageRecorder::class)));

        $this->app->singleton(SpeechSynthesizer::class, fn (): SpeechSynthesizer => config('services.google_tts.driver') === 'fake'
            ? new FakeSpeechSynthesizer
            : GoogleTtsSynthesizer::fromConfig($this->app->make(AiUsageRecorder::class)));

        // La única forma de escribir una weekly (10.2).
        $this->app->bind(WeeklySubmissionWriter::class, EloquentWeeklySubmissionWriter::class);
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
    }
}
