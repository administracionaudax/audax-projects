<?php

namespace App\Models;

use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Enums\WeeklyReminderTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de un aviso de la weekly (F-108): enviado, fallido u omitido. El índice único
 * (trigger_key, channel, user_id) es la deduplicación: un mismo disparo nunca llega dos veces.
 *
 * @property int $id
 * @property int|null $weekly_cycle_id
 * @property int|null $user_id
 * @property string|null $recipient_name
 * @property string|null $recipient_email
 * @property WeeklyReminderTemplate $template
 * @property WeeklyReminderChannel $channel
 * @property string $trigger_key
 * @property WeeklyReminderStatus $status
 * @property string|null $error
 * @property int|null $sent_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WeeklyCycle|null $cycle
 * @property-read User|null $user
 * @property-read User|null $sender
 */
#[Fillable(['weekly_cycle_id', 'user_id', 'recipient_name', 'recipient_email', 'template', 'channel', 'trigger_key', 'status', 'error', 'sent_by'])]
class WeeklyReminderLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => WeeklyReminderTemplate::class,
            'channel' => WeeklyReminderChannel::class,
            'status' => WeeklyReminderStatus::class,
        ];
    }

    /**
     * @return BelongsTo<WeeklyCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(WeeklyCycle::class, 'weekly_cycle_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
