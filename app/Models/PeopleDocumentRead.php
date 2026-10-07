<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «He leído» de una persona en una versión de un documento de RR. HH. (D-354): quién y cuándo.
 *
 * @property int $id
 * @property int $people_document_id
 * @property int $user_id
 * @property CarbonImmutable $read_at
 * @property-read PeopleDocument $document
 * @property-read User $user
 */
class PeopleDocumentRead extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PeopleDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PeopleDocument::class, 'people_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
