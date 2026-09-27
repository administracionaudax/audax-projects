<?php

namespace App\Domain\Admin;

use App\Domain\Reports\ReportCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Orden manual de catálogos (tipos de tarea y estados) con los botones «subir» y «bajar»: intercambia
 * el elemento con su vecino y deja las posiciones consecutivas (0, 1, 2…), aunque antes hubiera
 * empates (p. ej. varios en 0).
 */
final class Reorderer
{
    public const string UP = 'up';

    public const string DOWN = 'down';

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $ordered  Consulta ya ordenada (position y desempate estable).
     * @param  TModel  $item
     * @return bool false si ya estaba en el extremo.
     */
    public function move(Builder $ordered, Model $item, string $direction): bool
    {
        return DB::transaction(function () use ($ordered, $item, $direction): bool {
            /** @var list<int> $ids */
            $ids = $ordered->lockForUpdate()->pluck($item->getKeyName())->map(fn ($id): int => (int) $id)->values()->all();
            $index = array_search((int) $item->getKey(), $ids, true);

            if ($index === false) {
                return false;
            }

            $target = $direction === self::UP ? $index - 1 : $index + 1;

            if ($target < 0 || $target >= count($ids)) {
                return false;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            $model = $item->newQuery();
            foreach ($ids as $position => $id) {
                $model->clone()->whereKey($id)->where('position', '!=', $position)->update(['position' => $position]);
            }

            // El orden de estados y tipos sale en los informes: su caché, tras el commit (INT-03).
            ReportCache::bumpAfterCommit();

            return true;
        });
    }

    /**
     * Posición para un elemento nuevo: al final.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function next(Builder $query): int
    {
        $max = $query->max('position');

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
