<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\User;
use App\Models\WeeklyExemption;

/**
 * Tus exenciones de la weekly (Fase 10, D-151): semana, motivo (ausencia, manual o renuncia) y la
 * nota. Quién la puso no se incluye: es otra persona.
 */
final class WeeklyExemptionsSection extends Section
{
    public function key(): string
    {
        return 'weeklies-exenciones';
    }

    protected function textKey(): string
    {
        return 'weekly_exemptions';
    }

    protected function columnKeys(): array
    {
        return ['id', 'week', 'reason', 'absence_id', 'note', 'by_myself', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        $exemptions = WeeklyExemption::query()
            ->where('user_id', $user->id)
            ->with('cycle:id,number')
            ->orderBy('id')
            ->get();

        foreach ($exemptions as $exemption) {
            yield [
                'id' => $exemption->id,
                'week' => $exemption->cycle->number,
                'reason' => $exemption->reason->label(),
                'absence_id' => $exemption->absence_id,
                'note' => $exemption->note,
                'by_myself' => $exemption->created_by === $user->id,
                'created_at' => self::instant($exemption->created_at),
            ];
        }
    }
}
