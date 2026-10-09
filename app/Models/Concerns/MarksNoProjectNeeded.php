<?php

namespace App\Models\Concerns;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * «No necesita proyecto» (D-431) en una factura: gastos repercutidos o una factura suelta que no va
 * a ningún proyecto ni bolsa. Deja de contar en «Sin proyecto», en el contador de «Por revisar», en
 * «Requiere atención» y en la cobertura. Se puede deshacer («Necesita proyecto»).
 *
 * Tres columnas en la tabla del documento: `no_project_needed_at`, `no_project_needed_by` y
 * `no_project_note`. Hoy las tiene `holded_invoices`; las facturas propias de la emisión (E1,
 * `sales_documents`) llevarán las mismas y este trait, así la vista `billing_documents` las une sin
 * una tabla aparte (PLAN-EMISION §4.6). No son datos fiscales: el trigger de las emitidas las deja
 * cambiar.
 *
 * @property CarbonImmutable|null $no_project_needed_at
 * @property int|null $no_project_needed_by
 * @property string|null $no_project_note
 * @property-read User|null $noProjectNeededBy
 */
trait MarksNoProjectNeeded
{
    /** Motivo como mucho (la columna es de 500). */
    public const int NO_PROJECT_NOTE_MAX = 500;

    public function initializeMarksNoProjectNeeded(): void
    {
        $this->mergeCasts(['no_project_needed_at' => 'immutable_datetime']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function noProjectNeededBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'no_project_needed_by');
    }

    public function noProjectNeeded(): bool
    {
        return $this->no_project_needed_at !== null;
    }

    /** Marca que no necesita proyecto (con un motivo opcional). No toca nada de Holded. */
    public function markNoProjectNeeded(User $user, ?string $note): void
    {
        $note = $note === null ? null : trim($note);

        $this->forceFill([
            'no_project_needed_at' => now(),
            'no_project_needed_by' => $user->id,
            'no_project_note' => $note === '' || $note === null ? null : Str::limit($note, self::NO_PROJECT_NOTE_MAX, ''),
        ])->save();
    }

    /** «Necesita proyecto»: quita la marca. */
    public function clearNoProjectNeeded(): void
    {
        $this->forceFill(['no_project_needed_at' => null, 'no_project_needed_by' => null, 'no_project_note' => null])->save();
    }

    /**
     * Solo las que necesitan proyecto (sin la marca).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function whereProjectNeeded(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->qualifyColumn('no_project_needed_at'));
    }
}
