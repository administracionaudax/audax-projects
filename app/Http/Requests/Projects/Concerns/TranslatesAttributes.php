<?php

namespace App\Http\Requests\Projects\Concerns;

/**
 * Nombres de los campos en los mensajes de validación («El campo gestor principal…»), sacados de
 * lang/es/<área>.php → attributes.
 */
trait TranslatesAttributes
{
    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    protected function translatedAttributes(string $group, array $fields): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            $key = "{$group}.attributes.{$field}";
            $line = __($key);

            if (is_string($line) && $line !== $key) {
                $attributes[$field] = $line;
            }
        }

        return $attributes;
    }
}
