import type { ReactNode } from 'react';
import { BillableHoursChart } from '@/components/charts/billable-hours-chart';
import { CalendarHeatmap } from '@/components/charts/calendar-heatmap';
import { DepartmentHoursChart } from '@/components/charts/department-hours-chart';
import { WeeklyHoursChart } from '@/components/charts/weekly-hours-chart';
import {
    billableHours,
    dailyHours,
    departmentHours,
    weeklyHours,
} from '@/components/styleguide/sample-data';
import { Section } from '@/components/styleguide/section';

const WEEKLY = weeklyHours();
const DEPARTMENTS = departmentHours();
const BILLABLE = billableHours();
const DAILY = dailyHours();

function ChartCard({ children }: { children: ReactNode }) {
    return (
        <div className="min-w-0 rounded-[3px] border bg-card p-4 sm:p-6">
            {children}
        </div>
    );
}

export function ChartsSection() {
    return (
        <Section
            id="graficas"
            title="Gráficas"
            description="Recharts con los colores del tema (var(--chart-1…6), en orden fijo), ejes y rejilla recesivos, líneas de 2 px, tooltips en h:mm y la alternativa «Ver como tabla». Sin tartas ni donuts."
        >
            <div className="grid gap-4 xl:grid-cols-2">
                <ChartCard>
                    <WeeklyHoursChart data={WEEKLY} />
                </ChartCard>
                <ChartCard>
                    <DepartmentHoursChart data={DEPARTMENTS} />
                </ChartCard>
                <ChartCard>
                    <BillableHoursChart data={BILLABLE} />
                </ChartCard>
                <ChartCard>
                    <CalendarHeatmap
                        days={DAILY}
                        title="Horas imputadas por día · Laura Gómez"
                    />
                </ChartCard>
            </div>
        </Section>
    );
}
