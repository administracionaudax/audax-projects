<?php

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Jobs\AnswerAssistantQuestion;
use App\Jobs\CleanDictation;
use App\Jobs\GenerateAiSummary;
use App\Jobs\GenerateWeeklyAudio;
use App\Jobs\GenerateWeeklyReport;
use App\Jobs\SuggestTasksFromWeekly;
use App\Jobs\UpdateWeeklySatisfaction;
use App\Models\AiSummary;
use App\Models\Client;
use App\Models\Project;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

/*
| Coste de Gemini y la cola `ai` (D-222, hallazgo 2 de la revisión de seguridad de la Fase 10):
| - límites diarios por persona (configurables) para el asistente, los resúmenes y las tareas sugeridas,
| - una pregunta al asistente en curso por persona,
| - Jobs únicos: no se encola dos veces lo mismo,
| - el informe, su audio y la satisfacción del cierre van en la cola prioritaria `ai-high`.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Europe/Madrid'));
    $this->me = userWithRole('employee');
    $this->acme = Client::factory()->create(['name' => 'Acme']);
    Project::factory()->withMembers([$this->me])->create(['client_id' => $this->acme->id]);
    WeeklyCycle::factory()->active('2026-10-05')->create();
});

it('el asistente: una pregunta en curso por persona; la siguiente espera a la respuesta', function () {
    Queue::fake();

    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => '¿Cómo va Acme?'])->assertStatus(202);
    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => '¿Y Pepe?'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['question' => __('weeklies.assistant.busy')]);

    Queue::assertPushed(AnswerAssistantQuestion::class, 1);

    // Otra persona sí puede preguntar.
    $this->actingAs(userWithRole('employee'))->postJson('/ia/preguntas', ['question' => '¿Cómo va Acme?'])->assertStatus(202);

    // Una pregunta atascada (más de lo que dura un Job) no bloquea para siempre.
    $this->travel(AiQueue::UNIQUE_FOR + 1)->seconds();
    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => '¿Y Pepe?'])->assertStatus(202);
});

it('el asistente: límite diario por persona, que vuelve al día siguiente (hora de Madrid)', function () {
    config(['services.gemini.daily_limits.assistant' => 2]);
    $llm = FakeLlm::bind()->push('Una.')->push('Dos.')->push('Tres.');

    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => 'Uno'])->assertStatus(202);
    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => 'Dos'])->assertStatus(202);
    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => 'Tres'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['question' => __('weeklies.ai_limits.assistant', ['limit' => 2])]);

    expect($llm->requests())->toHaveCount(2);

    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:05', 'Europe/Madrid'));
    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => 'Tres'])->assertStatus(202);
    expect($llm->requests())->toHaveCount(3);
});

it('con el límite a 0 no hay tope', function () {
    config(['services.gemini.daily_limits.assistant' => 0]);
    FakeLlm::bind();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => "Pregunta {$i}"])->assertStatus(202);
    }
});

it('los resúmenes con IA: límite diario por persona; pedir uno que ya se genera no gasta', function () {
    Queue::fake();
    config(['services.gemini.daily_limits.summaries' => 2]);
    $beta = Client::factory()->create(['name' => 'Beta']);

    $this->actingAs($this->me)->postJson("/clientes/{$this->acme->id}/resumen-ia")->assertStatus(202);
    // El mismo, aún en cola: no encola otro ni gasta.
    $this->actingAs($this->me)->postJson("/clientes/{$this->acme->id}/resumen-ia")->assertStatus(202);
    $this->actingAs($this->me)->postJson("/clientes/{$beta->id}/actividad-ia")->assertStatus(202);

    $this->actingAs($this->me)->postJson("/clientes/{$beta->id}/resumen-ia")
        ->assertStatus(429)
        ->assertJsonPath('message', __('weeklies.ai_limits.summaries', ['limit' => 2]));
    // Desde la página (Inertia), vuelve con el aviso de error.
    $this->actingAs($this->me)->post("/clientes/{$beta->id}/resumen-ia")
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    Queue::assertPushed(GenerateAiSummary::class, 2);
    expect(AiSummary::query()->count())->toBe(2);

    // El límite es de cada persona.
    $this->actingAs(userWithRole('employee'))->postJson("/clientes/{$beta->id}/resumen-ia")->assertStatus(202);
});

it('las tareas sugeridas: límite diario por persona', function () {
    Queue::fake();
    config(['services.gemini.daily_limits.suggested_tasks' => 1]);
    WeeklyCycle::factory()->forWeekOf('2026-09-28')->create();

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertStatus(202);
    $this->travel(AiQueue::UNIQUE_FOR + 60)->seconds();
    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')
        ->assertStatus(429)
        ->assertJsonPath('message', __('weeklies.ai_limits.suggested_tasks', ['limit' => 1]));

    Queue::assertPushed(SuggestTasksFromWeekly::class, 1);
});

it('todos los Jobs de IA son únicos y no se encolan dos veces lo mismo', function (string $job, array $args) {
    Queue::fake();

    expect(is_subclass_of($job, ShouldBeUnique::class))->toBeTrue();

    $job::dispatch(...$args);
    $job::dispatch(...$args);

    Queue::assertPushed($job, 1);
})->with([
    'informe' => [GenerateWeeklyReport::class, [1]],
    'audio' => [GenerateWeeklyAudio::class, [1]],
    'satisfacción' => [UpdateWeeklySatisfaction::class, [1]],
    'resumen' => [GenerateAiSummary::class, [1]],
    'pregunta' => [AnswerAssistantQuestion::class, ['abc']],
    'tareas sugeridas' => [SuggestTasksFromWeekly::class, [1]],
    'dictado' => [CleanDictation::class, [1]],
]);

it('el informe, su audio y la satisfacción van a la cola prioritaria; el resto, a la cola ai', function () {
    expect((new GenerateWeeklyReport(1))->queue)->toBe(AiQueue::HIGH)
        ->and((new GenerateWeeklyAudio(1))->queue)->toBe(AiQueue::HIGH)
        ->and((new UpdateWeeklySatisfaction(1))->queue)->toBe(AiQueue::HIGH)
        ->and((new AnswerAssistantQuestion('abc'))->queue)->toBe(AiQueue::NAME)
        ->and((new GenerateAiSummary(1))->queue)->toBe(AiQueue::NAME)
        ->and((new SuggestTasksFromWeekly(1))->queue)->toBe(AiQueue::NAME)
        ->and((new CleanDictation(1))->queue)->toBe(AiQueue::NAME)
        // El supervisor atiende primero la prioritaria (sin balanceo, por orden).
        ->and(config('horizon.defaults.supervisor-ai.queue'))->toBe([AiQueue::HIGH, AiQueue::NAME])
        ->and(config('horizon.defaults.supervisor-ai.balance'))->toBeFalse();
});
