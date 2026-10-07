<?php

namespace App\Domain\Absences;

use App\Enums\HolidayLevel;

/**
 * Calendario laboral de un centro de trabajo en la ciudad de València (Fase 11, R3; L-23; D-367):
 * las fiestas nacionales, las autonómicas de la Comunitat Valenciana y las dos locales de València,
 * comprobadas una a una en el BOE, el DOGV y valencia.es el 07/10/2026. No se calcula: cada año se
 * copia de su fuente oficial (la importación «nacional» de D-050 no vale para la Comunitat: en 2026
 * añadiría el 6 de diciembre en domingo sin el 24 de junio ni el 9 de octubre).
 *
 * Fuentes:
 * - 2026, nacionales: Resolución de 17/10/2025 de la Dirección General de Trabajo, BOE-A-2025-21667
 *   (BOE núm. 259 de 28/10/2025).
 * - 2026, autonómicas: Decreto 100/2025, de 1 de julio, del Consell (DOGV núm. 10145 de 07/07/2025).
 * - 2026, locales: Resolución de 12/11/2025 (DOGV núm. 10238 de 14/11/2025), València: 22 de enero
 *   y 13 de abril.
 * - 2027, nacionales y autonómicas: Decreto 42/2026, de 20 de marzo, del Consell (DOGV núm. 10329 de
 *   25/03/2026). La resolución estatal de 2027 aún no está en el BOE.
 * - 2027, locales: acuerdo del Pleno del Ayuntamiento (valencia.es, 23/07/2026), 22 de enero y 5 de
 *   abril; **pendiente** de la resolución de fiestas locales del DOGV (sale en noviembre).
 *
 * Además, el convenio estatal de publicidad (BOE-A-2016-1290, art. 23.1) da la **fiesta
 * profesional** del 25 de enero (o el primer viernes laborable siguiente si no cae en viernes) y el
 * **24 y el 31 de diciembre** como permiso retribuido. Van aparte (agreement()) y sin marcar: el
 * convenio aplicable está **pendiente de asesor** (WOFFU-INVESTIGACION F-1).
 */
final class ValenciaHolidays
{
    public const string BOE_2026 = 'BOE-A-2025-21667 (BOE núm. 259, 28/10/2025)';

    public const string DOGV_2026 = 'Decreto 100/2025 del Consell (DOGV núm. 10145, 07/07/2025)';

    public const string DOGV_LOCAL_2026 = 'Resolución de 12/11/2025 de fiestas locales (DOGV núm. 10238, 14/11/2025)';

    public const string DOGV_2027 = 'Decreto 42/2026 del Consell (DOGV núm. 10329, 25/03/2026)';

    public const string LOCAL_2027 = 'Pleno del Ayuntamiento de València (valencia.es, 23/07/2026); pendiente de la resolución de fiestas locales del DOGV';

    public const string AGREEMENT = 'Convenio de publicidad, art. 23.1 (BOE-A-2016-1290); pendiente de asesor';

    /**
     * @var array<int, list<array{0: string, 1: string, 2: string, 3: string}>> año → [MM-DD, nombre, nivel, fuente]
     */
    private const array YEARS = [
        2026 => [
            ['01-01', 'Año Nuevo', 'national', self::BOE_2026],
            ['01-06', 'Epifanía del Señor', 'national', self::BOE_2026],
            ['01-22', 'San Vicente Mártir', 'local', self::DOGV_LOCAL_2026],
            ['03-19', 'San José', 'national', self::BOE_2026],
            ['04-03', 'Viernes Santo', 'national', self::BOE_2026],
            ['04-06', 'Lunes de Pascua', 'regional', self::DOGV_2026],
            ['04-13', 'San Vicente Ferrer', 'local', self::DOGV_LOCAL_2026],
            ['05-01', 'Fiesta del Trabajo', 'national', self::BOE_2026],
            ['06-24', 'San Juan', 'regional', self::DOGV_2026],
            ['08-15', 'Asunción de la Virgen', 'national', self::BOE_2026],
            ['10-09', 'Día de la Comunitat Valenciana', 'regional', self::DOGV_2026],
            ['10-12', 'Fiesta Nacional de España', 'national', self::BOE_2026],
            ['12-08', 'Inmaculada Concepción', 'national', self::BOE_2026],
            ['12-25', 'Navidad', 'national', self::BOE_2026],
        ],
        2027 => [
            ['01-01', 'Año Nuevo', 'national', self::DOGV_2027],
            ['01-06', 'Epifanía del Señor', 'national', self::DOGV_2027],
            ['01-22', 'San Vicente Mártir', 'local', self::LOCAL_2027],
            ['03-19', 'San José', 'national', self::DOGV_2027],
            ['03-26', 'Viernes Santo', 'national', self::DOGV_2027],
            ['03-29', 'Lunes de Pascua', 'regional', self::DOGV_2027],
            ['04-05', 'San Vicente Ferrer', 'local', self::LOCAL_2027],
            ['05-01', 'Fiesta del Trabajo', 'national', self::DOGV_2027],
            ['10-09', 'Día de la Comunitat Valenciana', 'regional', self::DOGV_2027],
            ['10-12', 'Fiesta Nacional de España', 'national', self::DOGV_2027],
            ['11-01', 'Todos los Santos', 'national', self::DOGV_2027],
            ['12-06', 'Día de la Constitución', 'national', self::DOGV_2027],
            ['12-08', 'Inmaculada Concepción', 'national', self::DOGV_2027],
            ['12-25', 'Navidad', 'national', self::DOGV_2027],
        ],
    ];

    /**
     * @return list<int>
     */
    public static function years(): array
    {
        return array_keys(self::YEARS);
    }

    public static function covers(int $year): bool
    {
        return isset(self::YEARS[$year]);
    }

    /**
     * Las fiestas laborales de València del año (vacío si no está comprobado).
     *
     * @return list<array{date: string, name: string, level: string, source: string, pending: bool}>
     */
    public function forYear(int $year): array
    {
        return array_values(array_map(fn (array $row): array => [
            'date' => sprintf('%04d-%s', $year, $row[0]),
            'name' => $row[1],
            'level' => $row[2],
            'source' => $row[3],
            'pending' => $row[3] === self::LOCAL_2027,
        ], self::YEARS[$year] ?? []));
    }

    /**
     * Los días del convenio de publicidad (pendientes de asesor): la fiesta profesional y el 24 y el
     * 31 de diciembre. Si el 25 de enero no es viernes, el primer viernes laborable siguiente.
     *
     * @return list<array{date: string, name: string, level: string, source: string, pending: bool}>
     */
    public function agreement(int $year): array
    {
        $holidays = array_flip(array_column($this->forYear($year), 'date'));
        $day = new \DateTimeImmutable(sprintf('%04d-01-25', $year));

        while ((int) $day->format('N') !== 5 || isset($holidays[$day->format('Y-m-d')])) {
            $day = $day->modify('+1 day');
        }

        $company = HolidayLevel::Company->value;

        return [
            ['date' => $day->format('Y-m-d'), 'name' => 'Fiesta profesional (convenio de publicidad)', 'level' => $company, 'source' => self::AGREEMENT, 'pending' => true],
            ['date' => sprintf('%04d-12-24', $year), 'name' => 'Nochebuena (permiso retribuido del convenio)', 'level' => $company, 'source' => self::AGREEMENT, 'pending' => true],
            ['date' => sprintf('%04d-12-31', $year), 'name' => 'Nochevieja (permiso retribuido del convenio)', 'level' => $company, 'source' => self::AGREEMENT, 'pending' => true],
        ];
    }
}
