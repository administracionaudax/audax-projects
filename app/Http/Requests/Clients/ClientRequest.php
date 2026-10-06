<?php

namespace App\Http\Requests\Clients;

use App\Http\Requests\Admin\Concerns\NormalizesInput;
use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Alta y edición de clientes (SPEC §4.2 y §6, D-022). Crean y editan admins y responsables
 * (ClientPolicy). La tarifa por defecto solo se valida y guarda con view-financials. El estado
 * activo/inactivo va aparte (desactivar y reactivar).
 */
class ClientRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client
            ? Gate::allows('update', $client)
            : Gate::allows('create', Client::class);
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['name', 'tax_id', 'contact_name', 'phone', 'icon']);

        $email = $this->input('contact_email');
        if (is_string($email)) {
            $email = Str::lower(trim($email));
            $this->merge(['contact_email' => $email === '' ? null : $email]);
        }

        $taxId = $this->input('tax_id');
        if (is_string($taxId)) {
            $this->merge(['tax_id' => Str::upper($taxId)]);
        }

        $this->normalizeDecimals(['default_hourly_rate']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $client = $this->route('client');

        $rules = [
            'name' => ['required', 'string', 'max:255', $this->uniqueName(Client::class, $client instanceof Client ? $client->id : null, __('clients.errors.name_taken'))],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Icono del cliente (F-126): un emoji, como en WeeklySync. Un emoji compuesto (banderas,
            // familias…) ocupa varios caracteres: se limita a 16 y a un solo grafema.
            'icon' => ['nullable', 'string', 'max:16', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && (preg_match('/^\X$/u', $value) !== 1 || preg_match('/[\p{L}\s]/u', $value) === 1)) {
                    $fail(__('clients.errors.icon'));
                }
            }],
        ];

        if (Gate::allows('view-financials')) {
            $rules['default_hourly_rate'] = ['nullable', 'decimal:0,2', 'min:0', 'max:99999999.99'];
        }

        // Responsable del cliente (D-232): alguien activo de la plantilla que escribe la Weekly; vacío,
        // se deduce de los proyectos.
        $rules['owner_user_id'] = ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && ! User::query()->whereKey((int) $value)->where('is_active', true)->role(User::WEEKLY_ROLES)->exists()) {
                $fail(__('clients.errors.owner'));
            }
        }];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('clients.attributes.name'),
            'tax_id' => __('clients.attributes.tax_id'),
            'contact_name' => __('clients.attributes.contact_name'),
            'contact_email' => __('clients.attributes.contact_email'),
            'phone' => __('clients.attributes.phone'),
            'notes' => __('clients.attributes.notes'),
            'icon' => __('clients.attributes.icon'),
            'default_hourly_rate' => __('clients.attributes.default_hourly_rate'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function clientData(): array
    {
        $data = [];

        foreach (['name', 'tax_id', 'contact_name', 'contact_email', 'phone', 'icon'] as $key) {
            $data[$key] = $this->filled($key) ? $this->string($key)->toString() : null;
        }

        if ($this->has('owner_user_id')) {
            $data['owner_user_id'] = $this->filled('owner_user_id') ? $this->integer('owner_user_id') : null;
        }

        $notes = $this->input('notes');
        $data['notes'] = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;

        if (Gate::allows('view-financials')) {
            $data['default_hourly_rate'] = $this->filled('default_hourly_rate') ? $this->string('default_hourly_rate')->toString() : null;
        }

        return $data;
    }
}
