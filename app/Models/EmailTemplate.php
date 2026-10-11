<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de email de la emisión propia cambiada en Ajustes (PLAN-EMISION §3.4, E2; D-439): una
 * por tipo (factura, rectificativa, recordatorio) e idioma. Sin fila, vale la de serie
 * (EmailTemplates::defaults, en lang/es/invoicing.php).
 *
 * @property int $id
 * @property string $kind invoice | credit_note | reminder
 * @property string $language es | en
 * @property string $subject
 * @property string $body
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['kind', 'language', 'subject', 'body', 'updated_by'])]
class EmailTemplate extends Model {}
