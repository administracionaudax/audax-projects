<?php

namespace App\Domain\Absences;

use App\Models\Holiday;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Importación de festivos (D-050): un calendario .ics (cada VEVENT con su DTSTART y su SUMMARY) o
 * un CSV con líneas «AAAA-MM-DD;Nombre» (también DD/MM/AAAA y una cabecera opcional).
 *
 * - preview() lee el fichero y devuelve cada festivo con su estado, sin guardar nada: se añade,
 *   ya existe un festivo ese día (se deja como está), se repite en el fichero o tiene un error
 *   (con su número de línea).
 * - store() añade los de las fechas que aún no tienen festivo (nunca duplica: la fecha es única)
 *   y lo deja en la auditoría. Lo usan la importación y «Añadir los festivos nacionales».
 */
final class HolidayImporter
{
    /** Tamaño máximo del fichero (en KB, regla `max` de la validación). */
    public const int MAX_KILOBYTES = 256;

    /** Festivos por fichero, como mucho. */
    public const int MAX_ROWS = 500;

    public const int MAX_NAME = 100;

    public const string NEW = 'new';

    public const string EXISTING = 'existing';

    public const string DUPLICATE = 'duplicate';

    public const string ERROR = 'error';

    /**
     * @return array{format: 'ics'|'csv', rows: list<array{line: int, date: string|null, name: string|null, status: string, message: string|null}>, counts: array<string, int>}
     *
     * @throws ValidationException si el fichero no se puede leer (vacío, formato desconocido, demasiadas líneas)
     */
    public function preview(string $contents, ?string $extension = null): array
    {
        [$format, $parsed] = $this->parse($contents, $extension);

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

            $rows[] = ['line' => $row['line'], 'date' => $date, 'name' => $row['name'], 'status' => $status, 'message' => $message];
        }

        $counts = array_fill_keys([self::NEW, self::EXISTING, self::DUPLICATE, self::ERROR], 0);
        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }

        return ['format' => $format, 'rows' => $rows, 'counts' => $counts];
    }

    /**
     * Festivos del fichero, con su línea y su error (si lo hay).
     *
     * @return array{0: 'ics'|'csv', 1: list<array{line: int, date: string|null, name: string|null, error: string|null}>}
     *
     * @throws ValidationException
     */
    public function parse(string $contents, ?string $extension = null): array
    {
        $contents = self::normalize($contents);

        if (trim($contents) === '') {
            throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.empty')]);
        }

        $isCalendar = str_starts_with(strtoupper(ltrim($contents)), 'BEGIN:VCALENDAR');

        if ($isCalendar) {
            return ['ics', $this->parseIcs($contents)];
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

        return DB::transaction(function () use ($actor, $unique, $event, $properties): array {
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
     * Calendario .ics: una fila por VEVENT, con la línea donde empieza.
     *
     * @return list<array{line: int, date: string|null, name: string|null, error: string|null}>
     */
    private function parseIcs(string $contents): array
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

        foreach ($texts as $position => $text) {
            $number = $numbers[$position];
            $upper = strtoupper(trim($text));

            if ($upper === 'BEGIN:VEVENT') {
                $current = ['line' => $number, 'dtstart' => null, 'summary' => null];

                continue;
            }

            if ($upper === 'END:VEVENT') {
                if ($current !== null) {
                    $events[] = $current;
                }
                $current = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            [$name, $value] = self::property($text);

            if ($name === 'DTSTART') {
                $current['dtstart'] = $value;
            } elseif ($name === 'SUMMARY') {
                $current['summary'] = $value;
            }
        }

        if ($events === []) {
            throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.no_events')]);
        }

        if (count($events) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => self::message('absences.holidays.import.too_many', ['max' => self::MAX_ROWS])]);
        }

        return array_map(function (array $event): array {
            $name = $event['summary'] !== null ? self::cleanName(self::unescape($event['summary'])) : null;
            $date = null;
            $error = null;

            if ($event['dtstart'] === null) {
                $error = self::message('absences.holidays.import.missing_dtstart');
            } else {
                $date = self::icsDate($event['dtstart']);
                $error = $date === null
                    ? self::message('absences.holidays.import.invalid_date', ['value' => mb_substr($event['dtstart'], 0, 40)])
                    : self::rangeError($date);
            }

            if ($error === null && ($name === null || $name === '')) {
                $error = self::message('absences.holidays.import.missing_summary');
            }

            return ['line' => $event['line'], 'date' => $date, 'name' => $name === '' ? null : $name, 'error' => $error];
        }, $events);
    }

    /**
     * CSV «AAAA-MM-DD;Nombre» (o separado por comas o tabuladores). Admite DD/MM/AAAA, líneas en
     * blanco, comentarios (#) y una primera línea de cabecera.
     *
     * @return list<array{line: int, date: string|null, name: string|null, error: string|null}>
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

            $rows[] = ['line' => $index + 1, 'date' => $date, 'name' => $name === '' ? null : $name, 'error' => $error];
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
     * Fecha local de un DTSTART: «20260101» (VALUE=DATE) o una fecha y hora («20260101T000000»,
     * con Z si es UTC: se pasa a la hora de Madrid antes de tomar el día). Con TZID o sin zona,
     * la fecha es la que figura.
     */
    private static function icsDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', $value, $m) !== 1) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        if (($m[7] ?? '') === 'Z') {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], 'UTC')
                ?->setTimezone(LocalTime::timezone())
                ->toDateString();
        }

        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
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
