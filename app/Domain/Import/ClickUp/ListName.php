<?php

namespace App\Domain\Import\ClickUp;

/**
 * Nombre de una lista de ClickUp según el patrón de la agencia (D-135):
 * `TIPO+N - Hh - descripción - Fxxxxxx [Cliente]`, con un emoji delante. Por ejemplo
 * «🏨 BH6 - 50h - F260170 [Kora Travel]» o «🍊 FE2 - ??h - Podcast [Naranjasyfrutas]».
 * Todas las partes salvo el código son opcionales; una lista sin código (p. ej. «Auditoría UX»)
 * solo tiene nombre.
 */
final readonly class ListName
{
    public function __construct(
        /** Nombre limpio, sin emoji, sin el cliente entre corchetes y sin el código F. */
        public string $name,
        /** Código de tipo en mayúsculas (BH, FE, WE…) o null si la lista no sigue el patrón. */
        public ?string $code,
        public ?int $number,
        /** Horas del nombre; null si no las tiene, son «??» o son 0. */
        public ?int $hours,
        /** El nombre trae «??h»: horas sin definir. */
        public bool $hoursUnknown,
        /** Código de factura (F250309). */
        public ?string $invoiceReference,
        public ?string $description,
        /** Cliente entre corchetes, si lo hay. */
        public ?string $client,
    ) {}

    public static function parse(string $raw): self
    {
        $clean = self::stripEmoji($raw);
        $client = null;

        if (preg_match('/\s*\[([^\]]*)\]\s*$/u', $clean, $match) === 1) {
            $client = trim($match[1]) !== '' ? trim($match[1]) : null;
            $clean = trim(mb_substr($clean, 0, mb_strlen($clean) - mb_strlen($match[0])));
        }

        if (preg_match('/^([A-Z]{2})(\d+)\b(.*)$/u', $clean, $match) !== 1) {
            return new self($clean !== '' ? $clean : $raw, null, null, null, false, null, null, $client);
        }

        $code = $match[1];
        $number = (int) $match[2];
        $rest = trim($match[3], " -\t");
        $parts = $rest === '' ? [] : array_map(fn (string $part): string => trim($part, " -\t"), preg_split('/\s+-\s+/u', $rest) ?: []);
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

        $hours = null;
        $hoursUnknown = false;

        if ($parts !== [] && preg_match('/^(\d+(?:[.,]\d+)?|\?+)\s*h\b\s*(.*)$/iu', $parts[0], $hoursMatch) === 1) {
            if (str_starts_with($hoursMatch[1], '?')) {
                $hoursUnknown = true;
            } else {
                $value = (int) round((float) str_replace(',', '.', $hoursMatch[1]));
                $hours = $value > 0 ? $value : null;
            }

            $leftover = trim($hoursMatch[2]);
            if ($leftover === '') {
                array_shift($parts);
            } else {
                $parts[0] = $leftover;
            }
        }

        $invoice = null;
        $descriptionParts = [];
        foreach ($parts as $part) {
            if (preg_match('/^F\d{5,8}$/u', $part) === 1) {
                $invoice = $part;
            } else {
                $descriptionParts[] = $part;
            }
        }

        $description = $descriptionParts === [] ? null : implode(' - ', $descriptionParts);

        $name = $code.$number;
        if ($hours !== null) {
            $name .= ' - '.$hours.'h';
        }
        if ($description !== null) {
            $name .= ' - '.$description;
        }

        return new self($name, $code, $number, $hours, $hoursUnknown, $invoice, $description, $client);
    }

    /**
     * Quita emoji, banderas, modificadores de tono y selectores de variación, y los espacios sobrantes.
     */
    public static function stripEmoji(string $text): string
    {
        $text = (string) preg_replace('/[\p{So}\p{Sk}\p{Cf}\p{Cs}\p{Co}\x{FE0E}\x{FE0F}\x{20E3}]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public function isHourBank(): bool
    {
        return $this->code === 'BH';
    }

    public function isMonthlyFee(): bool
    {
        return $this->code === 'FE';
    }
}
