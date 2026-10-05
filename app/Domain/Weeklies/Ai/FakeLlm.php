<?php

namespace App\Domain\Weeklies\Ai;

use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;

/**
 * Doble de LlmClient para los tests (y para local sin clave, GEMINI_DRIVER=fake). No llama a nada ni
 * escribe en ai_usage. Las respuestas se programan en orden:
 *
 *     $llm = FakeLlm::bind();                                   // la instancia del contenedor
 *     $llm->push(['global_summary' => '…']);                    // JSON (array)
 *     $llm->push('Texto libre');                                // texto
 *     $llm->push(new LlmUnavailable('caída'));                  // un fallo
 *     $llm->respondUsing(fn (LlmRequest $r) => [...]);         // según la petición
 *     $llm->assertSent(fn (LlmRequest $r) => $r->feature === AiFeature::WeeklyReport);
 *
 * Sin respuestas programadas falla con un mensaje claro (así ningún test depende de un valor oculto).
 */
final class FakeLlm implements LlmClient
{
    /** @var list<array<array-key, mixed>|string|Throwable> */
    private array $queue = [];

    /** @var (Closure(LlmRequest): (array<array-key, mixed>|string))|null */
    private ?Closure $responder = null;

    /** @var list<LlmRequest> */
    private array $requests = [];

    public function __construct(private readonly string $model = 'fake-gemini') {}

    /** Registra un FakeLlm en el contenedor y lo devuelve. */
    public static function bind(): self
    {
        $fake = new self;
        app()->instance(LlmClient::class, $fake);

        return $fake;
    }

    /**
     * @param  array<array-key, mixed>|string|Throwable  ...$responses
     */
    public function push(array|string|Throwable ...$responses): self
    {
        $this->queue = [...$this->queue, ...array_values($responses)];

        return $this;
    }

    /**
     * @param  Closure(LlmRequest): (array<array-key, mixed>|string)  $responder
     */
    public function respondUsing(Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        $next = $this->queue === [] ? null : array_shift($this->queue);

        if ($next === null && $this->responder !== null) {
            $next = ($this->responder)($request);
        }

        if ($next === null) {
            throw new RuntimeException("FakeLlm: no hay respuesta programada para {$request->feature->value}.");
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        $text = is_array($next) ? (string) json_encode($next, JSON_UNESCAPED_UNICODE) : $next;
        $json = null;

        if ($request->wantsJson()) {
            $json = is_array($next) ? $next : json_decode($next, true);

            if (! is_array($json)) {
                throw new LlmInvalidResponse('FakeLlm: la respuesta programada no es JSON.');
            }
        }

        return new LlmResponse($text, $json, $this->model, 0, 0, 0, 0, 'STOP');
    }

    /**
     * @return list<LlmRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @param  (callable(LlmRequest): bool)|null  $matches
     */
    public function assertSent(?callable $matches = null): void
    {
        $found = array_filter($this->requests, fn (LlmRequest $request): bool => $matches === null || $matches($request));

        Assert::assertNotEmpty($found, 'No se envió ninguna petición a la IA que cumpla la condición.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->requests, 'Número de peticiones a la IA distinto del esperado.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->requests, 'Se enviaron peticiones a la IA y no se esperaba ninguna.');
    }
}
