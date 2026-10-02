import { ArrowLeftRight } from 'lucide-react';
import { useId } from 'react';
import type {
    DetailLayout,
    DetailMeasure,
} from '@/components/reports/r3-types';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import type { ReportDimension } from '@/types';

/**
 * Elección de la tabla dinámica: dimensión de las filas y de las columnas (nunca la misma) y la
 * medida. Cada cambio llama a onChange con la tabla completa; la página lo lleva a la URL.
 */
export function PivotControls({
    layout,
    dimensions,
    measures,
    disabled = false,
    onChange,
}: {
    layout: DetailLayout;
    dimensions: ReportDimension[];
    measures: DetailMeasure[];
    disabled?: boolean;
    onChange: (layout: DetailLayout) => void;
}) {
    const id = useId();

    const setRows = (filas: ReportDimension) =>
        onChange({
            ...layout,
            filas,
            // Si coincide con las columnas, se intercambian.
            columnas:
                filas === layout.columnas ? layout.filas : layout.columnas,
        });

    const setColumns = (columnas: ReportDimension) =>
        onChange({
            ...layout,
            columnas,
            filas: columnas === layout.filas ? layout.columnas : layout.filas,
        });

    return (
        <section
            aria-label={t('reports_r3.layout.label')}
            className="flex flex-wrap items-end gap-3"
        >
            <div className="grid gap-1">
                <Label htmlFor={`${id}-rows`}>
                    {t('reports_r3.layout.rows')}
                </Label>
                <Select
                    value={layout.filas}
                    disabled={disabled}
                    onValueChange={(value) => setRows(value as ReportDimension)}
                >
                    <SelectTrigger id={`${id}-rows`} className="w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {dimensions.map((dimension) => (
                            <SelectItem key={dimension} value={dimension}>
                                {t(`reports_r3.dimension.${dimension}`)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <Button
                type="button"
                variant="outline"
                size="icon"
                disabled={disabled}
                aria-label={t('reports_r3.layout.swap')}
                title={t('reports_r3.layout.swap')}
                onClick={() =>
                    onChange({
                        ...layout,
                        filas: layout.columnas,
                        columnas: layout.filas,
                    })
                }
            >
                <ArrowLeftRight aria-hidden="true" />
            </Button>

            <div className="grid gap-1">
                <Label htmlFor={`${id}-columns`}>
                    {t('reports_r3.layout.columns')}
                </Label>
                <Select
                    value={layout.columnas}
                    disabled={disabled}
                    onValueChange={(value) =>
                        setColumns(value as ReportDimension)
                    }
                >
                    <SelectTrigger id={`${id}-columns`} className="w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {dimensions.map((dimension) => (
                            <SelectItem key={dimension} value={dimension}>
                                {t(`reports_r3.dimension.${dimension}`)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-1">
                <Label htmlFor={`${id}-measure`}>
                    {t('reports_r3.layout.measure')}
                </Label>
                <Select
                    value={layout.medida}
                    disabled={disabled}
                    onValueChange={(value) =>
                        onChange({ ...layout, medida: value as DetailMeasure })
                    }
                >
                    <SelectTrigger id={`${id}-measure`} className="w-48">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {measures.map((measure) => (
                            <SelectItem key={measure} value={measure}>
                                {t(`reports_r3.measure.${measure}`)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </section>
    );
}
