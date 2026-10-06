<?php

namespace App\Domain\Weeklies\Ai;

use App\Enums\AiFeature;
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
 *
 * FakeLlm::demo() (10.3) es el que usa la app con GEMINI_DRIVER=fake fuera de los tests (local sin
 * clave y los E2E de la CI): responde siempre, con textos de prueba que dicen que no son de la IA y
 * con la forma del esquema pedido (responseSchema).
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

    /**
     * Respuestas de prueba deterministas para la app sin clave (local y E2E): la forma del esquema
     * pedido, el cliente del prompt («CLIENTE:») y las claves de las secciones del audio.
     */
    public static function demo(): self
    {
        return (new self('fake-gemini'))->respondUsing(function (LlmRequest $request): array|string {
            // Tareas sugeridas (10.6): una tarea de prueba para el usuario objetivo y el primer cliente.
            if ($request->feature === AiFeature::SuggestedTasks) {
                $userId = preg_match('/USUARIO OBJETIVO:\n- id: (\d+)/u', $request->prompt, $match) === 1 ? $match[1] : null;
                $clientId = preg_match('/CLIENTES CONOCIDOS \(ID y Nombre\):\n\{"id":(\d+)/u', $request->prompt, $match) === 1 ? $match[1] : null;

                return $userId === null ? [] : [[
                    'description' => 'Revisar la tarea de prueba generada sin IA (GEMINI_DRIVER=fake)',
                    'assigneeId' => $userId,
                    'assignerId' => null,
                    'clientId' => $clientId,
                    'status' => 'TODO',
                ]];
            }

            // Transcripción del dictado con Gemini (D-243): siempre con voz.
            if ($request->feature === AiFeature::DictationTranscription) {
                return ['hasMeaningfulSpeech' => true, 'transcription' => 'Transcripción de prueba generada sin IA (GEMINI_DRIVER=fake).', 'reason' => 'OK'];
            }

            if ($request->responseSchema === null) {
                if (preg_match('/TRANSCRIPCIÓN BRUTA:\n(.*?)\n\nINSTRUCCIONES:/s', $request->prompt, $match) === 1) {
                    return trim($match[1]);
                }

                return 'Texto de prueba generado sin IA (GEMINI_DRIVER=fake).';
            }

            // Una frase por persona o por cliente del INPUT_JSON (fichas de cliente y de persona, 10.4).
            if (isset($request->responseSchema['properties']['summaries']) && preg_match('/INPUT_JSON:\n(.+)\n/u', $request->prompt, $match) === 1) {
                $input = json_decode($match[1], true);

                return ['summaries' => array_values(array_map(fn (array $row): array => [
                    'memberId' => $row['memberId'] ?? null,
                    'clientId' => $row['clientId'] ?? null,
                    'summary' => 'Actividad de prueba generada sin IA (GEMINI_DRIVER=fake).',
                ], array_filter(is_array($input) ? $input : [], is_array(...))))];
            }

            $client = preg_match('/CLIENTE:\s*(.+)\n/u', $request->prompt, $match) === 1 ? trim($match[1]) : null;
            preg_match_all('/"clientKey": "([^"]+)"/', $request->prompt, $keys);

            return self::fromSchema($request->responseSchema, [
                'clientName' => $client ?? 'Cliente',
                'executiveSummary' => 'Resumen de prueba'.($client !== null ? " de {$client}" : '').' generado sin IA (GEMINI_DRIVER=fake).',
                'status' => 'On Track',
                'globalSummary' => 'Resumen global de prueba generado sin IA (GEMINI_DRIVER=fake).',
                'intro' => 'Hola equipo. Este es un audio de prueba.',
                'outro' => 'Y con esto cerramos el repaso de la semana.',
                'clients' => array_map(fn (string $key): array => ['clientKey' => $key, 'script' => 'Bloque de prueba.'], array_values(array_unique($keys[1]))),
                'sentiment' => 'NEUTRAL',
                'evidenceLevel' => 'NONE',
                'reasoning' => 'Sin evidencia: respuesta de prueba.',
                'confidence' => 0.5,
            ]);
        });
    }

    /**
     * Un valor con la forma del esquema; $values manda en las propiedades que tenga.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function fromSchema(array $schema, array $values): array
    {
        $result = [];

        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $property) {
            $type = is_array($property) ? ($property['type'] ?? 'STRING') : 'STRING';
            $result[$name] = $values[$name] ?? match ($type) {
                'ARRAY' => [],
                'NUMBER', 'INTEGER' => 0,
                'BOOLEAN' => false,
                'OBJECT' => [],
                default => 'Texto de prueba.',
            };
        }

        return $result;
    }

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
