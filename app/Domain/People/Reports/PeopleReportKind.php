<?php

namespace App\Domain\People\Reports;

/**
 * Los informes del registro con huella (R2; PLAN-FASE-11 §6.3; D-351), con los nombres de Woffu
 * (W-089 a W-093) para que RR. HH. los reconozca. Su `value` es el segmento de la URL
 * (`/personas/informes/{informe}`).
 *
 * - `registro-mensual`: el «Registro mensual de la jornada», el que pide la Inspección: cada día con
 *   sus horas de entrada y salida, los tramos, lo trabajado y las horas ordinarias, extra y
 *   complementarias (W-089).
 * - `anexo-horas`: el «Anexo de horas» del mes por persona (extra a compensar y a pagar,
 *   complementarias, sin clasificar y el acumulado del año frente al tope de 80 h), para la
 *   representación legal con los datos mínimos (STS 1161/2024) (W-090).
 * - `presencia-diaria` y `presencia-mensual`: previsto frente a trabajado, por día o por mes, con
 *   las incidencias, el cierre y quién validó las correcciones (W-091 y W-092).
 * - `fichajes`: cada fila de la cadena, también las anulaciones, con su huella (W-093).
 * - `incidencias`: los días con incidencias y lo que se ha hecho con ellas.
 *
 * R3 (vacaciones y permisos, D-371; W-096, W-097 y W-071): `saldos`, `actividad` y
 * `justificantes-pendientes` (BuildsLeaveReports).
 */
enum PeopleReportKind: string
{
    case MonthlyRegister = 'registro-mensual';
    case OvertimeAnnex = 'anexo-horas';
    case DailyPresence = 'presencia-diaria';
    case MonthlyPresence = 'presencia-mensual';
    case Punches = 'fichajes';
    case Incidents = 'incidencias';
    case LeaveBalances = 'saldos';
    case LeaveActivity = 'actividad';
    case MissingDocuments = 'justificantes-pendientes';

    public function title(): string
    {
        return __("people.reports.kinds.{$this->value}.title");
    }

    /** Los que se piden por mes (el resto, por un periodo de días). */
    public function monthly(): bool
    {
        return in_array($this, [self::MonthlyRegister, self::OvertimeAnnex, self::MonthlyPresence], true);
    }

    /** Clave estable para la auditoría y `people_exports`. */
    public function key(): string
    {
        return str_replace('-', '_', $this->value);
    }
}
