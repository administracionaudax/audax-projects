<?php

namespace App\Http\Requests\Templates\Concerns;

use App\Enums\BillingType;
use App\Models\ProjectTemplate;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * «Desde plantilla» en el alta de un proyecto (SPEC §6, D-058): una plantilla activa, la fecha
 * desde la que se cuentan sus días (por defecto el inicio del proyecto o hoy) y, si el proyecto es
 * de bolsas, los datos de su primera bolsa (`hour_bank.*`), a la que irán todas las tareas. La usa
 * StoreProjectRequest, que también usa HourBankRules (hourBankRules(), hourBankMessages()…).
 */
trait CreatesFromTemplate
{
    private ?ProjectTemplate $chosenTemplate = null;

    /**
     * Campos del alta que no son del proyecto (no se guardan en él).
     *
     * @return list<string>
     */
    public static function templateFields(): array
    {
        return ['template_id', 'template_start', 'hour_bank'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateRules(): array
    {
        $rules = [
            'template_id' => ['nullable', 'integer'],
            'template_start' => ['nullable', 'date_format:Y-m-d'],
        ];

        if ($this->wantsTemplateBank()) {
            $rules['hour_bank'] = ['required', 'array'];
            $rules = [...$rules, ...$this->hourBankRules('hour_bank.')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function templateMessages(): array
    {
        return [
            ...$this->hourBankMessages('hour_bank.'),
            'hour_bank.required' => $this->transText('templates.errors.bank_required'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function templateAttributes(): array
    {
        return [
            'template_id' => $this->transText('templates.attributes.template_id'),
            'template_start' => $this->transText('templates.attributes.template_start'),
            ...$this->hourBankAttributes('hour_bank.'),
        ];
    }

    /**
     * Una plantilla que ya no está activa (o que quien crea no puede aplicar) no se aplica.
     */
    protected function checkTemplate(Validator $validator): void
    {
        if (! $this->filled('template_id') || $validator->errors()->has('template_id')) {
            return;
        }

        $template = $this->template();

        if ($template === null || ! ($this->user()?->can('apply', $template) ?? false)) {
            $validator->errors()->add('template_id', $this->transText('templates.errors.template_unavailable'));
        }
    }

    /**
     * Proyecto de bolsas desde plantilla: sus tareas necesitan una bolsa, que se crea en el alta.
     */
    public function wantsTemplateBank(): bool
    {
        return $this->filled('template_id') && $this->input('billing_type') === BillingType::HourBank->value;
    }

    public function template(): ?ProjectTemplate
    {
        if (! $this->filled('template_id') || ! is_numeric($this->input('template_id'))) {
            return null;
        }

        return $this->chosenTemplate ??= ProjectTemplate::query()
            ->whereKey((int) $this->input('template_id'))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Día 0 de la plantilla: el elegido, el inicio del proyecto o hoy.
     */
    public function templateStart(): CarbonImmutable
    {
        $start = $this->validated('template_start') ?? $this->validated('start_date');

        return is_string($start) && $start !== ''
            ? CarbonImmutable::parse($start)->startOfDay()
            : LocalTime::today();
    }

    /**
     * Datos de la primera bolsa (validados), si el proyecto de bolsas se crea desde plantilla.
     *
     * @return array<string, mixed>|null
     */
    public function templateBankData(): ?array
    {
        $data = $this->wantsTemplateBank() ? $this->validated('hour_bank') : null;

        return is_array($data) ? $data : null;
    }
}
