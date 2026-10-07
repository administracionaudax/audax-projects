<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\MonthClose;
use App\Models\User;

/**
 * Cierres mensuales de mi registro (Fase 11, R2; D-357): los totales, mi respuesta y la huella de
 * cada PDF (los PDF se descargan en «Mi registro»).
 */
final class MonthClosesSection extends Section
{
    public function key(): string
    {
        return 'cierres-mensuales';
    }

    protected function textKey(): string
    {
        return 'month_closes';
    }

    protected function columnKeys(): array
    {
        return ['month', 'version', 'status', 'worked_minutes', 'expected_minutes', 'difference_minutes', 'overtime_minutes', 'generated_at', 'confirmed_at', 'disagreed_at', 'disagreement_note', 'reopened_at', 'reopen_reason', 'pdf_sha256'];
    }

    public function rows(User $user): iterable
    {
        foreach (MonthClose::query()->where('user_id', $user->id)->orderBy('month')->orderBy('version')->get() as $close) {
            yield [
                'month' => $close->monthKey(),
                'version' => $close->version,
                'status' => $close->status->value,
                'worked_minutes' => $close->worked_minutes,
                'expected_minutes' => $close->expected_minutes,
                'difference_minutes' => $close->difference_minutes,
                'overtime_minutes' => $close->overtime_minutes,
                'generated_at' => self::instant($close->generated_at),
                'confirmed_at' => self::instant($close->confirmed_at),
                'disagreed_at' => self::instant($close->disagreed_at),
                'disagreement_note' => $close->disagreement_note,
                'reopened_at' => self::instant($close->reopened_at),
                'reopen_reason' => $close->reopen_reason,
                'pdf_sha256' => $close->pdf_sha256,
            ];
        }
    }
}
