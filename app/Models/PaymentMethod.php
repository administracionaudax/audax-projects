<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Forma de pago de la emisión propia (PLAN-EMISION §4.1, H-080; D-423): nombre, texto del PDF (con
 * «:iban», que se sustituye por su IBAN o el del emisor) y días hasta el vencimiento. Al emitir se
 * copia el texto en la factura.
 *
 * @property int $id
 * @property string $name
 * @property string|null $document_text
 * @property string|null $document_text_en
 * @property string|null $iban
 * @property int|null $due_days
 * @property bool $is_default
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'document_text', 'document_text_en', 'iban', 'due_days', 'is_default', 'position', 'archived_at'])]
class PaymentMethod extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<PaymentMethod>  $query
     * @return Builder<PaymentMethod>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** El texto del PDF en el idioma de la factura, con el IBAN puesto. */
    public function text(string $language, ?string $issuerIban): string
    {
        $text = ($language === 'en' ? ($this->document_text_en ?? $this->document_text) : $this->document_text) ?? $this->name;
        $iban = $this->iban ?? $issuerIban;

        return trim(str_replace(':iban', $iban === null ? '' : self::formatIban($iban), $text));
    }

    /** «ES12 3456 …» en grupos de cuatro. */
    public static function formatIban(string $iban): string
    {
        return trim(chunk_split(strtoupper((string) preg_replace('/\s+/', '', $iban)), 4, ' '));
    }
}
