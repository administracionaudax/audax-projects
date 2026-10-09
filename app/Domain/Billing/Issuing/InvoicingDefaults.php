<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\SeriesKind;
use App\Enums\SifEnvironment;
use App\Enums\TaxOperationType;
use App\Enums\TaxRateKind;
use App\Models\NumberingSeries;
use App\Models\PaymentMethod;
use App\Models\SifInstallation;
use App\Models\TaxRate;
use Carbon\CarbonImmutable;

/**
 * Lo que la emisión propia necesita de partida (PLAN-EMISION §4.1 y §7.1; D-419, D-420 y D-423).
 * Idempotente: solo crea lo que falta (por su clave o su código) y no toca lo que se haya cambiado
 * en Ajustes.
 *
 * - Impuestos: IVA 21, 10, 4 y 0 %; servicios a empresarios de la UE (no sujeta por localización, con
 *   la mención de la inversión del sujeto pasivo); fuera de la UE (no sujeta); exenta (art. 20);
 *   inversión del sujeto pasivo en España (S2); retenciones de IRPF del 15 % y del 7 %.
 * - Formas de pago: transferencia (por defecto, a 30 días) y domiciliación.
 * - Instalaciones del sistema de facturación: producción (F y CN) y pruebas (PRU y PRUCN), cada una
 *   con su cadena (§2.7.4).
 * - Series (P-2, D-419): F[AA]#### y CN[AA]#### desde el 1/1/2027 (el corte de la opción A), y la
 *   de pruebas PRU[AA]#### con su rectificativa PRUCN[AA]####, desde ya y que nunca cuenta.
 *
 * Los textos legales llevan la marca «pendiente de validar con la gestoría» en Ajustes (G-4).
 */
final class InvoicingDefaults
{
    /** El corte de la opción A del plan (§1.2): la serie F empieza el 1/1/2027. */
    public const string CUTOVER = '2027-01-01';

    public static function install(): void
    {
        self::taxRates();
        self::paymentMethods();
        [$production, $test] = self::installations();
        self::series($production, $test);
    }

    private static function taxRates(): void
    {
        $lux = 'Operación no sujeta al IVA español por reglas de localización (art. 69 de la Ley 37/1992).';
        $luxEn = 'Transaction not subject to Spanish VAT under the place-of-supply rules (art. 69 of Law 37/1992).';
        $rates = [
            ['key' => 'iva_21', 'name' => 'IVA 21 %', 'rate' => '21.00', 'operation_type' => TaxOperationType::S1, 'is_default' => true],
            ['key' => 'iva_10', 'name' => 'IVA 10 %', 'rate' => '10.00', 'operation_type' => TaxOperationType::S1],
            ['key' => 'iva_4', 'name' => 'IVA 4 %', 'rate' => '4.00', 'operation_type' => TaxOperationType::S1],
            ['key' => 'iva_0', 'name' => 'IVA 0 %', 'rate' => '0.00', 'operation_type' => TaxOperationType::S1],
            ['key' => 'intra_eu', 'name' => 'Empresa de la UE (inversión del sujeto pasivo)', 'rate' => '0.00', 'operation_type' => TaxOperationType::N2,
                'legal_mention' => $lux.' Inversión del sujeto pasivo (art. 196 de la Directiva 2006/112/CE).',
                'legal_mention_en' => $luxEn.' Reverse charge (art. 196 of Directive 2006/112/EC).'],
            ['key' => 'export', 'name' => 'Fuera de la UE (no sujeta)', 'rate' => '0.00', 'operation_type' => TaxOperationType::N2,
                'legal_mention' => $lux, 'legal_mention_en' => $luxEn],
            ['key' => 'exempt', 'name' => 'Exenta (art. 20)', 'rate' => '0.00', 'operation_type' => TaxOperationType::E1,
                'legal_mention' => 'Operación exenta de IVA (art. 20 de la Ley 37/1992).',
                'legal_mention_en' => 'VAT-exempt transaction (art. 20 of Law 37/1992).'],
            ['key' => 'reverse_charge', 'name' => 'Inversión del sujeto pasivo (España)', 'rate' => '0.00', 'operation_type' => TaxOperationType::S2,
                'legal_mention' => 'Inversión del sujeto pasivo (art. 84.Uno.2.º de la Ley 37/1992).',
                'legal_mention_en' => 'Reverse charge (art. 84.Uno.2.º of Law 37/1992).'],
            ['key' => 'irpf_15', 'name' => 'Retención IRPF 15 %', 'rate' => '15.00', 'kind' => TaxRateKind::Withholding],
            ['key' => 'irpf_7', 'name' => 'Retención IRPF 7 %', 'rate' => '7.00', 'kind' => TaxRateKind::Withholding],
        ];

        foreach ($rates as $position => $rate) {
            if (TaxRate::query()->where('key', $rate['key'])->exists()) {
                continue;
            }

            TaxRate::query()->create([
                'kind' => TaxRateKind::Vat,
                'operation_type' => null,
                'legal_mention' => null,
                'legal_mention_en' => null,
                'is_default' => false,
                ...$rate,
                'position' => $position + 1,
            ]);
        }
    }

    private static function paymentMethods(): void
    {
        if (PaymentMethod::query()->exists()) {
            return;
        }

        PaymentMethod::query()->create([
            'name' => 'Transferencia bancaria',
            'document_text' => 'Transferencia bancaria a la cuenta :iban',
            'document_text_en' => 'Bank transfer to account :iban',
            'due_days' => 30,
            'is_default' => true,
            'position' => 1,
        ]);
        PaymentMethod::query()->create([
            'name' => 'Domiciliación bancaria',
            'document_text' => 'Domiciliación bancaria (recibo SEPA)',
            'document_text_en' => 'SEPA direct debit',
            'due_days' => 30,
            'position' => 2,
        ]);
    }

    /**
     * @return array{0: SifInstallation, 1: SifInstallation}
     */
    private static function installations(): array
    {
        $make = fn (string $number, SifEnvironment $environment): SifInstallation => SifInstallation::query()->firstOrCreate(
            ['installation_number' => $number],
            [
                'sif_code' => (string) config('invoicing.system.code'),
                'sif_name' => (string) config('invoicing.system.name'),
                'environment' => $environment,
                'mode' => 'chain_only',
                'started_at' => CarbonImmutable::now(),
            ],
        );

        return [$make('0001', SifEnvironment::Production), $make('PRU-0001', SifEnvironment::Test)];
    }

    private static function series(SifInstallation $production, SifInstallation $test): void
    {
        $make = function (string $code, string $name, string $type, string $format, SeriesKind $kind, SifInstallation $installation, ?string $starts, bool $default, ?NumberingSeries $refund = null): NumberingSeries {
            return NumberingSeries::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'document_type' => $type,
                'format' => $format,
                'kind' => $kind,
                'sif_installation_id' => $installation->id,
                'starts_on' => $starts,
                'is_default' => $default,
                'refund_series_id' => $refund?->id,
            ]);
        };

        $credit = $make('CN', 'Rectificativas', 'credit_note', 'CN[YY]####', SeriesKind::Regular, $production, self::CUTOVER, true);
        $make('F', 'Facturas', 'invoice', 'F[YY]####', SeriesKind::Regular, $production, self::CUTOVER, true, $credit);
        $testCredit = $make('PRUCN', 'Rectificativas de prueba', 'credit_note', 'PRUCN[YY]####', SeriesKind::Test, $test, null, false);
        $make('PRU', 'Pruebas', 'invoice', 'PRU[YY]####', SeriesKind::Test, $test, null, false, $testCredit);
    }
}
