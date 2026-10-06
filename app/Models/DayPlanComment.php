<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comentario en una línea del plan del día (docs/PLAN-CARGAS.md §4.2, D-255): lo deja el responsable
 * de la persona o un admin (y ella puede contestar). Nadie edita la línea de otro.
 *
 * @property int $id
 * @property int $day_plan_item_id
 * @property int $user_id
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read DayPlanItem $item
 * @property-read User $user
 */
#[Fillable(['day_plan_item_id', 'user_id', 'body'])]
class DayPlanComment extends Model
{
    use LogsDomainActivity;

    /** Caracteres de un comentario. */
    public const int BODY_MAX = 1000;

    /**
     * @return BelongsTo<DayPlanItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(DayPlanItem::class, 'day_plan_item_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
