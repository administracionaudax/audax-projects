<?php

namespace App\Domain\People\Reports;

use App\Domain\Identity\CompanyIdentity;
use App\Domain\People\RegisterHasher;
use App\Domain\People\RegisterIntegrity;
use App\Domain\Reports\Export\TableExporter;
use App\Models\InspectionAccess;
use App\Models\MonthClose;
use App\Models\OvertimeDecision;
use App\Models\RegisterAnchor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Exportación para la Inspección de Trabajo (PLAN-FASE-11 §11.3; D-353; W-088 y W-089): un ZIP de
 * un periodo y un conjunto de personas, listo al momento (art. 50 LISOS: no facilitarlo en la
 * visita es obstrucción), con lo que se lee y lo que se trata:
 *
 * - `registro-de-la-jornada.pdf` y `.csv`: el registro diario (entrada, salida, tramos, comida,
 *   trabajado y horas ordinarias, extra y complementarias),
 * - `fichajes.csv`: cada fila de la cadena, también las anulaciones, con su huella y la anterior,
 * - `correcciones.csv`: el historial completo (original, anulado y añadido, autor, fecha, motivo y
 *   conformidad o discrepancia),
 * - `presencia-diaria.csv`, `cierres-mensuales.csv` (confirmaciones, desacuerdos y
 *   desconfirmaciones), `horas-extra.csv` (todas las decisiones, también las sustituidas) y
 *   `anclas.csv` (el ancla diaria del periodo),
 * - `integridad.txt` (la comprobación de la cadena al generarlo), `LEEME.txt` y `SHA256SUMS.txt`.
 *
 * El ZIP queda anotado con su SHA-256 (RegisterFiles::record) y en la auditoría.
 */
final class InspectionExport
{
    public function __construct(
        private readonly PeopleReports $reports,
        private readonly RegisterFiles $files,
        private readonly TableExporter $exporter,
        private readonly RegisterIntegrity $integrity,
        private readonly CompanyIdentity $identity,
    ) {}

    /**
     * Genera el ZIP en un temporal (quien lo pide lo borra al enviarlo).
     */
    public function build(RegisterScope $scope, ?User $by, ?InspectionAccess $access = null): RegisterFile
    {
        $dir = sys_get_temp_dir().'/audax-itss-'.getmypid().'-'.Str::random(8);

        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException('No se puede crear el directorio temporal de la exportación.');
        }

        $zipPath = $dir.'.zip';
        $now = CarbonImmutable::now();
        $actor = $by->name ?? $access->name ?? '';

        try {
            $register = $this->reports->monthlyRegister($scope, 'itss_register', (string) __('people.inspection.export.register_title'));
            $files = [];

            $files['registro-de-la-jornada.'.$this->files->extension()] = $this->files->pdf($register, RegisterHasher::contentHash($register->content()), $now, $actor);
            $files['registro-de-la-jornada.csv'] = $this->csv($dir, $register->headers, $register->rows);

            $punches = $this->reports->punches($scope);
            $files['fichajes.csv'] = $this->csv($dir, $punches->headers, $punches->rows);

            $corrections = array_map(fn ($correction): array => PeopleReports::correctionRow($correction), $this->reports->correctionsOf($scope));
            $files['correcciones.csv'] = $this->csv($dir, self::headers(PeopleReports::CORRECTION_COLUMNS), $corrections);

            $presence = $this->reports->dailyPresence($scope);
            $files['presencia-diaria.csv'] = $this->csv($dir, $presence->headers, $presence->rows);

            $files['cierres-mensuales.csv'] = $this->csv($dir, self::headers(['person', 'month', 'version', 'status', 'generated_at', 'confirmed_at', 'disagreed_at', 'disagreement_note', 'reopened_at', 'reopened_by', 'reopen_reason', 'worked_min', 'expected_min', 'overtime_min', 'pdf_sha256', 'content_hash']), $this->closes($scope));
            $files['horas-extra.csv'] = $this->csv($dir, self::headers(['person', 'date', 'hour_type', 'excess_min', 'overtime_min', 'flex_min', 'destination', 'decided_by', 'decided_at', 'supersedes', 'note', 'hash']), $this->decisions($scope));
            $files['anclas.csv'] = $this->csv($dir, self::headers(['date', 'digest', 'prev_digest', 'events_count', 'verified_ok']), $this->anchors($scope));

            $check = $this->integrity->verify(array_map(fn (User $user): int => $user->id, $scope->users));
            $files['integridad.txt'] = $this->integrityText($check, $now);

            $sums = [];
            foreach ($files as $name => $contents) {
                $sums[$name] = hash('sha256', $contents);
            }

            $files['LEEME.txt'] = $this->readme($scope, $now, $actor, $sums);
            $sums['LEEME.txt'] = hash('sha256', $files['LEEME.txt']);
            $files['SHA256SUMS.txt'] = implode('', array_map(fn (string $name, string $sum): string => "{$sum}  {$name}\n", array_keys($sums), $sums));

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se ha podido crear el ZIP de la exportación para la Inspección.');
            }

            foreach ($files as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            $zip->close();
        } catch (Throwable $e) {
            @unlink($zipPath);

            throw $e;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        $sha = (string) hash_file('sha256', $zipPath);
        $contentHash = RegisterHasher::contentHash($sums);
        $filename = Str::slug('registro-de-jornada-inspeccion-'.$scope->from.'-'.$scope->to, '-', 'es').'.zip';
        $this->files->record('itss', 'zip', $scope->params(), $filename, $sha, $contentHash, (int) filesize($zipPath), $by, $access, (string) __('people.inspection.export.title'));

        return new RegisterFile($zipPath, $filename, 'application/zip', null, $sha, $contentHash, (string) __('people.inspection.export.title'));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|bool|null>>  $rows
     */
    private function csv(string $dir, array $headers, array $rows): string
    {
        $path = $dir.'/'.Str::random(12).'.csv';
        $this->exporter->write($path, $headers, $rows, 'csv');
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function closes(RegisterScope $scope): array
    {
        return array_values(MonthClose::query()
            ->with(['user:id,name', 'reopener:id,name'])
            ->whereIn('user_id', array_map(fn (User $user): int => $user->id, $scope->users))
            ->whereBetween('month', [substr($scope->from, 0, 7).'-01', $scope->to])
            ->orderBy('user_id')
            ->orderBy('month')
            ->orderBy('version')
            ->get()
            ->map(fn (MonthClose $close): array => [
                $close->user->name,
                $close->monthKey(),
                $close->version,
                $close->status->label(),
                PeopleFormat::dateTime($close->generated_at),
                PeopleFormat::dateTime($close->confirmed_at),
                PeopleFormat::dateTime($close->disagreed_at),
                $close->disagreement_note ?? '',
                PeopleFormat::dateTime($close->reopened_at),
                $close->reopener->name ?? '',
                $close->reopen_reason ?? '',
                $close->worked_minutes,
                $close->expected_minutes,
                $close->overtime_minutes,
                $close->pdf_sha256,
                $close->content_hash,
            ])
            ->all());
    }

    /**
     * Todas las decisiones de horas extra del periodo, también las sustituidas.
     *
     * @return list<list<string|int|null>>
     */
    private function decisions(RegisterScope $scope): array
    {
        return array_values(OvertimeDecision::query()
            ->with(['user:id,name', 'decider:id,name'])
            ->whereIn('user_id', array_map(fn (User $user): int => $user->id, $scope->users))
            ->whereBetween('date', [$scope->from, $scope->to])
            ->orderBy('user_id')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (OvertimeDecision $decision): array => [
                $decision->user->name,
                $decision->date->toDateString(),
                $decision->hour_type->label(),
                $decision->excess_minutes,
                $decision->overtime_minutes,
                $decision->flex_minutes,
                $decision->destination?->label() ?? '',
                $decision->decider->name,
                PeopleFormat::dateTime($decision->created_at),
                $decision->supersedes_id,
                $decision->note ?? '',
                $decision->hash,
            ])
            ->all());
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function anchors(RegisterScope $scope): array
    {
        return array_values(RegisterAnchor::query()
            ->whereBetween('date', [$scope->from, CarbonImmutable::parse($scope->to)->addDay()->toDateString()])
            ->orderBy('date')
            ->get()
            ->map(fn (RegisterAnchor $anchor): array => [
                $anchor->date->toDateString(),
                $anchor->digest,
                $anchor->prev_digest,
                $anchor->events_count,
                (string) __($anchor->verified_ok ? 'people.inspection.export.yes' : 'people.inspection.export.no'),
            ])
            ->all());
    }

    /**
     * @param  array{ok: bool, events: int, corrections: int, problems: list<string>}  $check
     */
    private function integrityText(array $check, CarbonImmutable $now): string
    {
        $lines = [
            (string) __('people.inspection.export.integrity_title'),
            (string) __('people.inspection.export.integrity_when', ['date' => PeopleFormat::dateTime($now)]),
            $check['ok']
                ? (string) __('people.inspection.export.integrity_ok', ['events' => $check['events'], 'corrections' => $check['corrections']])
                : (string) __('people.inspection.export.integrity_failed'),
            ...$check['problems'],
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $sums
     */
    private function readme(RegisterScope $scope, CarbonImmutable $now, string $actor, array $sums): string
    {
        $latest = RegisterAnchor::query()->orderByDesc('date')->first();
        $lines = [
            (string) __('people.inspection.export.readme.title', ['company' => $this->identity->name()]),
            '',
            (string) __('people.inspection.export.readme.period', ['from' => PeopleFormat::date($scope->from), 'to' => PeopleFormat::date($scope->to)]),
            (string) __('people.inspection.export.readme.people', ['people' => implode(', ', array_map(fn (User $user): string => $user->name, $scope->users))]),
            (string) __('people.inspection.export.readme.generated', ['date' => PeopleFormat::dateTime($now), 'by' => $actor]),
            (string) __('people.inspection.export.readme.timezone'),
            '',
            (string) __('people.inspection.export.readme.files'),
        ];

        foreach (array_keys($sums) as $name) {
            $base = pathinfo($name, PATHINFO_FILENAME);
            $lines[] = '- '.$name.': '.__("people.inspection.export.readme.describe.{$base}");
        }

        $lines[] = '';
        $lines[] = (string) __('people.inspection.export.readme.integrity');
        $lines[] = (string) __('people.inspection.export.readme.anchor', [
            'date' => $latest === null ? '—' : PeopleFormat::date($latest->date->toDateString()),
            'digest' => $latest->digest ?? '—',
        ]);
        $lines[] = (string) __('people.inspection.export.readme.formats');
        $lines[] = (string) __('people.inspection.export.readme.legal');

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function headers(array $keys): array
    {
        return array_map(fn (string $key): string => (string) __("people.reports.headers.{$key}"), $keys);
    }
}
