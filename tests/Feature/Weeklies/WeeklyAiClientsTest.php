<?php

use App\Domain\Weeklies\Ai\AiPricing;
use App\Domain\Weeklies\Ai\AiUsageRecorder;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\FakeSpeechSynthesizer;
use App\Domain\Weeklies\Ai\GeminiClient;
use App\Domain\Weeklies\Ai\GoogleTtsSynthesizer;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmInvalidResponse;
use App\Domain\Weeklies\Ai\LlmNotConfigured;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\Ai\SpeechRequest;
use App\Domain\Weeklies\Ai\SpeechSynthesizer;
use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Models\AiUsage;
use App\Models\WeeklyCycle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| IA externa de la Weekly (D-146, F-173 y F-174): GeminiClient y GoogleTtsSynthesizer contra la API
| simulada (Http::fake), sus dobles y el registro en ai_usage.
*/

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

function gemini(?string $key = 'clave-de-prueba', string $model = 'gemini-2.5-flash'): GeminiClient
{
    return new GeminiClient(app(AiUsageRecorder::class), $key, $model, 'https://generativelanguage.googleapis.com/v1beta', 30, 3);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function geminiBody(string $text, array $extra = []): array
{
    return [
        'candidates' => [['content' => ['parts' => [['text' => $text]], 'role' => 'model'], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 150, 'thoughtsTokenCount' => 50, 'totalTokenCount' => 1200],
        'modelVersion' => 'gemini-2.5-flash',
        ...$extra,
    ];
}

it('pide JSON con el esquema, la clave en la cabecera y el modelo de la configuración', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiBody("```json\n{\"global_summary\":\"Bien\"}\n```"))]);
    $cycle = WeeklyCycle::factory()->create();
    $admin = userWithRole('admin');

    $response = gemini()->generate(new LlmRequest(
        feature: AiFeature::WeeklyReport,
        prompt: 'Resume la semana',
        system: 'Eres el asistente de Audax',
        responseSchema: ['type' => 'OBJECT', 'properties' => ['global_summary' => ['type' => 'STRING']]],
        temperature: 0.2,
        user: $admin,
        subject: $cycle,
        operation: 'global',
        metadata: ['clients' => 3],
    ));

    expect($response->json)->toBe(['global_summary' => 'Bien'])
        ->and($response->promptTokens)->toBe(1000)
        ->and($response->responseTokens)->toBe(200)
        ->and($response->totalTokens)->toBe(1200)
        ->and($response->finishReason)->toBe('STOP');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
        && $request->hasHeader('x-goog-api-key', 'clave-de-prueba')
        && ! str_contains($request->url(), 'key=')
        && $request['generationConfig']['responseMimeType'] === 'application/json'
        && $request['generationConfig']['responseSchema']['type'] === 'OBJECT'
        && $request['generationConfig']['temperature'] === 0.2
        && $request['systemInstruction']['parts'][0]['text'] === 'Eres el asistente de Audax'
        && $request['contents'][0]['parts'][0]['text'] === 'Resume la semana');

    $usage = AiUsage::query()->sole();
    expect($usage->provider)->toBe(AiProvider::Gemini)
        ->and($usage->feature)->toBe(AiFeature::WeeklyReport)
        ->and($usage->status)->toBe('success')
        ->and($usage->user_id)->toBe($admin->id)
        ->and($usage->subject_type)->toBe($cycle->getMorphClass())
        ->and($usage->subject_id)->toBe($cycle->id)
        ->and($usage->operation)->toBe('global')
        ->and($usage->prompt_tokens)->toBe(1000)
        ->and($usage->response_tokens)->toBe(200)
        // 1000 × 0,3 + 200 × 2,5 = 800 USD por millón → 0,000800.
        ->and($usage->estimated_cost_usd)->toBe('0.000800')
        ->and($usage->metadata)->toBe(['clients' => 3]);
});

it('en texto no pide JSON ni esquema', function () {
    Http::fake(['*' => Http::response(geminiBody('Hola'))]);

    $response = gemini()->generate(new LlmRequest(AiFeature::Assistant, 'Hola'));

    expect($response->text)->toBe('Hola')->and($response->json)->toBeNull();
    Http::assertSent(fn (Request $request): bool => ! isset($request['generationConfig']) && ! isset($request['systemInstruction']));
});

it('reintenta los 429 y los 5xx', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['message' => 'quota']], 429)
        ->push([], 503)
        ->push(geminiBody('{"ok":true}'))]);

    $response = gemini()->generate(new LlmRequest(AiFeature::Satisfaction, 'x', json: true));

    expect($response->json)->toBe(['ok' => true]);
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('no reintenta un 4xx', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

    expect(fn () => gemini()->generate(new LlmRequest(AiFeature::Satisfaction, 'x')))->toThrow(LlmUnavailable::class);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('tras agotar los reintentos falla y lo registra como error sin la clave', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Internal']], 500)]);

    expect(fn () => gemini()->generate(new LlmRequest(AiFeature::WeeklyReport, 'x')))->toThrow(LlmUnavailable::class);

    $usage = AiUsage::query()->sole();
    expect($usage->status)->toBe('error')
        ->and($usage->error)->toContain('HTTP 500')
        ->and($usage->error)->not->toContain('clave-de-prueba')
        ->and($usage->estimated_cost_usd)->toBeNull();
});

it('una respuesta vacía o un JSON roto es una respuesta inválida', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['candidates' => [['content' => ['parts' => []], 'finishReason' => 'SAFETY']]])
        ->push(geminiBody('esto no es JSON'))]);

    expect(fn () => gemini()->generate(new LlmRequest(AiFeature::WeeklyReport, 'x')))->toThrow(LlmInvalidResponse::class)
        ->and(fn () => gemini()->generate(new LlmRequest(AiFeature::WeeklyReport, 'x', json: true)))->toThrow(LlmInvalidResponse::class)
        ->and(AiUsage::query()->where('status', 'error')->count())->toBe(2);
});

it('sin clave no llama a nada', function () {
    Http::fake();

    expect(fn () => gemini(key: null)->generate(new LlmRequest(AiFeature::WeeklyReport, 'x')))->toThrow(LlmNotConfigured::class);
    Http::assertNothingSent();
});

it('el modelo y el driver salen de la configuración (F-174)', function () {
    config(['services.gemini.driver' => 'gemini', 'services.gemini.model' => 'gemini-3.5-flash', 'services.gemini.key' => 'k']);
    app()->forgetInstance(LlmClient::class);

    expect(app(LlmClient::class))->toBeInstanceOf(GeminiClient::class)
        ->and(app(LlmClient::class)->model())->toBe('gemini-3.5-flash');

    config(['services.gemini.driver' => 'fake']);
    app()->forgetInstance(LlmClient::class);

    expect(app(LlmClient::class))->toBeInstanceOf(FakeLlm::class)
        ->and(app(SpeechSynthesizer::class))->toBeInstanceOf(FakeSpeechSynthesizer::class);
});

it('FakeLlm devuelve lo programado y registra las peticiones', function () {
    $llm = FakeLlm::bind();
    $llm->push(['a' => 1], 'texto', new LlmUnavailable('caída'));

    $client = app(LlmClient::class);

    expect($client->generate(new LlmRequest(AiFeature::WeeklyReport, 'x', json: true))->json)->toBe(['a' => 1])
        ->and($client->generate(new LlmRequest(AiFeature::Assistant, 'y'))->text)->toBe('texto')
        ->and(fn () => $client->generate(new LlmRequest(AiFeature::Assistant, 'z')))->toThrow(LlmUnavailable::class);

    $llm->respondUsing(fn (LlmRequest $request): array => ['feature' => $request->feature->value]);
    expect($client->generate(new LlmRequest(AiFeature::Satisfaction, 'w', json: true))->json)->toBe(['feature' => 'satisfaction']);

    $llm->assertSentCount(4);
    $llm->assertSent(fn (LlmRequest $request): bool => $request->prompt === 'y');
    expect(AiUsage::query()->count())->toBe(0);
});

it('FakeLlm sin respuesta programada falla con un mensaje claro', function () {
    expect(fn () => FakeLlm::bind()->generate(new LlmRequest(AiFeature::WeeklyReport, 'x')))->toThrow(RuntimeException::class, 'no hay respuesta programada');
});

it('Google TTS locuta por trozos, une los MP3 y registra los caracteres', function () {
    $id3 = 'ID3'."\x04\x00\x00\x00\x00\x00\x05".'abcde';
    Http::fake(['texttospeech.googleapis.com/*' => Http::sequence()
        ->push(['audioContent' => base64_encode($id3.'AUDIO1')])
        ->push(['audioContent' => base64_encode($id3.'AUDIO2')])]);
    $tts = new GoogleTtsSynthesizer(app(AiUsageRecorder::class), 'clave-tts', 'es-ES-Journey-F');
    $text = str_repeat('Frase de la semana con el cliente. ', 200);

    $speech = $tts->synthesize(new SpeechRequest($text));

    expect($speech->audio)->toBe($id3.'AUDIO1'.'AUDIO2')
        ->and($speech->chunks)->toBe(2)
        ->and($speech->characters)->toBe(mb_strlen($text));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://texttospeech.googleapis.com/v1/text:synthesize'
        && $request->hasHeader('x-goog-api-key', 'clave-tts')
        && $request['voice']['name'] === 'es-ES-Journey-F'
        && $request['audioConfig']['audioEncoding'] === 'MP3'
        && strlen($request['input']['text']) <= GoogleTtsSynthesizer::MAX_BYTES);

    $usage = AiUsage::query()->sole();
    expect($usage->provider)->toBe(AiProvider::GoogleTts)
        ->and($usage->feature)->toBe(AiFeature::Speech)
        ->and($usage->character_count)->toBe(mb_strlen($text))
        ->and($usage->estimated_cost_usd)->toBe(AiPricing::speech(mb_strlen($text)));
});

it('trocea el texto como el original: frases, palabras y caracteres', function () {
    expect(GoogleTtsSynthesizer::chunks(''))->toBe([])
        ->and(GoogleTtsSynthesizer::chunks("Hola.\r\n\r\n\r\n\r\nAdiós."))->toBe(["Hola.\n\nAdiós."])
        ->and(GoogleTtsSynthesizer::chunks('Uno. Dos. Tres.', 9))->toBe(['Uno. Dos.', 'Tres.'])
        ->and(GoogleTtsSynthesizer::chunks('palabra larguísima', 8))->toBe(['palabra', 'larguís', 'ima'])
        ->and(GoogleTtsSynthesizer::chunks('Una frase muy larga sin puntos', 12))->toBe(['Una frase', 'muy larga', 'sin puntos']);
});

it('Google TTS sin clave no llama a nada y un fallo queda registrado', function () {
    Http::fake(['*' => Http::response([], 500)]);

    expect(fn () => (new GoogleTtsSynthesizer(app(AiUsageRecorder::class), null))->synthesize(new SpeechRequest('Hola')))->toThrow(LlmNotConfigured::class);
    Http::assertNothingSent();

    expect(fn () => (new GoogleTtsSynthesizer(app(AiUsageRecorder::class), 'k'))->synthesize(new SpeechRequest('Hola')))->toThrow(LlmUnavailable::class)
        ->and(AiUsage::query()->sole()->status)->toBe('error');
});

it('estima el coste con BCMath y seis decimales', function () {
    expect(AiPricing::gemini('gemini-2.5-flash', 1_000_000, 1_000_000))->toBe('2.800000')
        ->and(AiPricing::gemini('gemini-2.5-flash', 1, 0))->toBe('0.000000')
        ->and(AiPricing::gemini('gemini-2.5-flash', 2, 0))->toBe('0.000001')
        ->and(AiPricing::gemini('modelo-desconocido', 100, 100))->toBeNull()
        ->and(AiPricing::speech(1_000_000))->toBe('16.000000')
        ->and(AiPricing::speech(0))->toBeNull();
});
