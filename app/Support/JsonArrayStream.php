<?php

namespace App\Support;

use Generator;
use JsonException;
use RuntimeException;

/**
 * Lee un fichero JSON cuyo contenido es un array de objetos ([{…}, {…}, …]) elemento a elemento,
 * sin cargarlo entero en memoria: cada elemento se decodifica por separado. Para los exports
 * grandes (las tareas de ClickUp ocupan cientos de MB).
 */
final class JsonArrayStream
{
    /** @var resource */
    private $handle;

    private string $buffer = '';

    private int $pos = 0;

    /** Inicio del elemento en curso dentro del búfer (null entre elementos). */
    private ?int $start = null;

    private bool $eof = false;

    /**
     * @param  resource  $handle
     * @param  int<1, max>  $chunkBytes
     */
    private function __construct($handle, private readonly string $path, private readonly int $chunkBytes)
    {
        $this->handle = $handle;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     *
     * @throws RuntimeException si no se puede abrir el fichero o no es un array de objetos
     * @throws JsonException si un elemento no es JSON válido
     */
    public static function objects(string $path, int $chunkBytes = 1 << 20): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se puede abrir {$path}.");
        }

        try {
            yield from (new self($handle, $path, max($chunkBytes, 1)))->read();
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function read(): Generator
    {
        if ($this->skipWhitespace() !== '[') {
            throw new RuntimeException("{$this->path} no contiene un array JSON.");
        }
        $this->pos++;

        $index = 0;
        $depth = 0;

        while ($this->available()) {
            $this->pos += strcspn($this->buffer, '{}[]"', $this->pos);
            if ($this->pos >= strlen($this->buffer)) {
                continue;
            }

            $char = $this->buffer[$this->pos];

            if ($char === '"') {
                if ($depth === 0) {
                    throw new RuntimeException("{$this->path}: los elementos del array deben ser objetos.");
                }

                $this->skipString();

                continue;
            }

            if ($char === '{' || $char === '[') {
                if ($depth === 0) {
                    if ($char === '[') {
                        throw new RuntimeException("{$this->path}: los elementos del array deben ser objetos.");
                    }
                    $this->start = $this->pos;
                }

                $depth++;
                $this->pos++;

                continue;
            }

            // Cierre: el ] final del array o el de un elemento.
            if ($depth === 0) {
                return;
            }

            $depth--;
            $this->pos++;

            if ($depth === 0 && $this->start !== null) {
                /** @var array<string, mixed> $object */
                $object = json_decode(substr($this->buffer, $this->start, $this->pos - $this->start), true, 512, JSON_THROW_ON_ERROR);
                $this->start = null;

                yield $index++ => $object;
            }
        }
    }

    /**
     * Avanza hasta pasar la comilla que cierra la cadena que empieza en la posición actual.
     */
    private function skipString(): void
    {
        $this->pos++;

        while ($this->available()) {
            $this->pos += strcspn($this->buffer, '"\\', $this->pos);
            if ($this->pos >= strlen($this->buffer)) {
                continue;
            }

            if ($this->buffer[$this->pos] === '\\') {
                $this->pos += 2;

                continue;
            }

            $this->pos++;

            return;
        }
    }

    private function skipWhitespace(): ?string
    {
        while ($this->available()) {
            $this->pos += strspn($this->buffer, " \t\r\n", $this->pos);
            if ($this->pos < strlen($this->buffer)) {
                return $this->buffer[$this->pos];
            }
        }

        return null;
    }

    /**
     * ¿Queda algo por leer en la posición actual? Si el búfer se ha agotado, lee el siguiente
     * trozo conservando solo el elemento en curso.
     */
    private function available(): bool
    {
        while ($this->pos >= strlen($this->buffer)) {
            if ($this->eof) {
                return false;
            }

            $offset = $this->start ?? min($this->pos, strlen($this->buffer));
            $this->buffer = substr($this->buffer, $offset);
            $this->pos -= $offset;
            if ($this->start !== null) {
                $this->start = 0;
            }

            $chunk = fread($this->handle, $this->chunkBytes);
            if ($chunk === false || $chunk === '') {
                $this->eof = true;
            } else {
                $this->buffer .= $chunk;
            }
        }

        return true;
    }
}
