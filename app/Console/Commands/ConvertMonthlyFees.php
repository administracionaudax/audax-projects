<?php

namespace App\Console\Commands;

use App\Domain\Billing\MonthlyFeeConversion;
use App\Domain\Reports\Pdf\PdfFormat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Convierte los fees importados de ClickUp al tipo «Fee mensual» (Fase 12, D-382). De propuesta, no
 * automática: con --dry-run solo enseña la lista; sin él, la enseña y pide confirmación (o --force).
 * Con --codigo= se convierten solo esos proyectos.
 */
#[Signature('app:convert-monthly-fees {--dry-run : Solo enseña los proyectos que parecen fees} {--force : Sin pedir confirmación} {--codigo=* : Solo estos códigos de proyecto}')]
#[Description('Propone y convierte los proyectos que parecen fees (código FE de ClickUp) al tipo «Fee mensual»')]
class ConvertMonthlyFees extends Command
{
    public function handle(MonthlyFeeConversion $conversion): int
    {
        /** @var list<string> $codes */
        $codes = array_values(array_filter(array_map(fn (mixed $code): string => strtoupper(trim((string) $code)), (array) $this->option('codigo'))));
        $candidates = array_values(array_filter(
            $conversion->candidates(),
            fn (array $row): bool => $codes === [] || in_array($row['project']->code, $codes, true),
        ));

        if ($candidates === []) {
            $this->info('No hay proyectos que parezcan fees mensuales sin convertir.');

            return self::SUCCESS;
        }

        $this->table(
            ['Código', 'Proyecto', 'Cliente', 'Tipo actual', 'Horas al mes', 'Importe al mes (propuesto)', 'Por qué'],
            array_map(fn (array $row): array => [
                $row['project']->code,
                $row['project']->name,
                (string) data_get($row['project'], 'client.name', '—'),
                $row['project']->billing_type->label(),
                $row['minutes'] !== null ? PdfFormat::minutes($row['minutes']) : 'sin definir',
                $row['amount'] !== null ? PdfFormat::money($row['amount']) : 'sin facturas de Holded',
                match ($row['reason']) {
                    'description_and_code' => 'descripción «Fee mensual» y código FE',
                    'description' => 'descripción «Fee mensual»',
                    default => 'código FE',
                },
            ], $candidates),
        );

        if ($this->option('dry-run')) {
            $this->info(count($candidates).' proyecto(s) se convertirían. Sin --dry-run, se convierten al confirmarlo.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Convertir estos '.count($candidates).' proyecto(s) a «Fee mensual»?')) {
            $this->info('No se ha convertido nada.');

            return self::SUCCESS;
        }

        $converted = $conversion->apply($codes === [] ? null : $codes);
        $this->info("Convertidos {$converted} proyecto(s) a «Fee mensual». Revisa el importe al mes en sus ajustes.");

        return self::SUCCESS;
    }
}
