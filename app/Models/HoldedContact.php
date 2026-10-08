<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contacto de Holded (Fase 12, D-387), espejo de solo lectura. Se casa con un cliente de Audax por
 * NIF y, si no, por nombre (HoldedContactMatcher); los que no casan se resuelven a mano en
 * /facturacion/contactos. Nunca crea clientes.
 *
 * @property int $id
 * @property string $holded_id
 * @property string $name
 * @property string|null $trade_name
 * @property string|null $tax_id
 * @property string|null $tax_id_normalized
 * @property string|null $email
 * @property string|null $type
 * @property string|null $country_code
 * @property array<string, string|null>|null $address
 * @property int|null $client_id
 * @property string|null $match_method tax_id | name | manual
 * @property CarbonImmutable|null $ignored_at
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client|null $client
 */
#[Fillable([
    'holded_id',
    'name',
    'trade_name',
    'tax_id',
    'tax_id_normalized',
    'email',
    'type',
    'country_code',
    'address',
    'client_id',
    'match_method',
    'ignored_at',
    'resolved_by',
    'synced_at',
])]
class HoldedContact extends Model
{
    public const string MATCH_TAX_ID = 'tax_id';

    public const string MATCH_NAME = 'name';

    public const string MATCH_MANUAL = 'manual';

    /** Casado por un nombre parecido (D-248): sale en «Por revisar» hasta que alguien lo confirme. */
    public const string MATCH_APPROX = 'approx';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'ignored_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** Sin cliente y sin descartar: pendiente de resolver a mano. */
    public function isUnresolved(): bool
    {
        return $this->client_id === null && $this->ignored_at === null;
    }
}
