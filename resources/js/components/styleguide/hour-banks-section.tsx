import { RefreshCw } from 'lucide-react';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { hourBankLevel } from '@/components/charts/thresholds';
import { BRAND_OUTLINE } from '@/components/styleguide/buttons-section';
import { Section } from '@/components/styleguide/section';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate } from '@/lib/format';

const BANKS = [
    {
        id: 1,
        name: 'Bolsa Desarrollo 2026',
        client: 'Clínica Dental Sonríe',
        department: 'Desarrollo',
        from: '2026-01-01',
        to: '2026-12-31',
        consumed: 1875,
        total: 3000,
        committed: 600,
    },
    {
        id: 2,
        name: 'Bolsa Marketing T3',
        client: 'Bodegas Lur',
        department: 'Marketing',
        from: '2026-07-01',
        to: '2026-09-30',
        consumed: 2460,
        total: 3000,
        committed: 840,
    },
    {
        id: 3,
        name: 'Bolsa Mantenimiento web',
        client: 'Hoteles Mediterráneo',
        department: 'Desarrollo',
        from: '2026-03-01',
        to: '2026-12-31',
        consumed: 3150,
        total: 3000,
        committed: 360,
    },
];

export function HourBanksSection() {
    return (
        <Section
            id="bolsas-de-horas"
            title="Bolsas de horas"
            description="Tarjeta por bolsa (SPEC §8): barra con color por umbral (verde < 75 %, ámbar desde el 75 %, rojo al agotarse), marcas en el 75 %, el 90 % y el total, exceso en rojo con icono y horas comprometidas de las tareas abiertas."
        >
            <div className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                {BANKS.map((bank) => {
                    const level = hourBankLevel(bank.consumed, bank.total);

                    return (
                        <Card
                            key={bank.id}
                            className="gap-4 rounded-md py-5 shadow-none"
                        >
                            <CardHeader className="px-5">
                                <CardTitle className="text-base font-medium">
                                    {bank.name}
                                </CardTitle>
                                <CardDescription>
                                    {bank.client} · {bank.department} · del{' '}
                                    {formatDate(bank.from)} al{' '}
                                    {formatDate(bank.to)}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-4 px-5">
                                <HourBankMeter
                                    name={bank.name}
                                    consumed={bank.consumed}
                                    total={bank.total}
                                    committed={bank.committed}
                                />
                                {level !== 'ok' ? (
                                    <div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className={BRAND_OUTLINE}
                                        >
                                            <RefreshCw aria-hidden="true" />
                                            Renovar bolsa
                                        </Button>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </Section>
    );
}
