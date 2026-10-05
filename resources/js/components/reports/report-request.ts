/**
 * URL de un informe (ReportRequestData, D-139) con `formato=` para descargarlo (xlsx, csv, pdf) o
 * imprimirlo (imprimir). Cada ReportKind es una ruta de routes/app/reports.php (Wayfinder); la weekly,
 * de routes/app/weeklies.php (D-192).
 */
import {
    billing,
    client,
    department,
    detail,
    direction,
    hourBankPdf,
    person,
    project,
} from '@/routes/reports';
import { exportMethod as exportHours } from '@/routes/reports/hours';
import { exportMethod as exportProjectTime } from '@/routes/projects/time';
import { pdf as weeklyReportPdf } from '@/routes/weeklies/report';
import type { ReportRequestData } from '@/types';
import type { QueryParams } from '@/wayfinder';

export type ReportFormat = 'xlsx' | 'csv' | 'pdf' | 'imprimir';

export function reportRequestUrl(
    request: ReportRequestData,
    format?: ReportFormat,
): string {
    const query = {
        ...(request.query as QueryParams),
        ...(format ? { formato: format } : {}),
    };
    const id = (name: string) => Number(request.route_params[name]);

    switch (request.kind) {
        case 'direction':
            return direction.url({ query });
        case 'department':
            return department.url(id('department'), { query });
        case 'person':
            return person.url(id('user'), { query });
        case 'client':
            return client.url(id('client'), { query });
        case 'project':
            return project.url(id('project'), { query });
        case 'billing':
            return billing.url({ query });
        case 'detail':
            return detail.url({ query });
        case 'hours':
            return exportHours.url({ query });
        case 'project_hours':
            return exportProjectTime.url(id('project'), { query });
        case 'hour_bank':
            return hourBankPdf.url(
                { project: id('project'), hourBank: id('hourBank') },
                { query },
            );
        case 'weekly':
            return weeklyReportPdf.url(id('cycle'), { query });
        default:
            throw new Error(`Informe desconocido: ${request.kind}`);
    }
}

/** El mismo informe con otra tabla (`tabla=` de Excel y CSV). */
export function withTable(
    request: ReportRequestData,
    table: string,
): ReportRequestData {
    return { ...request, query: { ...request.query, tabla: table } };
}
