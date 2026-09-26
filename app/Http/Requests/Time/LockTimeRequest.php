<?php

namespace App\Http\Requests\Time;

use App\Models\Client;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Bloqueo de horas (POST /horas/bloqueo): un cliente O un proyecto, un rango de fechas y una
 * referencia opcional (p. ej. el número de factura). La vista previa usa las mismas reglas.
 */
class LockTimeRequest extends TimeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $client = $this->filled('client_id');
            $project = $this->filled('project_id');

            if (! $client && ! $project) {
                $validator->errors()->add('client_id', (string) __('time.errors.lock_scope_required'));
            } elseif ($client && $project) {
                $validator->errors()->add('client_id', (string) __('time.errors.lock_scope_single'));
            }
        }];
    }

    public function client(): ?Client
    {
        return $this->filled('client_id') ? Client::query()->withTrashed()->find($this->integer('client_id')) : null;
    }

    public function project(): ?Project
    {
        return $this->filled('project_id') ? Project::query()->withTrashed()->find($this->integer('project_id')) : null;
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('date_from')->toString());
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('date_to')->toString());
    }

    public function reference(): ?string
    {
        $reference = trim($this->string('reference')->toString());

        return $reference === '' ? null : $reference;
    }
}
