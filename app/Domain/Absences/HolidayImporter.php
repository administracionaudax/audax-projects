<?php

namespace App\Domain\Absences;

use App\Domain\Reports\ReportCache;
use App\Models\Holiday;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use DateInterval;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Importación de festivos (D-050): un calendario .ics (cada VEVENT con su DTSTART y su SUMMARY) o
 * un CSV con líneas «AAAA-MM-DD;Nombre» (también DD/MM/AAAA y una cabecera opcional).
 *
 * En un .ics:
 * - se ignoran las propiedades de los subcomponentes del evento (VALARM…),
 * - un evento de varios días (DTEND o DURATION) da un festivo por día, con un aviso (31 como mucho),
 * - uno que se repite cada año (RRULE:FREQ=YEARLY, con INTERVAL, COUNT, UNTIL y EXDATE) se toma en
 *   el año elegido, con un aviso; cualquier otra repetición (o RDATE) es un error de su línea.
 *
 * - preview() lee el fichero y devuelve cada festivo con su estado, sin guardar nada: se añade,
 *   ya existe un festivo ese día (se deja como está), se repite en el fichero o tiene un error
 *   (con su número de línea).
 * - store() añade los de las fechas que aún no tienen festivo (nunca duplica: la fecha es única),
 *   lo deja en la auditoría e invalida la caché de los informes (ReportCache: la capacidad depende
 *   de los festivos). Lo usan la importación y «Añadir los festivos nacionales».
 */
final class HolidayImporter
{
    /** Tamaño máximo del fichero (en KB, regla `max` de la validación). */
    public const int MAX_KILOBYTES = 256;

    /** Festivos por fichero, como mucho. */
    public const int MAX_ROWS = 500;

    public const int MAX_NAME = 100;

    /** Días de un evento del .ics, como mucho (uno más largo no parece un festivo). */
    public const int MAX_SPAN_DAYS = 31;

    public const string NEW = 'new';

    public const string EXISTING = 'existing';

    public const string DUPLICATE = 'duplicate';

    public const string ERROR = 'error';

    /**
     * @param  int|null  $year  Año en el que se toman los eventos que se repiten cada año (por defecto, el actual).
     * @return array{format: 'ics'|'csv', rows: list<array{line: int, date: string|null, name: string|null, status: string, message: string|null}>, counts: array<string, int>}
     *
     * @throws ValidationException si el fichero no se puede leer (vacío, formato desconocido, demasiadas líneas)
     */
    public function preview(string $contents, ?string $extension = null, ?int $year = null): array
    {
        [$format, $parsed] = $this->parse($contents, $extension, $year);

        $dates = array_values(array_unique(array_filter(array_map(
            fn (array $row): ?string => $row['error'] === null ? $row['date'] : null,
            $parsed,
        ))));

        $existing = $this->existingNames($dates);
        $seen = [];
        $rows = [];

        foreach ($parsed as $row) {
            $status = self::NEW;
            $message = $row['error'];
            $date = $row['date'];

            if ($message !== null || $date === null) {
                $status = self::ERROR;
            } elseif (isset($seen[$date])) {
                $status = self::DUPLICATE;
                $message = self::message('absences.holidays.import.duplicate', ['line' => $seen[$date]]);
            } elseif (isset($existing[$date])) {
                $status = self::EXISTING;
                $message = self::message('absences.holidays.import.existing', ['name' => $existing[$date]]);
            }

            if ($status !== self::ERROR && $date !== null) {
                $seen[$date] ??= $row['line'];
            }

            // Aviso de la línea (un evento de varios días o que se repite), tras el de su estado.
            if ($status !== self::ERROR && $row['note'] !== null) {
                $message = $message === null ? $row['note'] : $message.' '.$row['note'];
            }

            $rows[] = ['line' => $row['line'], 'date' => $date, 'name' => $row['name'], 'status' => $status, 'message' => $message];
        }

        $counts = array_fill_keys([self::NEW, self::EXISTING, self::DUPLICATE, self::ERROR], 0);
        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }

        return ['format' => $format, 'rows' => $rows, 'counts' => $counts];
    }

    /**
     * Festivos del fichero, con su línea, su error y su aviso (si los hay).
     *
     * @param  int|null  $year  Año en el que se toman los eventos que se repiten cada año (por defecto, el actual).
     * @return array{0: 'ics'|'csv', 1: list<array{line: int, date: string|null, name: string|null, error: string|null, note: string|null}>}
     *
     * @throws ValidationException
     */
    public function parse(string $contents, ?string $extension = null, ?int $year = null): array
    {
        $contents = self::normalize($contents);
        $year = min(SpanishNationalHolidays::MAX_YEAR, max(SpanishNationalHolidays::MIN_YEAR, $year ?? (int) LocalTime::now()->format('Y')));

        if (trim($contents) === '') {
            throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.empty')]);
        }

        $isCalendar = str_starts_with(strtoupper(ltrim($contents)), 'BEGIN:VCALENDAR');

        if ($isCalendar) {
            return ['ics', $this->parseIcs($contents, $year)];
        }

        if (strtolower((string) $extension) === 'ics') {
            throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.unknown_format')]);
        }

        return ['csv', $this->parseCsv($contents)];
    }

    /**
     * Añade los festivos de las fechas que aún no tienen (la primera aparición de cada fecha) y deja
     * un registro resumido en la auditoría.
     *
     * @param  list<array{date: string, name: string}>  $rows
     * @param  array<string, mixed>  $properties
     * @return array{created: int, skipped: int, dates: list<string>}
     */
    public function store(User $actor, array $rows, string $event, array $properties = []): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['date']] ??= $row['name'];
        }

        $result = DB::transaction(function () use ($actor, $unique, $event, $properties): array {
            $existing = $this->existingNames(array_keys($unique));
            $new = array_diff_key($unique, $existing);
            ksort($new);

            $now = now();
            $created = 0;

            foreach (array_chunk($new, 100, true) as $chunk) {
                $created += Holiday::query()->insertOrIgnore(array_map(
                    fn (string $date, string $name): array => [
                        'date' => $date,
                        'name' => $name,
                        'scope' => 'company',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    array_keys($chunk),
                    array_values($chunk),
                ));
            }

            $dates = array_keys($new);

            if ($created > 0) {
                activity('holidays')
                    ->causedBy($actor)
                    ->event($event)
                    ->withProperties([...$properties, 'dates' => $dates, 'created' => $created, 'skipped' => count($unique) - $created])
                    ->log(self::message("absences.activity.{$event}"));
            }

            return ['created' => $created, 'skipped' => count($unique) - $created, 'dates' => $dates];
        });

        // insertOrIgnore no dispara eventos del modelo: se invalida aquí, tras confirmar.
        if ($result['created'] > 0) {
            ReportCache::bump();
        }

        return $result;
    }

    /**
     * Nombre de los festivos que ya hay en esas fechas.
     *
     * @param  list<string>  $dates
     * @return array<string, string>
     */
    private function existingNames(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $names = [];

        foreach (array_chunk($dates, 400) as $chunk) {
            foreach (Holiday::query()->whereIn('date', $chunk)->get(['date', 'name']) as $holiday) {
                $names[$holiday->date->toDateString()] = $holiday->name;
            }
        }

        return $names;
    }

    /**
     * Calendario .ics: una fila por día de cada VEVENT, con la línea donde empieza el evento.
     *
     * @return list<array{line: int, date: string|null, name: string|null, error: string|null, note: string|null}>
     */
    private function parseIcs(string $contents, int $year): array
    {
        // Líneas lógicas: las que empiezan por espacio o tabulador continúan la anterior (RFC 5545).
        $numbers = [];
        $texts = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $index => $line) {
            $last = count($texts) - 1;

            if ($last >= 0 && $line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $texts[$last] .= substr($line, 1);

                continue;
            }

            $numbers[] = $index + 1;
            $texts[] = $line;
        }

        $events = [];
        $current = null;
        // Subcomponentes abiertos dentro del evento (VALARM…): sus propiedades no son del evento.
        $nested = 0;

        foreach ($texts as $position => $text) {
            $upper = strtoupper(trim($text));

            if ($upper === 'BEGIN:VEVENT') {
                $current = ['line' => $numbers[$position], 'dtstart' => null, 'dtend' => null, 'duration' => null, 'summary' => null, 'rrule' => null, 'rdate' => false, 'exdates' => []];
                $nested = 0;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($upper === 'END:VEVENT') {
                $events[] = $current;
                $current = null;

                continue;
            }

            if (str_starts_with($upper, 'BEGIN:')) {
                $nested++;

                continue;
            }

            if (str_starts_with($upper, 'END:')) {
                $nested = max(0, $nested - 1);

                continue;
            }

            if ($nested > 0) {
                continue;
            }

            [$name, $value] = self::property($text);

            switch ($name) {
                case 'DTSTART':
                    $current['dtstart'] = $value;
                    break;
                case 'DTEND':
                    $current['dtend'] = $value;
                    break;
                case 'DURATION':
                    $current['duration'] = $value;
                    break;
                case 'SUMMARY':
                    $current['summary'] = $value;
                    break;
                case 'RRULE':
                    $current['rrule'] = $value;
                    break;
                case 'RDATE':
                    $current['rdate'] = true;
                    break;
                case 'EXDATE':
                    foreach (explode(',', $value) as $excluded) {
                        $current['exdates'][] = trim($excluded);
                    }
                    break;
            }
        }

        if ($events === []) {
            throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.no_events')]);
        }

        $rows = [];

        foreach ($events as $event) {
            foreach ($this->eventRows($event, $year) as $row) {
                if (count($rows) >= self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.too_many', ['max' => self::MAX_ROWS])]);
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Festivos de un VEVENT: uno por día (DTEND o DURATION), en el año elegido si se repite cada año.
     *
     * @param  array{line: int, dtstart: string|null, dtend: string|null, duration: string|null, summary: string|null, rrule: string|null, rdate: bool, exdates: list<string>}  $event
     * @return list<array{line: int, date: string|null, name: string|null, error: string|null, note: string|null}>
     */
    private function eventRows(array $event, int $year): array
    {
        $line = $event['line'];
        $name = $event['summary'] !== null ? self::cleanName(self::unescape($event['summary'])) : '';
        $name = $name === '' ? null : $name;
        $fail = fn (string $error, ?string $date = null): array => [['line' => $line, 'date' => $date, 'name' => $name, 'error' => $error, 'note' => null]];

        if ($event['dtstart'] === null) {
            return $fail(self::message('absences.holidays.import.missing_dtstart'));
        }

        $start = self::icsMoment($event['dtstart']);
        if ($start === null) {
            return $fail(self::message('absences.holidays.import.invalid_date', ['value' => mb_substr($event['dtstart'], 0, 40)]));
        }

        $days = self::icsDays($event, $start['moment']);
        if (is_string($days)) {
            return $fail($days, $start['date']);
        }

        $first = $start['date'];
        $notes = [];

        if ($event['rrule'] !== null || $event['rdate']) {
            [$first, $error] = self::recurrence($event, $start['date'], $year);

            if ($error !== null) {
                return $fail($error, $start['date']);
            }

            $notes[] = self::message('absences.holidays.import.recurring', [
                'from' => CarbonImmutable::parse($start['date'])->format('d/m/Y'),
                'year' => $year,
            ]);
        }

        $error = self::rangeError($first)
            ?? ($name === null ? self::message('absences.holidays.import.missing_summary') : null)
            ?? ($days > self::MAX_SPAN_DAYS ? self::message('absences.holidays.import.too_long', ['days' => $days, 'max' => self::MAX_SPAN_DAYS]) : null);

        if ($error !== null) {
            return $fail($error, $first);
        }

        $from = CarbonImmutable::parse($first);

        if ($days > 1) {
            $notes[] = self::message('absences.holidays.import.multi_day', [
                'days' => $days,
                'from' => $from->format('d/m/Y'),
                'to' => $from->addDays($days - 1)->format('d/m/Y'),
            ]);
        }

        $note = $notes === [] ? null : implode(' ', $notes);
        $rows = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->addDays($offset)->toDateString();
            $error = self::rangeError($date);
            $rows[] = ['line' => $line, 'date' => $date, 'name' => $name, 'error' => $error, 'note' => $error === null ? $note : null];
        }

        return $rows;
    }

    /**
     * Días que ocupa el evento: 1 sin DTEND ni DURATION; si no, su duración redondeada a días (el
     * DTEND no se incluye: un evento de día completo del 24 al 26 termina el 26 a las 0:00). Un
     * error (texto) si el DTEND o la DURATION no se entienden.
     *
     * @param  array{dtend: string|null, duration: string|null}  $event
     */
    private static function icsDays(array $event, CarbonImmutable $start): int|string
    {
        if ($event['dtend'] !== null) {
            $end = self::icsMoment($event['dtend']);

            if ($end === null) {
                return self::message('absences.holidays.import.invalid_end', ['value' => mb_substr($event['dtend'], 0, 40)]);
            }

            $seconds = $end['moment']->getTimestamp() - $start->getTimestamp();
        } elseif ($event['duration'] !== null) {
            $interval = self::duration($event['duration']);

            if ($interval === null) {
                return self::message('absences.holidays.import.invalid_duration', ['value' => mb_substr($event['duration'], 0, 40)]);
            }

            $seconds = $start->add($interval)->getTimestamp() - $start->getTimestamp();
        } else {
            return 1;
        }

        return max(1, (int) round($seconds / 86400));
    }

    /**
     * Fecha del año elegido de un evento que se repite cada año, o el error de su línea: solo se
     * admite RRULE con FREQ=YEARLY (y INTERVAL, COUNT, UNTIL, WKST, y BYMONTH y BYMONTHDAY si son los
     * del DTSTART), con las fechas de EXDATE fuera.
     *
     * @param  array{rrule: string|null, rdate: bool, exdates: list<string>}  $event
     * @return array{0: string, 1: string|null}
     */
    private static function recurrence(array $event, string $start, int $year): array
    {
        $rule = $event['rrule'];
        $unsupported = [$start, self::message('absences.holidays.import.recurring_unsupported', [
            'rule' => $rule === null ? 'RDATE' : mb_substr($rule, 0, 60),
        ])];

        if ($rule === null || $event['rdate']) {
            return $unsupported;
        }

        $parts = [];
        foreach (explode(';', strtoupper($rule)) as $part) {
            if (trim($part) !== '') {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
                $parts[trim($key)] = trim($value);
            }
        }

        $date = CarbonImmutable::parse($start);
        $number = fn (string $key): ?int => isset($parts[$key]) && ctype_digit($parts[$key]) ? (int) $parts[$key] : null;
        $interval = isset($parts['INTERVAL']) ? $number('INTERVAL') : 1;

        $supported = ($parts['FREQ'] ?? '') === 'YEARLY'
            && array_diff(array_keys($parts), ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'WKST', 'BYMONTH', 'BYMONTHDAY']) === []
            && $interval !== null && $interval >= 1
            && (! isset($parts['COUNT']) || $number('COUNT') !== null)
            && (! isset($parts['BYMONTH']) || $number('BYMONTH') === $date->month)
            && (! isset($parts['BYMONTHDAY']) || $number('BYMONTHDAY') === $date->day);

        $until = isset($parts['UNTIL']) ? self::icsMoment($parts['UNTIL']) : null;

        if (! $supported || (isset($parts['UNTIL']) && $until === null)) {
            return $unsupported;
        }

        $notInYear = [$start, self::message('absences.holidays.import.recurring_not_in_year', [
            'from' => $date->format('d/m/Y'),
            'year' => $year,
        ])];

        $offset = $year - $date->year;
        $count = $number('COUNT');

        if ($offset < 0 || $offset % $interval !== 0 || ($count !== null && intdiv($offset, $interval) >= $count)) {
            return $notInYear;
        }

        // Un 29 de febrero no se repite en los años no bisiestos (RFC 5545).
        if (! checkdate($date->month, $date->day, $year)) {
            return $notInYear;
        }

        $target = sprintf('%04d-%02d-%02d', $year, $date->month, $date->day);

        if ($until !== null && $target > $until['date']) {
            return $notInYear;
        }

        foreach ($event['exdates'] as $excluded) {
            if ((self::icsMoment($excluded)['date'] ?? null) === $target) {
                return $notInYear;
            }
        }

        return [$target, null];
    }

    /**
     * CSV «AAAA-MM-DD;Nombre» (o separado por comas o tabuladores). Admite DD/MM/AAAA, líneas en
     * blanco, comentarios (#) y una primera línea de cabecera.
     *
     * @return list<array{line: int, date: string|null, name: string|null, error: string|null, note: null}>
     */
    private function parseCsv(string $contents): array
    {
        $rows = [];
        $first = true;

        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $delimiter = str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',');
            $fields = str_getcsv($line, $delimiter, '"', '');
            $rawDate = trim((string) ($fields[0] ?? ''));
            $name = self::cleanName((string) ($fields[1] ?? ''));
            $date = self::parseDate($rawDate);
            $isFirst = $first;
            $first = false;

            // Cabecera («fecha;nombre»): la primera línea, sin fecha y sin cifras.
            if ($isFirst && $date === null && preg_match('/\d/', $rawDate) !== 1) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.too_many', ['max' => self::MAX_ROWS])]);
            }

            $error = match (true) {
                $rawDate === '' => self::message('absences.holidays.import.missing_date'),
                $date === null => self::message('absences.holidays.import.invalid_date', ['value' => mb_substr($rawDate, 0, 40)]),
                default => self::rangeError($date),
            };

            if ($error === null && $name === '') {
                $error = self::message('absences.holidays.import.missing_name');
            }

            $rows[] = ['line' => $index + 1, 'date' => $date, 'name' => $name === '' ? null : $name, 'error' => $error, 'note' => null];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.unknown_format')]);
        }

        return $rows;
    }

    /**
     * Nombre y valor de una línea de propiedad («DTSTART;VALUE=DATE:20260101» → DTSTART y
     * 20260101). Los dos puntos dentro de un parámetro entre comillas no separan.
     *
     * @return array{0: string, 1: string}
     */
    private static function property(string $line): array
    {
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === '"') {
                $inQuotes = ! $inQuotes;
            } elseif ($line[$i] === ':' && ! $inQuotes) {
                return [strtoupper(trim(explode(';', substr($line, 0, $i))[0])), trim(substr($line, $i + 1))];
            }
        }

        return ['', ''];
    }

    /**
     * Fecha local y momento de un valor de fecha del .ics: «20260101» (VALUE=DATE) o una fecha y
     * hora («20260101T000000», con Z si es UTC: se pasa a la hora de Madrid antes de tomar el día).
     * Con TZID o sin zona, la fecha es la que figura. El momento sirve para medir la duración:
     * en UTC si lleva Z y, si no, la hora tal cual (sin cambios de horario de por medio).
     *
     * @return array{date: string, moment: CarbonImmutable}|null
     */
    private static function icsMoment(string $value): ?array
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', trim($value), $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$hour, $minute, $second] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 60) {
            return null;
        }

        $moment = CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, 'UTC');

        if ($moment === null) {
            return null;
        }

        $date = ($m[7] ?? '') === 'Z'
            ? $moment->setTimezone(LocalTime::timezone())->toDateString()
            : sprintf('%04d-%02d-%02d', $year, $month, $day);

        return ['date' => $date, 'moment' => $moment];
    }

    /**
     * Duración del .ics («P1D», «P2W», «PT12H», «P1DT12H»); null si no se entiende o es negativa.
     */
    private static function duration(string $value): ?DateInterval
    {
        $value = ltrim(strtoupper(trim($value)), '+');

        if (preg_match('/^P(?!$)(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', $value) !== 1) {
            return null;
        }

        try {
            return new DateInterval($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * «2026-01-01», «1/1/2026» o «01.01.2026» → «2026-01-01»; null si no es una fecha válida.
     */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private static function rangeError(string $date): ?string
    {
        $year = (int) substr($date, 0, 4);

        if ($year < SpanishNationalHolidays::MIN_YEAR || $year > SpanishNationalHolidays::MAX_YEAR) {
            return self::message('absences.holidays.import.out_of_range', [
                'date' => CarbonImmutable::parse($date)->format('d/m/Y'),
                'min' => SpanishNationalHolidays::MIN_YEAR,
                'max' => SpanishNationalHolidays::MAX_YEAR,
            ]);
        }

        return null;
    }

    /**
     * Texto en UTF-8 sin BOM: un CSV guardado con Excel en Windows llega en Windows-1252.
     */
    private static function normalize(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = (string) mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }

    /**
     * Texto de un SUMMARY sin los escapes del formato (\, \; \n \\).
     */
    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\(.)/u', fn (array $m): string => in_array($m[1], ['n', 'N'], true) ? ' ' : $m[1], $value);
    }

    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_substr($name, 0, self::MAX_NAME);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function message(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
