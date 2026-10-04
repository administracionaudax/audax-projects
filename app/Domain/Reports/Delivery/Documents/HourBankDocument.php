<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Pdf\HourBankStatement;
use App\Domain\Reports\Pdf\HourBankStatementView;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Consumo de una bolsa (/proyectos/{project}/bolsas/{hourBank}/pdf, D-045; Fase 9, D-140): el PDF
 * para el cliente (sin importes) o, con ?importes=1 y view-financials, el de uso interno, con la
 * hoja de documentos de Audax (HourBankStatementView); en Excel y CSV, el detalle de las horas
 * aprobadas o bloqueadas con su total. Quién: HourBankPolicy::downloadPdf. La bolsa tiene que ser
 * del proyecto de la ruta.
 */
final class HourBankDocument extends BaseDocument
{
    public function __construct(private readonly HourBankStatement $statement) {}

    public function title(ReportRequest $request, User $as): string
    {
        [, $bank] = $this->authorized($request, $as);

        return self::joinTitle(self::t('reports.r2.pdf.title'), $bank->name);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        [$project, $bank] = $this->authorized($request, $as);
        $statement = $this->statement->build($as, $bank);
        $c = fn (string $key): string => self::t('report_pdf.hour_bank.columns.'.$key);

        $rows = array_map(fn (array $entry): array => [
            $entry['date'],
            $entry['person'],
            $entry['task'],
            TableExporter::hours($entry['in_bank']),
            TableExporter::hours($entry['overage']),
            $entry['description'],
            $entry['in_bank'],
            $entry['overage'],
        ], $statement['entries']);
        $rows[] = [self::t('report_pdf.total'), '', '', TableExporter::hours($statement['figures']['in_bank']), TableExporter::hours($statement['figures']['overage']), '',
            $statement['figures']['in_bank'], $statement['figures']['overage']];

        return new ExportTable(
            self::t('report_pdf.hour_bank.export_name', ['code' => $project->code, 'bank' => $bank->name]),
            [$c('date'), $c('person'), $c('task'), $c('in_bank'), $c('overage'), $c('description'), $c('in_bank_minutes'), $c('overage_minutes')],
            $rows,
            self::joinTitle(self::t('reports.r2.pdf.title'), $bank->name),
        );
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        [$project, $bank] = $this->authorized($request, $as);
        $importes = $request->query['importes'] ?? null;
        $data = $this->statement->build($as, $bank, withFinancials: in_array($importes, ['1', 'true', 'on', 'yes', 1, true], true));

        return HourBankStatementView::make($data, $as->name, self::filenameFor($project, $bank, $data['financials'] !== null));
    }

    /**
     * «ARR-WEB-consumo-bolsa-diseno-2026-09-27» (sin extensión): código del proyecto (en
     * mayúsculas, como en la app), nombre de la bolsa y fecha, solo con caracteres ASCII seguros.
     * El de uso interno (con importes) lleva «-interno» delante de la fecha.
     */
    public static function filenameFor(Project $project, HourBank $bank, bool $internal = false): string
    {
        $code = (string) preg_replace('/[^A-Z0-9-]+/', '-', Str::upper(Str::ascii($project->code)));

        return trim($code, '-').'-consumo-'.Str::slug($bank->name, '-', 'es').($internal ? '-interno' : '').'-'.LocalTime::todayString();
    }

    /**
     * @return array{0: Project, 1: HourBank}
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        $project = self::routeModel($request, 'project', Project::class);
        $bank = self::routeModel($request, 'hourBank', HourBank::class);

        if ($bank->project_id !== $project->id) {
            throw new AuthorizationException(self::t('report_pdf.errors.missing'));
        }

        self::authorizeFor($as, 'downloadPdf', $bank);

        return [$project, $bank];
    }
}
