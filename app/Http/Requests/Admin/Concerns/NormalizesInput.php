<?php

namespace App\Http\Requests\Admin\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Utilidades de los formularios de administración y clientes:
 * - importes escritos a la española («45,50» → «45.50»),
 * - nombres únicos sin distinguir mayúsculas (en PostgreSQL «Diseño» y «diseño» serían distintos).
 */
trait NormalizesInput
{
    /**
     * @param  list<string>  $keys
     */
    protected function normalizeDecimals(array $keys): void
    {
        $merge = [];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $value = str_replace([' ', '€'], '', trim($value));
                // «1.234,50» → «1234.50»; «45,5» → «45.5».
                if (str_contains($value, ',')) {
                    $value = str_replace(['.', ','], ['', '.'], $value);
                }
                $merge[$key] = $value === '' ? null : $value;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    protected function trimStrings(array $keys): void
    {
        $merge = [];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
                $merge[$key] = $value === '' ? null : $value;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Regla: no existe otro registro (sin borrar) con el mismo nombre, sin distinguir mayúsculas.
     *
     * @param  class-string<Model>  $model
     */
    protected function uniqueName(string $model, ?int $ignoreId, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $ignoreId, $message): void {
            if (! is_string($value)) {
                return;
            }

            /** @var Builder<Model> $query */
            $query = $model::query();

            $exists = $query
                ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($exists) {
                $fail($message);
            }
        };
    }
}
