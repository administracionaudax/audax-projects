<?php

namespace App\Domain\Chat\Links;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Cuerpo de respuesta en memoria con tope (512 KB, D-069). Cuando llega más, guarda solo lo que
 * cabe y devuelve 0 bytes escritos: cURL corta la descarga (error de escritura) y nunca se baja
 * más del tope. Se usa como «sink» de la petición a través de GuzzleHttp\Psr7\StreamWrapper.
 */
final class LimitedBody implements StreamInterface
{
    private string $buffer = '';

    private int $position = 0;

    private bool $overflowed = false;

    public function __construct(private readonly int $limit) {}

    public function contents(): string
    {
        return $this->buffer;
    }

    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    public function write(string $string): int
    {
        $room = $this->limit - strlen($this->buffer);

        if ($room <= 0) {
            $this->overflowed = true;

            return 0;
        }

        $chunk = substr($string, 0, $room);
        $this->buffer .= $chunk;
        $this->position = strlen($this->buffer);

        if (strlen($string) > $room) {
            $this->overflowed = true;

            return 0;
        }

        return strlen($chunk);
    }

    public function __toString(): string
    {
        return $this->buffer;
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->buffer);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->buffer) + $offset,
            default => $offset,
        };

        if ($target < 0) {
            throw new RuntimeException('Posición no válida.');
        }

        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->buffer, $this->position, max(0, $length));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        return $this->read(strlen($this->buffer));
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
