<?php

use App\Domain\Reports\Export\KeysetPages;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| PERF-03: las exportaciones recorren las entradas por bloques con paginación por clave (fecha e id),
| no con OFFSET: una entrada creada o borrada entre dos bloques no repite ni pierde filas.
*/

beforeEach(function () {
    $this->user = User::factory()->employee()->create();
    $this->task = Task::factory()->create();
    $this->entry = fn (string $date): TimeEntry => TimeEntry::factory()->forTask($this->task)->on($date)->minutes(30)->create(['user_id' => $this->user->id]);

    foreach (['2026-09-01', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-03', '2026-09-04'] as $date) {
        ($this->entry)($date);
    }

    $this->ids = TimeEntry::query()->orderBy('date')->orderBy('id')->pluck('id')->all();
    $this->query = fn () => DB::table('time_entries')->select(['time_entries.id', 'time_entries.date']);
});

it('recorre todas las entradas en orden de fecha e id, en bloques de cualquier tamaño', function (int $size) {
    $ids = [];
    foreach (KeysetPages::byDateAndId(($this->query)(), $size) as $row) {
        $ids[] = (int) $row->id;
    }

    expect($ids)->toBe($this->ids);
})->with([1, 2, 4, 6, 100]);

it('una entrada creada antes del cursor o una leída que se borra entre bloques no repite ni pierde filas', function () {
    $write = function (): void {
        ($this->entry)('2026-08-31');          // antes de todo lo leído
        TimeEntry::query()->whereKey($this->ids[0])->delete(); // una ya leída
    };

    $ids = [];
    foreach (KeysetPages::byDateAndId(($this->query)(), 2) as $index => $row) {
        $ids[] = (int) $row->id;
        if ($index === 1) {
            $write();
        }
    }

    expect($ids)->toBe($this->ids);
});

it('con OFFSET (lo de antes: forPage y lazy) una escritura entre bloques repetía o perdía filas', function () {
    $read = function (Closure $write): array {
        $ids = [];
        foreach (($this->query)()->orderBy('date')->orderBy('id')->lazy(2) as $index => $row) {
            $ids[] = (int) $row->id;
            if ($index === 1) {
                $write();
            }
        }

        return $ids;
    };

    // Una entrada nueva antes del cursor: la segunda fila sale dos veces.
    $duplicated = $read(fn () => ($this->entry)('2026-08-31'));
    expect(array_count_values($duplicated)[$this->ids[1]])->toBe(2);

    // Una ya leída que se borra: la tercera no sale.
    TimeEntry::query()->where('date', '2026-08-31')->delete();
    $lost = $read(fn () => TimeEntry::query()->whereKey($this->ids[0])->delete());
    expect($lost)->not->toContain($this->ids[2]);
});
