<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Domain\Privacy\Export\PersonalDataSection;
use App\Support\LocalTime;
use Carbon\CarbonInterface;

/**
 * Base de las secciones del ZIP: los textos salen de lang/es/privacy.php
 * (export.sections.{sección}.description y export.sections.{sección}.columns.{columna}).
 */
abstract class Section implements PersonalDataSection
{
    /** Clave de la sección en lang/es/privacy.php (export.sections.*). */
    abstract protected function textKey(): string;

    /**
     * Columnas de la sección, en orden.
     *
     * @return list<string>
     */
    abstract protected function columnKeys(): array;

    public function description(): string
    {
        return self::text("privacy.export.sections.{$this->textKey()}.description");
    }

    public function columns(): array
    {
        $columns = [];

        foreach ($this->columnKeys() as $column) {
            $columns[$column] = self::text("privacy.export.sections.{$this->textKey()}.columns.{$column}");
        }

        return $columns;
    }

    /** Instante en ISO 8601 con la hora de Madrid (2026-09-27T14:05:00+02:00). */
    protected static function instant(?CarbonInterface $value): ?string
    {
        return $value?->toImmutable()->setTimezone(LocalTime::timezone())->toIso8601String();
    }

    protected static function date(?CarbonInterface $value): ?string
    {
        return $value?->toDateString();
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
