<?php

namespace App\Http\Requests\Projects\Concerns;

use App\Domain\Projects\ProjectCodeSuggester;
use App\Domain\Projects\ProjectColors;
use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Support\Duration;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reglas comunes de alta y edición de un proyecto (SPEC §4.2 y §6, D-022, D-032).
 * - Cliente: obligatorio salvo en proyectos internos (que no llevan); al crear, solo activos; al
 *   editar se puede conservar el actual aunque esté desactivado.
 * - Código: único (también entre los borrados), en mayúsculas; si ya existe, se sugiere otro.
 * - Importe cerrado y tarifa: solo con view-financials (sin el permiso, se ignoran).
 */
trait ProjectRules
{
    use TranslatesAttributes;

    /** Máximo del presupuesto: 9999:59 (el mayor valor que admite el campo de duración). */
    public const int MAX_BUDGET_MINUTES = 9999 * 60 + 59;

    protected function prepareProjectInput(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $normalized = ProjectCodeSuggester::normalize($code);
            $this->merge(['code' => $normalized === '' ? null : $normalized]);
        }

        if ($this->input('billing_type') === BillingType::Internal->value) {
            // Un proyecto interno no tiene cliente: se descarta el que llegue.
            $this->merge(['client_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function projectRules(?Project $project): array
    {
        $rules = [
            'client_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn (): bool => $this->input('billing_type') !== BillingType::Internal->value),
                Rule::exists('clients', 'id')->withoutTrashed(),
                function (string $attribute, mixed $value, Closure $fail) use ($project): void {
                    if (! is_numeric($value) || (int) $value === $project?->client_id) {
                        return;
                    }

                    if (! Client::query()->active()->whereKey((int) $value)->exists()) {
                        $fail($this->transText('projects.errors.client_inactive'));
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                $project === null ? 'nullable' : 'required',
                'string',
                'max:'.ProjectCodeSuggester::MAX_LENGTH,
                'regex:'.ProjectCodeSuggester::PATTERN,
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['required', 'string', Rule::in(ProjectColors::PALETTE)],
            'billing_type' => ['required', Rule::enum(BillingType::class)],
            'status' => $project?->status === ProjectStatus::Archived
                ? ['prohibited']
                : ['required', Rule::in([
                    ProjectStatus::Planned->value,
                    ProjectStatus::Active->value,
                    ProjectStatus::OnHold->value,
                    ProjectStatus::Completed->value,
                ])],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'budget_minutes' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_BUDGET_MINUTES],
            // Fee mensual (Fase 12, D-382): horas al mes.
            'monthly_minutes' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_BUDGET_MINUTES],
        ];

        if ($this->canSetFinancials()) {
            $rules['fixed_price_amount'] = ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
            $rules['hourly_rate'] = ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];
            $rules['monthly_fee_amount'] = ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function projectMessages(): array
    {
        return [
            'client_id.required' => $this->transText('projects.errors.client_required'),
            'code.regex' => $this->transText('projects.errors.code_format'),
            'color.in' => $this->transText('projects.errors.invalid_color'),
            'status.prohibited' => $this->transText('projects.errors.archived_status'),
            'status.in' => $this->transText('projects.errors.archived_status'),
            'due_date.after_or_equal' => $this->transText('projects.errors.due_before_start'),
            'budget_minutes.max' => $this->transText('hour_banks.errors.total_range', ['max' => Duration::format(self::MAX_BUDGET_MINUTES)]),
            'monthly_minutes.max' => $this->transText('hour_banks.errors.total_range', ['max' => Duration::format(self::MAX_BUDGET_MINUTES)]),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function projectAttributes(): array
    {
        return $this->translatedAttributes('projects', [
            'client_id', 'name', 'code', 'description', 'color', 'billing_type', 'status', 'start_date',
            'due_date', 'budget_minutes', 'monthly_minutes', 'fixed_price_amount', 'monthly_fee_amount', 'hourly_rate', 'owner_user_id', 'member_ids',
        ]);
    }

    /**
     * Código repetido: el error sugiere el primero libre (ACME-WEB-2).
     */
    protected function checkCodeIsFree(Validator $validator, ?Project $project): void
    {
        $code = $this->input('code');

        if (! is_string($code) || $code === '' || $validator->errors()->has('code')) {
            return;
        }

        $codes = app(ProjectCodeSuggester::class);

        if ($codes->taken($code, $project?->id)) {
            $validator->errors()->add('code', $this->transText('projects.errors.code_taken', [
                'code' => $code,
                'suggestion' => $codes->nextFree($code, $project?->id),
            ]));
        }
    }

    protected function canSetFinancials(): bool
    {
        $user = $this->user();

        return $user !== null && Gate::forUser($user)->allows('view-financials');
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected function transText(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
