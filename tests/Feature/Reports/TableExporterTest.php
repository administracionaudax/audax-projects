<?php

use App\Domain\Reports\Export\TableExporter;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/*
| Exportación de tablas (D-045): XLSX con números reales y CSV para Excel en español.
*/

beforeEach(function () {
    $this->headers = ['Proyecto', 'Horas', 'Ingreso', 'Facturable'];
    $this->rows = [
        ['ARR-WEB · Web corporativa', TableExporter::hours(90), TableExporter::money('1234.50'), true],
        ['Interno – Agencia', TableExporter::hours(20), null, false],
    ];
});

it('escribe un XLSX con cabecera y valores numéricos', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    expect(app(TableExporter::class)->write($path, $this->headers, $this->rows, 'xlsx'))->toBe(2);

    $reader = new XlsxReader;
    $reader->open($path);
    $read = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $read[] = $row->toArray();
        }
    }
    $reader->close();
    unlink($path);

    expect($read[0])->toBe($this->headers)
        ->and($read[1][0])->toBe('ARR-WEB · Web corporativa')
        ->and($read[1][1])->toBe(1.5)
        ->and($read[1][2])->toBe(1234.5)
        ->and($read[2][1])->toBe(0.33);
});

it('escribe un CSV con punto y coma, BOM y decimales con coma', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv');
    app(TableExporter::class)->write($path, $this->headers, $this->rows, 'csv');
    $content = (string) file_get_contents($path);
    unlink($path);

    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($content)->toContain('Proyecto;Horas;Ingreso;Facturable')
        ->and($content)->toContain('"ARR-WEB · Web corporativa";1,50;1234,50;Sí')
        ->and($content)->toContain(';0,33;;No');
});

it('descarga con nombre, tipo y cabeceras seguras', function () {
    $response = app(TableExporter::class)->download('Informe de dirección', $this->headers, $this->rows, 'csv');

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toContain('informe-de-direccion-')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(TableExporter::format('pdf'))->toBe('xlsx');
});
