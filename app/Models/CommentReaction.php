<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reacción con emoji a un comentario (SPEC §6).
 *
 * @property int $id
 * @property int $task_comment_id
 * @property int $user_id
 * @property string $emoji
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read TaskComment $comment
 * @property-read User $user
 */
#[Fillable(['task_comment_id', 'user_id', 'emoji'])]
class CommentReaction extends Model
{
    /**
     * Emojis permitidos (lista cerrada: nada de texto libre).
     */
    public const array EMOJIS = ['👍', '❤️', '🎉', '👀', '✅', '😄'];

    /**
     * @return BelongsTo<TaskComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'task_comment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
