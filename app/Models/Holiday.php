<?php

namespace App\Models;

use App\Enums\HolidayLevel;
use Carbon\CarbonImmutable;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Festivo de la empresa (SPEC §4.1): capacidad 0 ese día para todas las personas. Desde la Fase 11
 * (R3, D-367) lleva su nivel (nacional, autonómico, local o de empresa) y la fuente oficial.
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $name
 * @property string $scope
 * @property HolidayLevel|null $level
 * @property string|null $source
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['date', 'name', 'scope', 'level', 'source'])]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'scope' => 'company',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'level' => HolidayLevel::class,
        ];
    }
}
