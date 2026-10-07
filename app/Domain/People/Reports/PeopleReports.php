<?php

namespace App\Domain\People\Reports;

use App\Domain\Identity\CompanyIdentity;
use App\Domain\People\OvertimeService;
use App\Domain\Reports\Delivery\Documents\ExportSheet;
use App\Domain\Reports\Delivery\Documents\PdfTable;
use App\Enums\AbsenceType;
use App\Enums\ClockEventKind;
use App\Enums\CorrectionStatus;
use App\Enums\HourType;
use App\Enums\MonthCloseStatus;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use App\Models\MonthClose;
use App\Models\OvertimeDecision;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Los informes del registro con huella (R2; PLAN-FASE-11 §6.3; D-351; W-089 a W-093) y «Mi
 * registro»: cada uno da un RegisterDocument con lo mismo para el PDF y para el Excel y el CSV.
 * Los CSV y Excel llevan los minutos como enteros; los PDF, en h:mm. Quién puede pedir cada uno lo
 * deciden los controladores (PeopleAccess); aquí solo se prepara el contenido del ámbito que llega.
 *
 * @phpstan-import-type Line from RegisterDataset
 * @phpstan-import-type Totals from RegisterDataset
 */
final class PeopleReports
{
    public function __construct(
        private readonly RegisterDataset $dataset,
        private readonly CompanyIdentity $identity,
    ) {}

    public function build(PeopleReportKind $kind, RegisterScope $scope): RegisterDocument
    {
        return match ($kind) {
            PeopleReportKind::MonthlyRegister => $this->monthlyRegister($scope),
            PeopleReportKind::OvertimeAnnex => $this->overtimeAnnex($scope),
            PeopleReportKind::DailyPresence => $this->dailyPresence($scope),
            PeopleReportKind::MonthlyPresence => $this->monthlyPresence($scope),
            PeopleReportKind::Punches => $this->punches($scope),
            PeopleReportKind::Incidents => $this->incidents($scope),
        };
    }

    /**
     * «Registro mensual de la jornada» (W-089): por persona, cada día con su horario previsto, la
     * entrada, la salida, los tramos, la comida, lo trabajado y las horas ordinarias, extra (con su
     * destino), complementarias y sin clasificar, el modo y las incidencias; y el estado del cierre.
     */
    public function monthlyRegister(RegisterScope $scope, string $kind = 'monthly_register', ?string $title = null): RegisterDocument
    {
        $title ??= PeopleReportKind::MonthlyRegister->title();
        $data = $this->dataset->build($scope->users, $scope->from, $scope->to, $scope->hideAbsenceType);
        $closes = $this->closes($scope);
        $sections = [];
        $rows = [];
        $sum = self::emptyTotals();

        foreach ($data as $entry) {
            $user = $entry['user'];
            $pdfRows = [];

            foreach ($entry['lines'] as $line) {
                if ($line['status'] === 'future') {
                    continue;
                }

                $ordinary = self::ordinary($line);
                $parts = PeopleFormat::segments($line['segments']);
                $pdfRows[] = [
                    PeopleFormat::day($line['date']),
                    self::expectedLabel($line),
                    PeopleFormat::time($line['first_in']),
                    PeopleFormat::time($line['last_out']),
                    $parts['work'],
                    $parts['pause'],
                    $line['segments'] === [] ? '' : PeopleFormat::hm($line['worked_minutes']),
                    $line['segments'] === [] ? '' : PeopleFormat::hm($ordinary),
                    self::hmOrEmpty($line['overtime_minutes']).self::destinationSuffix($line),
                    self::hmOrEmpty($line['complementary_minutes']),
                    self::hmOrEmpty($line['unclassified_minutes']),
                    PeopleFormat::modes($line['modes']),
                    PeopleFormat::incidents($line['incidents']),
                ];

                $rows[] = [
                    $user->name,
                    $line['date'],
                    $line['expected_minutes'],
                    PeopleFormat::time($line['first_in']),
                    PeopleFormat::time($line['last_out']),
                    $parts['work'],
                    $line['pause_minutes'],
                    $line['worked_minutes'],
                    $ordinary,
                    $line['overtime_minutes'],
                    $line['destination'] === null ? '' : __("people.overtime.destinations.{$line['destination']}"),
                    $line['complementary_minutes'],
                    $line['unclassified_minutes'],
                    PeopleFormat::modes($line['modes']),
                    PeopleFormat::incidents($line['incidents']),
                    $closes[$user->id][substr($line['date'], 0, 7)] ?? '',
                ];
            }

            $totals = $entry['totals'];
            $sum = self::addTotals($sum, $totals);
            $closeLabels = array_values(array_unique(array_filter($closes[$user->id] ?? [])));

            $sections[] = [
                'title' => $user->name,
                'lead' => (string) __('people.reports.register.person_lead', [
                    'worked' => PeopleFormat::hm($totals['worked_minutes']),
                    'expected' => PeopleFormat::hm($totals['expected_minutes']),
                    'overtime' => PeopleFormat::hm($totals['overtime_minutes']),
                    'complementary' => PeopleFormat::hm($totals['complementary_minutes']),
                    'close' => $closeLabels === [] ? __('people.reports.register.no_close') : implode(' · ', $closeLabels),
                ]),
                'table' => PdfTable::make([
                    [__('people.reports.cols.day')], [__('people.reports.cols.expected')], [__('people.reports.cols.in'), true],
                    [__('people.reports.cols.out'), true], [__('people.reports.cols.segments')], [__('people.reports.cols.pause')],
                    [__('people.reports.cols.worked'), true], [__('people.reports.cols.ordinary'), true], [__('people.reports.cols.overtime'), true],
                    [__('people.reports.cols.complementary'), true], [__('people.reports.cols.unclassified'), true], [__('people.reports.cols.mode')],
                    [__('people.reports.cols.incidents')],
                ], $pdfRows, [
                    (string) __('people.reports.cols.total'), PeopleFormat::hm($totals['expected_minutes']), '', '', '', '',
                    PeopleFormat::hm($totals['worked_minutes']), PeopleFormat::hm($totals['worked_minutes'] - $totals['overtime_minutes'] - $totals['complementary_minutes'] - $totals['unclassified_minutes']),
                    PeopleFormat::hm($totals['overtime_minutes']), PeopleFormat::hm($totals['complementary_minutes']), PeopleFormat::hm($totals['unclassified_minutes']), '', '',
                ], compact: true, empty: (string) __('people.reports.empty')),
            ];
        }

        return new RegisterDocument(
            kind: $kind,
            title: $title,
            basename: $title.' '.$this->periodSlug($scope),
            cover: $this->cover($title, $scope),
            kpis: self::kpis($sum),
            sections: $sections,
            headers: self::headers([
                'person', 'date', 'expected_min', 'in', 'out', 'segments', 'pause_min', 'worked_min', 'ordinary_min',
                'overtime_min', 'destination', 'complementary_min', 'unclassified_min', 'mode', 'incidents', 'close',
            ]),
            rows: $rows,
            notes: [(string) __('people.reports.register.note')],
        );
    }

    /**
     * «Anexo de horas» (W-090): por persona y mes, las horas extra (a compensar y a pagar), las
     * complementarias, el exceso sin clasificar y el acumulado del año frente al tope de 80 h. Con
     * los datos mínimos para la representación legal (nombre y centro de trabajo, STS 1161/2024).
     */
    public function overtimeAnnex(RegisterScope $scope): RegisterDocument
    {
        $title = PeopleReportKind::OvertimeAnnex->title();
        $data = $this->dataset->build($scope->users, $scope->from, $scope->to, true);
        $center = self::workCenter();
        $year = (int) substr($scope->from, 0, 4);
        $yearTotals = $this->yearOvertime(array_map(fn (User $user): int => $user->id, $scope->users), $year, $scope->to);
        $rows = [];
        $pdfRows = [];
        $detail = [];
        $sum = self::emptyTotals();

        foreach ($data as $entry) {
            $user = $entry['user'];
            $totals = $entry['totals'];
            $sum = self::addTotals($sum, $totals);
            $ytd = $yearTotals[$user->id] ?? 0;

            $pdfRows[] = [
                $user->name,
                $center,
                PeopleFormat::hm($totals['overtime_compensate_minutes']),
                PeopleFormat::hm($totals['overtime_pay_minutes']),
                PeopleFormat::hm($totals['overtime_minutes']),
                PeopleFormat::hm($totals['complementary_minutes']),
                PeopleFormat::hm($totals['unclassified_minutes']),
                PeopleFormat::hm($ytd),
                PeopleFormat::hm(max(OvertimeService::YEAR_CAP_MINUTES - $ytd, 0)),
            ];
            $rows[] = [
                $user->name, $center, substr($scope->from, 0, 7), $totals['overtime_compensate_minutes'], $totals['overtime_pay_minutes'],
                $totals['overtime_minutes'], $totals['complementary_minutes'], $totals['unclassified_minutes'], $ytd,
                max(OvertimeService::YEAR_CAP_MINUTES - $ytd, 0),
            ];

            foreach ($entry['lines'] as $line) {
                if ($line['overtime_minutes'] > 0 || $line['complementary_minutes'] > 0) {
                    $detail[] = [
                        $user->name,
                        PeopleFormat::day($line['date']),
                        self::hmOrEmpty($line['overtime_minutes']),
                        self::hmOrEmpty($line['complementary_minutes']),
                        $line['destination'] === null ? '' : (string) __("people.overtime.destinations.{$line['destination']}"),
                    ];
                }
            }
        }

        return new RegisterDocument(
            kind: 'overtime_annex',
            title: $title,
            basename: $title.' '.$this->periodSlug($scope),
            cover: $this->cover($title, $scope),
            kpis: [
                ['label' => (string) __('people.reports.kpis.overtime'), 'value' => PeopleFormat::hm($sum['overtime_minutes']), 'detail' => null],
                ['label' => (string) __('people.reports.kpis.compensate'), 'value' => PeopleFormat::hm($sum['overtime_compensate_minutes']), 'detail' => null],
                ['label' => (string) __('people.reports.kpis.pay'), 'value' => PeopleFormat::hm($sum['overtime_pay_minutes']), 'detail' => null],
                ['label' => (string) __('people.reports.kpis.unclassified'), 'value' => PeopleFormat::hm($sum['unclassified_minutes']), 'detail' => null],
            ],
            sections: [
                [
                    'title' => (string) __('people.reports.annex.summary'),
                    'lead' => (string) __('people.reports.annex.lead'),
                    'table' => PdfTable::make([
                        [__('people.reports.cols.person')], [__('people.reports.cols.work_center')], [__('people.reports.cols.compensate'), true],
                        [__('people.reports.cols.pay'), true], [__('people.reports.cols.overtime'), true], [__('people.reports.cols.complementary'), true],
                        [__('people.reports.cols.unclassified'), true], [__('people.reports.cols.year_to_date'), true], [__('people.reports.cols.cap_left'), true],
                    ], $pdfRows, empty: (string) __('people.reports.empty')),
                ],
                [
                    'title' => (string) __('people.reports.annex.detail'),
                    'lead' => null,
                    'table' => PdfTable::make([
                        [__('people.reports.cols.person')], [__('people.reports.cols.day')], [__('people.reports.cols.overtime'), true],
                        [__('people.reports.cols.complementary'), true], [__('people.reports.cols.destination')],
                    ], $detail, compact: true, empty: (string) __('people.reports.annex.no_overtime')),
                ],
            ],
            headers: self::headers(['person', 'work_center', 'month', 'compensate_min', 'pay_min', 'overtime_min', 'complementary_min', 'unclassified_min', 'year_to_date_min', 'cap_left_min']),
            rows: $rows,
            notes: [(string) __('people.reports.annex.note')],
            landscape: false,
        );
    }

    /**
     * «Presencia diaria» (W-091): por persona y día, previsto frente a trabajado, la entrada, la
     * salida, la comida, la diferencia, las horas extra, el modo, las incidencias, si el mes está
     * confirmado y quién validó las correcciones.
     */
    public function dailyPresence(RegisterScope $scope): RegisterDocument
    {
        $title = PeopleReportKind::DailyPresence->title();
        $data = $this->dataset->build($scope->users, $scope->from, $scope->to, $scope->hideAbsenceType);
        $closes = $this->closes($scope);
        $validated = $this->validatedBy($scope);
        $rows = [];
        $pdfRows = [];
        $sum = self::emptyTotals();

        foreach ($data as $entry) {
            $user = $entry['user'];
            $sum = self::addTotals($sum, $entry['totals']);

            foreach ($entry['lines'] as $line) {
                if ($line['status'] === 'future') {
                    continue;
                }

                $close = $closes[$user->id][substr($line['date'], 0, 7)] ?? '';
                $validators = $validated[$user->id][$line['date']] ?? '';
                $status = (string) __("people.day_status.{$line['status']}");

                $pdfRows[] = [
                    $user->name,
                    PeopleFormat::day($line['date']),
                    self::expectedLabel($line),
                    PeopleFormat::time($line['first_in']),
                    PeopleFormat::time($line['last_out']),
                    $line['pause_minutes'] > 0 ? PeopleFormat::hm($line['pause_minutes']) : '',
                    $line['segments'] === [] ? '' : PeopleFormat::hm($line['worked_minutes']),
                    PeopleFormat::difference($line['difference_minutes']),
                    self::hmOrEmpty($line['overtime_minutes'] + $line['complementary_minutes']),
                    PeopleFormat::modes($line['modes']),
                    PeopleFormat::incidents($line['incidents']),
                    $status,
                    $validators,
                ];
                $rows[] = [
                    $user->name, $line['date'], $line['expected_minutes'], PeopleFormat::time($line['first_in']), PeopleFormat::time($line['last_out']),
                    $line['pause_minutes'], $line['worked_minutes'], $line['difference_minutes'], $line['overtime_minutes'], $line['complementary_minutes'],
                    $line['flex_minutes'], $line['unclassified_minutes'], PeopleFormat::modes($line['modes']), PeopleFormat::incidents($line['incidents']),
                    $status, $close, $validators,
                ];
            }
        }

        return new RegisterDocument(
            kind: 'daily_presence',
            title: $title,
            basename: $title.' '.$this->periodSlug($scope),
            cover: $this->cover($title, $scope),
            kpis: self::kpis($sum),
            sections: [[
                'title' => $title,
                'lead' => null,
                'table' => PdfTable::make([
                    [__('people.reports.cols.person')], [__('people.reports.cols.day')], [__('people.reports.cols.expected')], [__('people.reports.cols.in'), true],
                    [__('people.reports.cols.out'), true], [__('people.reports.cols.pause'), true], [__('people.reports.cols.worked'), true],
                    [__('people.reports.cols.difference'), true], [__('people.reports.cols.overtime'), true], [__('people.reports.cols.mode')],
                    [__('people.reports.cols.incidents')], [__('people.reports.cols.status')], [__('people.reports.cols.validated_by')],
                ], $pdfRows, compact: true, empty: (string) __('people.reports.empty')),
            ]],
            headers: self::headers([
                'person', 'date', 'expected_min', 'in', 'out', 'pause_min', 'worked_min', 'difference_min', 'overtime_min', 'complementary_min',
                'flex_min', 'unclassified_min', 'mode', 'incidents', 'status', 'close', 'validated_by',
            ]),
            rows: $rows,
        );
    }

    /**
     * «Presencia mensual» (W-092): por persona y mes, previsto frente a trabajado, la diferencia, el
     * exceso y su clasificación, los días trabajados, a distancia y con incidencias, y el cierre.
     */
    public function monthlyPresence(RegisterScope $scope): RegisterDocument
    {
        $title = PeopleReportKind::MonthlyPresence->title();
        $data = $this->dataset->build($scope->users, $scope->from, $scope->to, true);
        $closes = $this->closes($scope);
        $month = substr($scope->from, 0, 7);
        $rows = [];
        $pdfRows = [];
        $sum = self::emptyTotals();

        foreach ($data as $entry) {
            $user = $entry['user'];
            $totals = $entry['totals'];
            $sum = self::addTotals($sum, $totals);
            $close = $closes[$user->id][$month] ?? (string) __('people.reports.register.no_close');

            $pdfRows[] = [
                $user->name,
                PeopleFormat::hm($totals['expected_minutes']),
                PeopleFormat::hm($totals['worked_minutes']),
                PeopleFormat::difference($totals['difference_minutes']),
                PeopleFormat::hm($totals['overtime_minutes']),
                PeopleFormat::hm($totals['complementary_minutes']),
                PeopleFormat::hm($totals['unclassified_minutes']),
                (string) $totals['days_worked'],
                (string) $totals['remote_days'],
                (string) $totals['incident_days'],
                $close,
            ];
            $rows[] = [
                $user->name, $month, $totals['expected_minutes'], $totals['worked_minutes'], $totals['difference_minutes'], $totals['excess_minutes'],
                $totals['overtime_minutes'], $totals['complementary_minutes'], $totals['flex_minutes'], $totals['unclassified_minutes'],
                $totals['days_worked'], $totals['onsite_days'], $totals['remote_days'], $totals['incident_days'], $close,
            ];
        }

        return new RegisterDocument(
            kind: 'monthly_presence',
            title: $title,
            basename: $title.' '.$this->periodSlug($scope),
            cover: $this->cover($title, $scope),
            kpis: self::kpis($sum),
            sections: [[
                'title' => $title,
                'lead' => null,
                'table' => PdfTable::make([
                    [__('people.reports.cols.person')], [__('people.reports.cols.expected'), true], [__('people.reports.cols.worked'), true],
                    [__('people.reports.cols.difference'), true], [__('people.reports.cols.overtime'), true], [__('people.reports.cols.complementary'), true],
                    [__('people.reports.cols.unclassified'), true], [__('people.reports.cols.days_worked'), true], [__('people.reports.cols.remote_days'), true],
                    [__('people.reports.cols.incident_days'), true], [__('people.reports.cols.close')],
                ], $pdfRows, [
                    (string) __('people.reports.cols.total'), PeopleFormat::hm($sum['expected_minutes']), PeopleFormat::hm($sum['worked_minutes']),
                    PeopleFormat::difference($sum['difference_minutes']), PeopleFormat::hm($sum['overtime_minutes']), PeopleFormat::hm($sum['complementary_minutes']),
                    PeopleFormat::hm($sum['unclassified_minutes']), (string) $sum['days_worked'], (string) $sum['remote_days'], (string) $sum['incident_days'], '',
                ], empty: (string) __('people.reports.empty')),
            ]],
            headers: self::headers([
                'person', 'month', 'expected_min', 'worked_min', 'difference_min', 'excess_min', 'overtime_min', 'complementary_min', 'flex_min',
                'unclassified_min', 'days_worked', 'onsite_days', 'remote_days', 'incident_days', 'close',
            ]),
            rows: $rows,
        );
    }

    /**
     * «Fichajes» (W-093): cada fila de la cadena del periodo, también las anulaciones, con su número,
     * cuándo pasó y cuándo se registró, el modo, el origen, quién lo escribió y su huella.
     */
    public function punches(RegisterScope $scope): RegisterDocument
    {
        $title = PeopleReportKind::Punches->title();
        $rows = [];
        $pdfRows = [];

        foreach ($this->chainRows($scope) as $event) {
            $row = self::eventRow($event);
            $rows[] = $row;
            $pdfRows[] = [
                $event->user->name,
                (string) $event->seq,
                $event->kind->label(),
                PeopleFormat::dateTimeSeconds($event->occurred_at),
                PeopleFormat::dateTimeSeconds($event->recorded_at),
                $event->work_mode?->label() ?? '',
                (string) __("people.sources.{$event->source->value}"),
                $event->voidedEvent === null ? '' : '#'.$event->voidedEvent->seq,
                $event->correction_id === null ? '' : '#'.$event->correction_id,
                $event->author->name ?? '',
                substr($event->hash, 0, 16).'…',
            ];
        }

        return new RegisterDocument(
            kind: 'punches',
            title: $title,
            basename: $title.' '.$this->periodSlug($scope),
            cover: $this->cover($title, $scope),
            kpis: [['label' => (string) __('people.reports.kpis.rows'), 'value' => (string) count($rows), 'detail' => null]],
            sections: [[
                'title' => $title,
                'lead' => (string) __('people.reports.punches.lead'),
                'table' => PdfTable::make([
                    [__('people.reports.cols.person')], [__('people.reports.cols.seq'), true], [__('people.reports.cols.kind')], [__('people.reports.cols.occurred_at')],
                    [__('people.reports.cols.recorded_at')], [__('people.reports.cols.mode')], [__('people.reports.cols.source')], [__('people.reports.cols.voids')],
                    [__('people.reports.cols.correction')], [__('people.reports.cols.author')], [__('people.reports.cols.hash')],
                ], $pdfRows, compact: true, empty: (string) __('people.reports.empty')),
            ]],
            headers: self::headers(self::EVENT_COLUMNS),
            rows: $rows,
        );
    }

    /**
     * «Incidencias»: los días con incidencias, qué se ha hecho (corrección pendiente, en
     * discrepancia o aceptada) y lo trabajado frente a lo previsto.
     */
    public function incidents(RegisterScope $scope): RegisterDocument
    {
        $title = PeopleReportKind::Incidents->title();
        $data = $this->dataset->build($scope->users, $scope->from, $scope->to, $scope->hideAbsenceType);
        $validated = $this->validatedBy($scope);
        $rows = [];
        $pdfRows = [];

        foreach ($data as $entry) {
            $user = $entry['user'];

            foreach ($entry['lines'] as $line) {
                if ($line['incidents'] === [] || $line['status'] === 'future') {
                    continue;
                }

                $follow = match (true) {
                    $line['pending_corrections'] > 0 => (string) __('people.reports.incidents.pending'),
                    $line['disputed_corrections'] > 0 => (string) __('people.reports.incidents.disputed'),
                    isset($validated[$user->id][$line['date']]) => (string) __('people.reports.incidents.corrected'),
                    default => (string) __('people.reports.incidents.open'),
                };

                $pdfRows[] = [
                    $user->name,
                    PeopleFormat::day($line['date']),
                    PeopleFormat::incidents($line['incidents']),
                    $line['segments'] === [] ? '' : PeopleFormat::hm($line['worked_minutes']),
                    self::expectedLabel($line),
                    $follow,
                ];
                $rows[] = [$user->name, $line['date'], PeopleFormat::incidents($line['incidents']), implode(',', $line['incidents']), $line['worked_minutes'], $line['expected_minutes'], $follow];
            }
        }

        return new RegisterDocument(
            kind: 'incidents',
            title: $title,
            basename: $title.' '.$this->periodSlug($scope),
            cover: $this->cover($title, $scope),
            kpis: [['label' => (string) __('people.reports.kpis.incident_days'), 'value' => (string) count($rows), 'detail' => null]],
            sections: [[
                'title' => $title,
                'lead' => (string) __('people.reports.incidents.lead'),
                'table' => PdfTable::make([
                    [__('people.reports.cols.person')], [__('people.reports.cols.day')], [__('people.reports.cols.incidents')],
                    [__('people.reports.cols.worked'), true], [__('people.reports.cols.expected')], [__('people.reports.cols.follow_up')],
                ], $pdfRows, compact: true, empty: (string) __('people.reports.incidents.none')),
            ]],
            headers: self::headers(['person', 'date', 'incidents', 'incident_codes', 'worked_min', 'expected_min', 'follow_up']),
            rows: $rows,
            landscape: false,
        );
    }

    /**
     * «Mi registro» de una persona en un periodo (PLAN-FASE-11 §6.1.6 y §11.3; D-346): el diario
     * con los totales, el historial de correcciones (con autor, fecha, motivo y conformidad o
     * discrepancia) y cada fila de la cadena con su huella. El Excel lleva una hoja para cada cosa;
     * el CSV, el diario.
     */
    public function personRegister(User $user, string $from, string $to, string $kind = 'my_register', bool $hideAbsenceType = false): RegisterDocument
    {
        $title = (string) __('people.reports.mine.title');
        $scope = new RegisterScope([$user], $from, $to, $user->name, $hideAbsenceType);
        $register = $this->monthlyRegister($scope, $kind, $title);
        $corrections = $this->correctionsOf($scope);
        $events = $this->chainRows($scope);

        $correctionRows = array_map(fn (ClockCorrection $correction): array => self::correctionRow($correction), $corrections);
        $eventRows = array_map(fn (ClockEvent $event): array => self::eventRow($event), $events);

        $sections = $register->sections;
        $sections[] = [
            'title' => (string) __('people.reports.mine.corrections'),
            'lead' => (string) __('people.reports.mine.corrections_lead'),
            'table' => PdfTable::make([
                [__('people.reports.cols.day')], [__('people.reports.cols.proposed_by')], [__('people.reports.cols.reason')], [__('people.reports.cols.status')],
                [__('people.reports.cols.decided_by')], [__('people.reports.cols.decision_note')],
            ], array_map(fn (ClockCorrection $correction): array => [
                PeopleFormat::day($correction->date->toDateString()),
                ($correction->proposer->name ?? '').' · '.PeopleFormat::dateTime($correction->created_at),
                $correction->reason,
                $correction->status->label(),
                $correction->decided_at === null ? '' : ($correction->decider->name ?? (string) __('people.reports.mine.no_answer')).' · '.PeopleFormat::dateTime($correction->decided_at),
                (string) ($correction->decision_note ?? ''),
            ], $corrections), compact: true, empty: (string) __('people.reports.mine.no_corrections')),
        ];
        $sections[] = [
            'title' => (string) __('people.reports.mine.chain'),
            'lead' => (string) __('people.reports.punches.lead'),
            'table' => PdfTable::make([
                [__('people.reports.cols.seq'), true], [__('people.reports.cols.kind')], [__('people.reports.cols.occurred_at')], [__('people.reports.cols.recorded_at')],
                [__('people.reports.cols.mode')], [__('people.reports.cols.source')], [__('people.reports.cols.voids')], [__('people.reports.cols.author')], [__('people.reports.cols.hash')],
            ], array_map(fn (ClockEvent $event): array => [
                (string) $event->seq,
                $event->kind->label(),
                PeopleFormat::dateTimeSeconds($event->occurred_at),
                PeopleFormat::dateTimeSeconds($event->recorded_at),
                $event->work_mode?->label() ?? '',
                (string) __("people.sources.{$event->source->value}"),
                $event->voidedEvent === null ? '' : '#'.$event->voidedEvent->seq,
                $event->author->name ?? '',
                substr($event->hash, 0, 16).'…',
            ], $events), compact: true, empty: (string) __('people.reports.empty')),
        ];

        return new RegisterDocument(
            kind: $kind,
            title: $title,
            basename: $title.' '.$user->name.' '.$this->periodSlug($scope),
            cover: $this->cover($title, $scope),
            kpis: $register->kpis,
            sections: $sections,
            headers: $register->headers,
            rows: $register->rows,
            sheets: [
                new ExportSheet((string) __('people.reports.mine.sheet_days'), $register->headers, $register->rows),
                new ExportSheet((string) __('people.reports.mine.sheet_chain'), self::headers(self::EVENT_COLUMNS), $eventRows),
                new ExportSheet((string) __('people.reports.mine.sheet_corrections'), self::headers(self::CORRECTION_COLUMNS), $correctionRows),
            ],
            notes: $register->notes,
        );
    }

    /**
     * Filas de la cadena del periodo: los fichajes que pasaron en esos días y las anulaciones
     * registradas en ellos.
     *
     * @return list<ClockEvent>
     */
    public function chainRows(RegisterScope $scope): array
    {
        [$start, $end] = self::bounds($scope);

        return array_values(ClockEvent::query()
            ->with(['user:id,name', 'author:id,name', 'voidedEvent:id,seq'])
            ->whereIn('user_id', array_map(fn (User $user): int => $user->id, $scope->users))
            ->where(fn ($query) => $query
                ->where(fn ($punch) => $punch->where('kind', '!=', ClockEventKind::Void->value)->whereBetween('occurred_at', [$start, $end]))
                ->orWhere(fn ($void) => $void->where('kind', ClockEventKind::Void->value)->whereBetween('recorded_at', [$start, $end])))
            ->orderBy('user_id')
            ->orderBy('seq')
            ->get()
            ->all());
    }

    /**
     * Correcciones de los días del periodo (todas: pendientes, aceptadas, en discrepancia y
     * retiradas).
     *
     * @return list<ClockCorrection>
     */
    public function correctionsOf(RegisterScope $scope): array
    {
        return array_values(ClockCorrection::query()
            ->with(['user:id,name', 'proposer:id,name', 'decider:id,name'])
            ->whereIn('user_id', array_map(fn (User $user): int => $user->id, $scope->users))
            ->whereBetween('date', [$scope->from, $scope->to])
            ->orderBy('user_id')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** Columnas de la cadena en Excel y CSV. */
    public const array EVENT_COLUMNS = [
        'person', 'seq', 'kind', 'occurred_at', 'recorded_at', 'mode', 'pause_type', 'source', 'voids_seq', 'correction', 'author', 'ip_hash',
        'user_agent', 'prev_hash', 'hash',
    ];

    /** Columnas de las correcciones en Excel y CSV. */
    public const array CORRECTION_COLUMNS = [
        'correction', 'person', 'date', 'proposed_by', 'proposed_at', 'reason', 'voids', 'adds', 'status', 'decided_by', 'decided_at',
        'decision_note', 'dispute_reason', 'seal',
    ];

    /**
     * @return list<string|int|null>
     */
    public static function eventRow(ClockEvent $event): array
    {
        return [
            $event->user->name ?? '',
            $event->seq,
            $event->kind->label(),
            PeopleFormat::dateTimeSeconds($event->occurred_at),
            PeopleFormat::dateTimeSeconds($event->recorded_at),
            $event->work_mode?->label() ?? '',
            $event->pause_type === null ? '' : (string) __("people.pauses.{$event->pause_type->value}"),
            (string) __("people.sources.{$event->source->value}"),
            $event->voidedEvent?->seq,
            $event->correction_id,
            $event->author->name ?? '',
            $event->ip_hash === null ? '' : substr($event->ip_hash, 0, 16),
            $event->user_agent ?? '',
            $event->prev_hash,
            $event->hash,
        ];
    }

    /**
     * @return list<string|int|null>
     */
    public static function correctionRow(ClockCorrection $correction): array
    {
        return [
            $correction->id,
            $correction->user->name ?? '',
            $correction->date->toDateString(),
            $correction->proposer->name ?? '',
            PeopleFormat::dateTime($correction->created_at),
            $correction->reason,
            implode(',', array_map(strval(...), $correction->voids)),
            implode('; ', array_map(fn (array $add): string => __("people.kinds.{$add['kind']}").' '.PeopleFormat::dateTime($add['occurred_at']), $correction->adds)),
            $correction->status->label(),
            $correction->decider->name ?? '',
            PeopleFormat::dateTime($correction->decided_at),
            $correction->decision_note ?? '',
            $correction->dispute_reason === null ? '' : (string) __("people.reports.dispute_reasons.{$correction->dispute_reason}"),
            $correction->hash ?? '',
        ];
    }

    /**
     * Estado del cierre de cada persona y mes del ámbito («Confirmado el 03/10/2026»…).
     *
     * @return array<int, array<string, string>>
     */
    public function closes(RegisterScope $scope): array
    {
        $labels = [];

        MonthClose::query()
            ->current()
            ->whereIn('user_id', array_map(fn (User $user): int => $user->id, $scope->users))
            ->whereBetween('month', [substr($scope->from, 0, 7).'-01', $scope->to])
            ->get()
            ->each(function (MonthClose $close) use (&$labels): void {
                $labels[$close->user_id][$close->monthKey()] = self::closeLabel($close);
            });

        return $labels;
    }

    public static function closeLabel(MonthClose $close): string
    {
        return match ($close->status) {
            MonthCloseStatus::Confirmed => (string) __('people.reports.close.confirmed', ['date' => PeopleFormat::date((string) $close->confirmed_at?->setTimezone(LocalTime::timezone())->toDateString())]),
            MonthCloseStatus::Disagreed => (string) __('people.reports.close.disagreed', ['date' => PeopleFormat::date((string) $close->disagreed_at?->setTimezone(LocalTime::timezone())->toDateString())]),
            default => $close->status->label(),
        };
    }

    /**
     * Quién aceptó las correcciones de cada persona y día («Raúl Responsable»).
     *
     * @return array<int, array<string, string>>
     */
    private function validatedBy(RegisterScope $scope): array
    {
        $names = [];

        foreach ($this->correctionsOf($scope) as $correction) {
            if ($correction->status === CorrectionStatus::Accepted && $correction->decider !== null) {
                $date = $correction->date->toDateString();
                $current = $names[$correction->user_id][$date] ?? '';
                $names[$correction->user_id][$date] = $current === '' ? $correction->decider->name : $current.', '.$correction->decider->name;
            }
        }

        return $names;
    }

    /**
     * Horas extra (no complementarias) del año hasta $to, por persona.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function yearOvertime(array $userIds, int $year, string $to): array
    {
        return OvertimeDecision::query()
            ->effective()
            ->whereIn('user_id', $userIds)
            ->where('hour_type', HourType::Overtime->value)
            ->whereBetween('date', [sprintf('%04d-01-01', $year), $to])
            ->selectRaw('user_id, sum(overtime_minutes) as minutes')
            ->groupBy('user_id')
            ->pluck('minutes', 'user_id')
            ->map(fn (mixed $minutes): int => (int) $minutes)
            ->all();
    }

    /**
     * @return array{kicker: string, title: string, subtitle: string, facts: list<array{0: string, 1: string}>, note: string|null}
     */
    private function cover(string $title, RegisterScope $scope): array
    {
        return [
            'kicker' => (string) __('people.reports.cover.kicker'),
            'title' => $title,
            'subtitle' => $this->identity->name(),
            'facts' => [
                [(string) __('people.reports.cover.period'), PeopleFormat::date($scope->from).' – '.PeopleFormat::date($scope->to)],
                [(string) __('people.reports.cover.scope'), $scope->label],
                [(string) __('people.reports.cover.people'), (string) count($scope->users)],
                [(string) __('people.reports.cover.timezone'), (string) __('people.reports.cover.madrid')],
            ],
            'note' => (string) __('people.reports.cover.note'),
        ];
    }

    private function periodSlug(RegisterScope $scope): string
    {
        return substr($scope->from, 0, 7) === substr($scope->to, 0, 7) && str_ends_with($scope->from, '-01')
            ? substr($scope->from, 0, 7)
            : $scope->from.' '.$scope->to;
    }

    /**
     * Cabeceras legibles a partir de sus claves (people.reports.headers.*).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function headers(array $keys): array
    {
        return array_map(fn (string $key): string => (string) __("people.reports.headers.{$key}"), $keys);
    }

    /**
     * Ordinarias = trabajado − extra − complementarias − sin clasificar (la flexibilidad es
     * ordinaria).
     *
     * @param  Line  $line
     */
    private static function ordinary(array $line): int
    {
        return max($line['worked_minutes'] - $line['overtime_minutes'] - $line['complementary_minutes'] - $line['unclassified_minutes'], 0);
    }

    /**
     * @param  Line  $line
     */
    private static function expectedLabel(array $line): string
    {
        return match (true) {
            ! $line['registered'] => (string) __('people.reports.expected.not_registered'),
            ! $line['employed'] => (string) __('people.reports.expected.not_employed'),
            $line['holiday'] !== null => (string) __('people.reports.expected.holiday', ['name' => $line['holiday']]),
            $line['absence'] && $line['expected_minutes'] === 0 => $line['absence_type'] === null
                ? (string) __('people.reports.expected.absence')
                : (string) __('people.reports.expected.absence_type', ['type' => AbsenceType::tryFrom($line['absence_type'])?->label() ?? $line['absence_type']]),
            $line['expected_minutes'] === 0 => (string) __('people.reports.expected.rest'),
            default => PeopleFormat::hm($line['expected_minutes']),
        };
    }

    /**
     * @param  Line  $line
     */
    private static function destinationSuffix(array $line): string
    {
        return $line['overtime_minutes'] > 0 && $line['destination'] !== null
            ? ' ('.mb_strtolower((string) __("people.overtime.destinations_short.{$line['destination']}")).')'
            : '';
    }

    private static function hmOrEmpty(int $minutes): string
    {
        return $minutes > 0 ? PeopleFormat::hm($minutes) : '';
    }

    /**
     * Principio y fin del periodo en UTC (días de Madrid).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function bounds(RegisterScope $scope): array
    {
        $zone = LocalTime::timezone();

        return [
            CarbonImmutable::parse($scope->from, $zone)->startOfDay()->utc(),
            CarbonImmutable::parse($scope->to, $zone)->endOfDay()->utc(),
        ];
    }

    /**
     * Centro de trabajo para el anexo (municipio y provincia, STS 1161/2024).
     */
    private static function workCenter(): string
    {
        $value = Setting::get('people_work_center');

        return is_string($value) && trim($value) !== '' ? $value : (string) __('people.reports.annex.default_center');
    }

    /**
     * @return Totals
     */
    private static function emptyTotals(): array
    {
        return RegisterDataset::totals([]);
    }

    /**
     * @param  Totals  $sum
     * @param  Totals  $totals
     * @return Totals
     */
    private static function addTotals(array $sum, array $totals): array
    {
        foreach ($totals as $key => $value) {
            $sum[$key] += $value;
        }

        return $sum;
    }

    /**
     * @param  Totals  $sum
     * @return list<array{label: string, value: string, detail: string|null}>
     */
    private static function kpis(array $sum): array
    {
        return [
            ['label' => (string) __('people.reports.kpis.worked'), 'value' => PeopleFormat::hm($sum['worked_minutes']), 'detail' => (string) __('people.reports.kpis.of_expected', ['expected' => PeopleFormat::hm($sum['expected_minutes'])])],
            ['label' => (string) __('people.reports.kpis.difference'), 'value' => PeopleFormat::difference($sum['difference_minutes']), 'detail' => null],
            ['label' => (string) __('people.reports.kpis.overtime'), 'value' => PeopleFormat::hm($sum['overtime_minutes']), 'detail' => $sum['unclassified_minutes'] > 0 ? (string) __('people.reports.kpis.unclassified_detail', ['minutes' => PeopleFormat::hm($sum['unclassified_minutes'])]) : null],
            ['label' => (string) __('people.reports.kpis.incident_days'), 'value' => (string) $sum['incident_days'], 'detail' => null],
        ];
    }
}
