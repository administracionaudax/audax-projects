<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Correspondencia de un objeto importado con su modelo local (D-136): única por (source, kind,
 * external_id). La escribe y la lee App\Domain\Import\ClickUp\ImportRefs.
 *
 * @property int $id
 * @property string $source
 * @property string $kind
 * @property string $external_id
 * @property string $local_type
 * @property int $local_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['source', 'kind', 'external_id', 'local_type', 'local_id'])]
class ImportRef extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_id' => 'integer',
        ];
    }
}
