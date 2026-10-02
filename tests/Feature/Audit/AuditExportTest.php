<?php

use App\Http\Controllers\Admin\AuditController;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/*
| Exportación de la auditoría a CSV (D-074): los mismos filtros que la página, en streaming, con el
| límite de filas de las exportaciones de los informes (D-045) y los textos nunca como fórmulas.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    $this->csv = function (string $query = ''): array {
        $response = $this->actingAs($this->admin)->get('/admin/auditoria/exportar'.($query === '' ? '' : "?{$query}"))->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('text/csv')
            ->and($response->headers->get('Content-Disposition'))->toContain('auditoria-');

        $content = $response->streamedContent();

        expect(str_starts_with($content, "\u{FEFF}"))->toBeTrue();

        $lines = array_values(array_filter(explode("\n", substr($content, 3)), fn (string $line): bool => trim($line) !== ''));

        return array_map(fn (string $line): array => str_getcsv(rtrim($line, "\r"), ';', '"', ''), $lines);
    };
});

test('exporta a CSV con los filtros de la página, fechas en Madrid y el detalle en una línea', function () {
    $this->actingAs($this->admin);
    $project = Project::factory()->create(['name' => 'Web corporativa', 'code' => 'WEB', 'hourly_rate' => '60.00']);
    $project->update(['name' => '=HIPERVINCULO("http://mal")', 'hourly_rate' => '75.00']);
    Activity::query()->where('subject_type', Project::class)->update(['created_at' => CarbonImmutable::parse('2026-09-30 22:15:00', 'UTC')]);

    $rows = ($this->csv)('entidad=project&accion=updated');

    expect($rows[0])->toBe(['Fecha y hora', 'Persona', 'Entidad', 'Elemento', 'Acción', 'Cambios'])
        ->and($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe('01/10/2026 00:15')
        ->and($rows[1][1])->toBe('Ana Admin')
        ->and($rows[1][2])->toBe('Proyecto')
        ->and($rows[1][3])->toBe('WEB · =HIPERVINCULO("http://mal")')
        ->and($rows[1][4])->toBe('Cambio')
        ->and($rows[1][5])->toBe('Nombre: Web corporativa → =HIPERVINCULO("http://mal"); Tarifa por hora: 60,00 € → 75,00 €');

    // Sin persona: «Sistema». Un elemento que empieza por «=» se neutraliza para que la hoja de
    // cálculo no lo ejecute como fórmula.
    $task = Task::factory()->create(['project_id' => $project->id, 'title' => '=1+1']);
    Activity::query()->create(['log_name' => 'tasks', 'description' => 'updated', 'event' => 'updated', 'subject_type' => Task::class, 'subject_id' => $task->id]);

    $system = ($this->csv)('persona=sistema');

    expect($system)->toHaveCount(2)
        ->and(array_slice($system[1], 1))->toBe(['Sistema', 'Tarea', "'=1+1", 'Cambio', '']);
});

test('corta en el límite de filas y lo avisa en la última', function () {
    app()->when(AuditController::class)->needs('$maxRows')->give(4);

    foreach (range(1, 6) as $i) {
        Activity::query()->create(['log_name' => 'projects', 'description' => "cambio {$i}", 'event' => 'updated']);
    }

    $rows = ($this->csv)();

    expect($rows)->toHaveCount(5)
        ->and($rows[4][0])->toBe('Se han exportado las primeras 3 entradas. Acota los filtros para exportar el resto.');
});

test('recorre toda la auditoría por bloques sin repetir ni saltarse entradas', function () {
    $ids = collect(range(1, AuditController::CHUNK + 20))
        ->map(fn (int $i): int => Activity::query()->create(['log_name' => 'tasks', 'description' => 'updated', 'event' => 'updated'])->id);

    $rows = ($this->csv)('entidad=task');

    expect($rows)->toHaveCount($ids->count() + 1);
});
