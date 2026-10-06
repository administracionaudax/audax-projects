<?php

namespace App\Domain\Forecast;

use App\Domain\Projects\ProjectColors;
use App\Enums\ForecastConfidence;
use App\Enums\ForecastStatus;
use App\Models\Allocation;
use App\Models\ForecastProject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Único punto de escritura de los proyectos previstos (docs/PLAN-CARGAS.md §5.2, D-281):
 *
 * - **Alta y edición**: cliente existente **o** nombre libre (uno de los dos), seguridad «segura» o
 *   «posible» (sin %, P5 b), fechas, estimación global opcional e importe estimado (solo con
 *   view-financials; sin el permiso, se ignora). Solo se editan abiertos o confirmados.
 * - **Confirmar**: un abierto pasa a confirmado y, con él, a «segura».
 * - **Marcar como perdido** (con motivo): deja de contar. **Reabrir**: de perdido a abierto.
 * - **Borrar**: si no está vinculado (el vinculado es la línea base de un proyecto real).
 *
 * Vincular y desvincular están en ForecastLinker. Todo queda en la auditoría (LogsDomainActivity).
 */
final class ForecastProjectWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): ForecastProject
    {
        $attributes = $this->normalize($data, $by, null);
        $attributes['color'] ??= ProjectColors::next(ForecastProject::withTrashed()->count());
        $attributes['owner_user_id'] ??= $by->id;
        $attributes['status'] = ForecastStatus::Open;
        $attributes['confidence'] ??= ForecastConfidence::Tentative;
        $attributes['created_by'] = $by->id;

        return ForecastProject::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ForecastProject $forecast, array $data, User $by): ForecastProject
    {
        if (! $forecast->status->isEditable()) {
            throw ValidationException::withMessages(['forecast' => __('forecast.errors.frozen')]);
        }

        $attributes = $this->normalize($data, $by, $forecast);

        if ($forecast->status === ForecastStatus::Confirmed && ($attributes['confidence'] ?? null) === ForecastConfidence::Tentative) {
            throw ValidationException::withMessages(['confidence' => __('forecast.errors.confirmed_is_firm')]);
        }

        $forecast->fill($attributes)->save();

        return $forecast;
    }

    public function confirm(ForecastProject $forecast): ForecastProject
    {
        $this->expect($forecast, [ForecastStatus::Open]);
        $forecast->update(['status' => ForecastStatus::Confirmed, 'confidence' => ForecastConfidence::Firm]);

        return $forecast;
    }

    public function lose(ForecastProject $forecast, string $reason): ForecastProject
    {
        $this->expect($forecast, [ForecastStatus::Open, ForecastStatus::Confirmed]);
        $reason = trim($reason);

        $forecast->update([
            'status' => ForecastStatus::Lost,
            'lost_reason' => $reason === '' ? null : mb_substr($reason, 0, ForecastProject::REASON_MAX),
            'lost_at' => now(),
        ]);

        return $forecast;
    }

    public function reopen(ForecastProject $forecast): ForecastProject
    {
        $this->expect($forecast, [ForecastStatus::Lost]);
        $forecast->update(['status' => ForecastStatus::Open, 'lost_reason' => null, 'lost_at' => null]);

        return $forecast;
    }

    public function delete(ForecastProject $forecast): void
    {
        if ($forecast->status === ForecastStatus::Linked) {
            throw ValidationException::withMessages(['forecast' => __('forecast.errors.linked_cannot_delete')]);
        }

        DB::transaction(function () use ($forecast): void {
            Allocation::query()->where('forecast_project_id', $forecast->id)->get()->each->delete();
            $forecast->delete();
        });
    }

    /**
     * @param  list<ForecastStatus>  $allowed
     */
    private function expect(ForecastProject $forecast, array $allowed): void
    {
        if (! in_array($forecast->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('forecast.errors.status_change')]);
        }
    }

    /**
     * Solo las claves que llegan (la edición puede ser parcial).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, User $by, ?ForecastProject $forecast): array
    {
        $attributes = [];

        foreach (['name', 'prospect_name', 'description', 'color'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_string($data[$key]) ? trim($data[$key]) : null;
                $attributes[$key] = $value === '' ? null : $value;
            }
        }

        foreach (['client_id', 'owner_user_id', 'estimated_minutes'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = is_numeric($data[$key]) ? (int) $data[$key] : null;
            }
        }

        foreach (['start_date', 'end_date'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;
            }
        }

        if (array_key_exists('confidence', $data)) {
            $attributes['confidence'] = $data['confidence'] instanceof ForecastConfidence
                ? $data['confidence']
                : ForecastConfidence::from((string) $data['confidence']);
        }

        if (array_key_exists('estimated_amount', $data) && Gate::forUser($by)->allows('view-financials')) {
            $attributes['estimated_amount'] = is_numeric($data['estimated_amount']) ? (string) $data['estimated_amount'] : null;
        }

        // Cliente existente o nombre libre: con cliente, el nombre libre sobra.
        $clientId = array_key_exists('client_id', $attributes) ? $attributes['client_id'] : $forecast?->client_id;
        $prospect = array_key_exists('prospect_name', $attributes) ? $attributes['prospect_name'] : $forecast?->prospect_name;

        if ($clientId !== null) {
            $attributes['prospect_name'] = null;
        } elseif ($prospect === null) {
            throw ValidationException::withMessages(['client_id' => __('forecast.errors.client_or_prospect')]);
        }

        if (($attributes['name'] ?? $forecast?->name) === null) {
            throw ValidationException::withMessages(['name' => __('forecast.errors.name')]);
        }

        $start = array_key_exists('start_date', $attributes) ? $attributes['start_date'] : $forecast?->start_date?->toDateString();
        $end = array_key_exists('end_date', $attributes) ? $attributes['end_date'] : $forecast?->end_date?->toDateString();

        if (is_string($start) && is_string($end) && $end < $start) {
            throw ValidationException::withMessages(['end_date' => __('forecast.errors.end_before_start')]);
        }

        return $attributes;
    }
}
