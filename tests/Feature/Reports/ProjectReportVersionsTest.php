<?php

use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use Illuminate\Auth\Access\AuthorizationException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Feature\Reports\R2Scenario;

/*
| Informe de un proyecto en sus dos versiones (D-240 a D-242) sobre el escenario de R2 (semana del
| 21 al 27/09/2026, NAN-WEB): E1 Ana 300 min aprobados en la subtarea S1, E2 Luis 400 bloqueados
| en T2, E3 Ana 90 en borrador en T1 (y E0, 300 aprobados en agosto, en la bolsa B0).
| - Interno (completo): todo lo de la página más resumen, matriz tarea × persona, meses, bolsas
|   (HourBankLedger), entradas y, solo con view-financials, costes y margen. Excel: un libro.
| - Para el cliente: solo las horas que vería en el portal, sin importes ni borradores, con las
|   personas como las ve él y las bolsas con las cifras del portal (PortalBankFigures).
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    $this->generator = app(ReportFileGenerator::class);
    $this->request = fn (array $query = []): ReportRequest => new ReportRequest(ReportKind::Project, ['project' => $this->s->web->id],
        ['periodo' => 'semana', 'fecha' => '2026-09-21', ...$query]);

    // Franja de E1 (D-172): de 09:00 a 14:00 en Madrid.
    $this->s->e1->forceFill(['started_at' => '2026-09-22 07:00:00', 'ended_at' => '2026-09-22 12:00:00'])->save();

    $this->html = function (array $query, $as): string {
        $file = $this->generator->generate(($this->request)($query), ExportFormat::Pdf, $as);
        $html = (string) file_get_contents($file->path);
        @unlink($file->path);

        return $html;
    };

    /** Libro de Excel: [nombre de la hoja => filas]. */
    $this->workbook = function (array $query, $as): array {
        $file = $this->generator->generate(($this->request)($query), ExportFormat::Xlsx, $as);
        $reader = new XlsxReader;
        $reader->open($file->path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();
        @unlink($file->path);

        return $sheets;
    };
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/audax-report-'.getmypid().'-*') ?: [] as $file) {
        @unlink($file);
    }
});

test('interno: el PDF lleva el resumen, la matriz, los meses, las bolsas, los costes y todas las entradas', function () {
    $html = ($this->html)([], $this->s->admin);
    $text = reportHtmlText($html);

    expect($text)
        ->toContain('Informe de proyecto · NAN-WEB')
        ->toContain('Interna y completa: no la envíes al cliente')
        ->toContain('Resumen del proyecto')
        ->toContain('Horas reales del proyecto (toda su vida) 18:10')
        ->toContain('Horas por persona')
        ->toContain('Estimado frente a real por tarea')
        ->toContain('↳ Versión móvil')
        ->toContain('Horas por tarea y persona')
        ->toContain('Horas por semana')
        ->toContain('Horas por mes')
        ->toContain('Septiembre de 2026')
        ->toContain('Horas por tipo de tarea')
        ->toContain('Bolsas del proyecto')
        ->toContain('Bolsa Diseño ñ')
        ->toContain('Costes y margen')
        ->toContain('Entradas de horas')
        // Las tres entradas de la semana, también el borrador, con su franja y su estado.
        ->toContain('¿Qué tal? ¡Sí! 12 €')
        ->toContain('Maquetación de cabecera')
        ->toContain('Borrador que no sale en el PDF')
        ->toContain('09:00–14:00')
        ->toContain('Borrador')
        ->toContain('Bloqueada');

    // B1 según HourBankLedger: 790 consumidos, 600 dentro y 190 de exceso (+3:10), saldo 0.
    expect($text)->toMatch('/Bolsa Diseño ñ Agotada 01\/09\/2026 10:00 10:00 \+3:10 0:00 131,7 % 13:10 1\.000,00 €/u')
        // Costes: Luis 400 min (200 €), Ana 390 min (130 €); total 330 € frente a 1.196,67 €.
        ->toMatch('/Luis 6:40 30,00 € 200,00 € 666,67 € 466,67 € 70,0 % Ana 6:30 20,00 € 130,00 € 530,00 € 400,00 € 75,5 %/u')
        ->toMatch('/Total 13:10 25,06 € 330,00 € 1\.196,67 € 866,67 € 72,4 %/u')
        ->and($html)->toContain('<body class="report report--landscape">');
});

test('interno sin view-financials: todo menos la sección económica', function () {
    // Gema gestiona el proyecto, pero no ve datos económicos.
    $text = reportHtmlText(($this->html)([], $this->s->gema));

    expect($text)->toContain('Horas por tarea y persona')
        ->toContain('Entradas de horas')
        ->toContain('Borrador que no sale en el PDF')
        ->toContain('Datos económicos No incluidos')
        ->not->toContain('Costes y margen')
        ->not->toContain('Coste/h')
        ->not->toContain('Ingreso estimado')
        ->not->toContain('Tarifa');

    $sheets = ($this->workbook)([], $this->s->gema);
    expect(array_keys($sheets))->not->toContain('Costes y margen')
        ->and(json_encode($sheets))->not->toContain('€');
});

test('interno: el Excel es un libro con una hoja por sección, sin fórmulas', function () {
    $s = $this->s;
    $s->t2->forceFill(['title' => '=cmd|\'/C calc\'!A0'])->save();
    $sheets = ($this->workbook)([], $s->admin);

    expect(array_keys($sheets))->toBe(['Resumen', 'Tareas', 'Estimado por tipo', 'Personas', 'Tarea x persona', 'Semanas', 'Meses',
        'Tipos de tarea', 'Bolsas', 'Entradas', 'Costes y margen']);

    $summary = array_column($sheets['Resumen'], 1, 0);
    expect($summary['Proyecto'])->toBe('NAN-WEB · Web corporativa')
        ->and($summary['Horas imputadas en el periodo'])->toBe(13.17)
        ->and($summary['Horas reales del proyecto (toda su vida)'])->toBe(18.17)
        ->and($summary['Horas contratadas en bolsas'])->toBe(15)
        ->and($summary['Ingreso estimado del periodo'])->toBe(1196.67)
        ->and($summary['Coste del periodo'])->toBe(330);

    // Matriz: Luis en T2, Ana en T1 (su subtarea S1 suma en la principal, también el borrador E3).
    expect($sheets['Tarea x persona'][0])->toBe(['Tarea', 'Luis', 'Ana', 'Total (horas)'])
        ->and($sheets['Tarea x persona'][1])->toBe(['=cmd|\'/C calc\'!A0', 6.67, '', 6.67])
        ->and($sheets['Tarea x persona'][2])->toBe(['Diseño de la home', '', 6.5, 6.5])
        ->and($sheets['Tarea x persona'][3])->toBe(['Total', 6.67, 6.5, 13.17]);

    expect($sheets['Entradas'][0])->toBe(['Fecha', 'Persona', 'Tarea', 'Tarea principal', 'Inicio', 'Fin', 'Horas', 'Minutos', 'Descripción', 'Facturable', 'Estado'])
        ->and($sheets['Entradas'][1])->toBe(['2026-09-22', 'Ana', 'Versión móvil', 'Diseño de la home', '09:00', '14:00', 5, 300, '¿Qué tal? ¡Sí! 12 €', true, 'Aprobada'])
        ->and($sheets['Entradas'])->toHaveCount(4)
        ->and($sheets['Entradas'][3][10])->toBe('Borrador');

    expect($sheets['Bolsas'][2])->toBe(['Bolsa Diseño ñ', 'Agotada', '2026-09-01', '', 10, 13.17, 10, 3.17, 0, 131.7, 13.17, 1000, 70])
        ->and($sheets['Meses'][1])->toBe(['2026-09', 13.17, 13.17, 10, 3.17, 1196.67, 330])
        ->and($sheets['Costes y margen'][0])->toBe(['Persona', 'Horas imputadas', 'Coste medio (€/h)', 'Coste (€)', 'Ingreso estimado (€)', 'Rentabilidad (€)', 'Margen (%)'])
        ->and(end($sheets['Costes y margen']))->toBe(['Total', 13.17, 25.06, 330, 1196.67, 866.67, 72.4]);

    // Ninguna hoja lleva fórmulas (los textos van siempre como texto).
    $file = $this->generator->generate(($this->request)(), ExportFormat::Xlsx, $s->admin);
    $zip = new ZipArchive;
    $zip->open($file->path);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        expect((string) $zip->getFromIndex($i))->not->toContain('<f>');
    }
    $zip->close();
    @unlink($file->path);

    // El CSV es una tabla: sin ?tabla=, las tareas; con ella, la que se pide.
    $csv = $this->generator->generate(($this->request)(['tabla' => 'entradas']), ExportFormat::Csv, $s->admin);
    expect((string) file_get_contents($csv->path))->toContain('Borrador que no sale en el PDF')->toContain('09:00;14:00');
    @unlink($csv->path);
});

test('para el cliente: solo sus horas visibles, sin importes, sin borradores y con las cifras del portal', function () {
    $s = $this->s;
    $html = ($this->html)(['version' => 'cliente'], $s->admin);
    $text = reportHtmlText($html);

    expect($text)
        ->toContain('Informe de proyecto para el cliente · NAN-WEB')
        ->toContain('Incluye solo las horas aprobadas.')
        ->toContain('Horas del periodo 11:40')
        ->toContain('Horas acumuladas 16:40')
        ->toContain('Bolsas de horas')
        // B1 en el portal (D-092): dentro 600, exceso 100 (E3, borrador, no cuenta), saldo 0, agotada.
        ->toMatch('/Bolsa Diseño ñ Agotada 01\/09\/2026 10:00 10:00 \+1:40 0:00 116,7 % 11:40/u')
        ->toMatch('/Maquetación 6:40 Diseño de la home 5:00 ↳ Versión móvil 5:00 Total 11:40/u')
        ->toMatch('/Luis 6:40 57,1 % Ana 5:00 42,9 %/u')
        ->toContain('¿Qué tal? ¡Sí! 12 €')
        ->toContain('09:00–14:00')
        ->toContain('Maquetación de cabecera')
        ->not->toContain('Borrador que no sale en el PDF')
        ->not->toContain('Borrador')
        ->not->toContain('Bloqueada')
        ->not->toContain('Coste')
        ->not->toContain('Ingreso')
        ->not->toContain('Rentabilidad')
        ->not->toContain('Tarifa')
        ->not->toContain('Estimad')
        ->not->toContain('Datos económicos')
        ->not->toContain('1.000,00')
        ->and($html)->toContain('<body class="report">');

    // Ningún importe: el único «€» es el de la descripción que escribió Ana.
    expect(substr_count($text, '€'))->toBe(1);

    $sheets = ($this->workbook)(['version' => 'cliente'], $s->admin);
    expect(array_keys($sheets))->toBe(['Resumen', 'Tareas', 'Personas', 'Tipos de tarea', 'Semanas', 'Meses', 'Bolsas', 'Entradas'])
        ->and($sheets['Personas'])->toBe([['Persona', 'Horas', 'Minutos'], ['Luis', 6.67, 400], ['Ana', 5, 300], ['Total', 11.67, 700]])
        ->and($sheets['Entradas'][0])->toBe(['Fecha', 'Persona', 'Tarea', 'Tarea principal', 'Inicio', 'Fin', 'Horas', 'Minutos', 'Descripción'])
        ->and($sheets['Entradas'])->toHaveCount(3)
        ->and($sheets['Bolsas'][2])->toBe(['Bolsa Diseño ñ', 'Agotada', '2026-09-01', '', 10, 10, 1.67, 0, 116.7, 11.67]);

    $json = json_encode($sheets, JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('Borrador')->not->toContain('Coste')->not->toContain('Ingreso')->not->toContain('Tarifa')
        ->not->toContain('Precio')->not->toContain('Estimad')->not->toContain('1196')->not->toContain('Facturable');
});

test('para el cliente: las personas como las ve él (iniciales o «Equipo») y las horas que ve (enviadas)', function () {
    $s = $this->s;
    TimeEntry::factory()->forTask($s->t2)->on('2026-09-25')->minutes(60)->status(TimeEntryStatus::Submitted)->create([
        'user_id' => $s->luis->id, 'description' => 'Enviada sin aprobar',
    ]);

    $s->client->update(['portal_person_display' => PortalPersonDisplay::Initials]);
    $text = reportHtmlText(($this->html)(['version' => 'cliente'], $s->admin));
    expect($text)->toMatch('/L\. 6:40 57,1 % A\. 5:00 42,9 %/u')
        ->not->toContain('Luis')->not->toContain('Ana ')
        ->not->toContain('Enviada sin aprobar');

    $s->client->update(['portal_person_display' => PortalPersonDisplay::Team, 'portal_entry_visibility' => PortalEntryVisibility::Submitted]);
    $text = reportHtmlText(($this->html)(['version' => 'cliente'], $s->admin));
    expect($text)->not->toContain('Horas por persona')
        ->toContain('Equipo')
        ->toContain('Incluye las horas enviadas y las aprobadas.')
        ->toContain('Enviada sin aprobar')
        ->toContain('Horas del periodo 12:40')
        ->not->toContain('Luis')
        ->not->toContain('Borrador que no sale en el PDF');

    $sheets = ($this->workbook)(['version' => 'cliente'], $s->admin);
    expect(array_keys($sheets))->not->toContain('Personas')
        ->and(array_unique(array_column(array_slice($sheets['Entradas'], 1), 1)))->toBe(['Equipo']);
});

test('permisos: la versión para el cliente, solo quien ve todas las horas del proyecto', function () {
    $s = $this->s;

    // Gema (gestora) y el admin sí; Raúl, responsable que solo ve las de su equipo, no.
    expect(($this->html)(['version' => 'cliente'], $s->gema))->toContain('Informe de proyecto para el cliente');
    expect(fn () => $this->generator->generate(($this->request)(['version' => 'cliente']), ExportFormat::Pdf, $s->raul))
        ->toThrow(AuthorizationException::class);

    $url = "/informes/proyectos/{$s->web->id}?".R2Scenario::week(['version' => 'cliente']);
    $this->actingAs($s->raul)->get($url.'&formato=pdf')->assertForbidden();
    $this->actingAs($s->raul)->get($url.'&formato=imprimir')->assertForbidden();
    $this->actingAs($s->ana)->get($url.'&formato=xlsx')->assertForbidden();
    $this->actingAs($s->gema)->get($url.'&formato=xlsx')->assertOk();

    // Raúl sí saca el interno, con las horas de su equipo (Ana y Luis son de Diseño).
    $this->actingAs($s->raul)->get("/informes/proyectos/{$s->web->id}?".R2Scenario::week(['formato' => 'pdf']))->assertOk();

    // Una versión desconocida es la interna.
    expect(reportHtmlText(($this->html)(['version' => 'otra'], $s->admin)))->toContain('Interna y completa');
});
