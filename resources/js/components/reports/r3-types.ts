/**
 * Informe detallado (R3): contrato con App\Http\Controllers\Reports\DetailReportController.
 * Horas en minutos enteros; sin datos económicos.
 */
import type {
    PivotResult,
    ReportDimension,
    ReportFilterKey,
    ReportFiltersProps,
    ReportQuery,
} from '@/types';

/** Medidas de la URL (medida=): imputadas, facturables, dentro de bolsa y exceso. */
export type DetailMeasure = 'imputadas' | 'facturables' | 'dentro' | 'exceso';

/** Filas, columnas y medida de la tabla dinámica (van en la URL con los filtros). */
export type DetailLayout = {
    filas: ReportDimension;
    columnas: ReportDimension;
    medida: DetailMeasure;
};

/** Filtros del informe con las elecciones de la tabla (DetailReportController las añade). */
export type DetailQuery = ReportQuery & Partial<DetailLayout>;

export type DetailSummary = {
    logged_minutes: number;
    billable_minutes: number;
    in_bank_minutes: number;
    overage_minutes: number;
    billability: number | null;
};

export type DetailPageProps = {
    filters: ReportFiltersProps & {
        query: DetailQuery;
        previous: DetailQuery;
        next: DetailQuery;
    };
    layout: DetailLayout;
    /** Dimensiones que puede elegir quien mira («persona» solo si ve horas de otras personas). */
    dimensions: ReportDimension[];
    measures: DetailMeasure[];
    /** Filtros de la barra que tienen sentido para quien mira (persona y departamento, solo con equipo). */
    filterKeys: ReportFilterKey[];
    pivot: PivotResult;
    summary: DetailSummary;
    /** Resumen del periodo de comparación (con comparar=1). */
    comparison: DetailSummary | null;
};
