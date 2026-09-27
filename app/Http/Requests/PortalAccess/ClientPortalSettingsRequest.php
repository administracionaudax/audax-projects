<?php

namespace App\Http\Requests\PortalAccess;

use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Ajustes del portal de un cliente (SPEC §11, D-064, D-065): cómo se nombra a las personas, qué
 * horas ve (aprobadas y bloqueadas, o también enviadas; nunca borradores) y si recibe los avisos
 * de sus bolsas por email. Son datos del cliente: los cambia quien lo edita (ClientPolicy::update).
 */
class ClientPortalSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && Gate::allows('update', $client);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'portal_person_display' => ['required', 'string', Rule::enum(PortalPersonDisplay::class)],
            'portal_entry_visibility' => ['required', 'string', Rule::enum(PortalEntryVisibility::class)],
            'portal_notify_thresholds' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'portal_person_display' => __('portal.access.attributes.portal_person_display'),
            'portal_entry_visibility' => __('portal.access.attributes.portal_entry_visibility'),
            'portal_notify_thresholds' => __('portal.access.attributes.portal_notify_thresholds'),
        ];
    }

    /**
     * @return array{portal_person_display: PortalPersonDisplay, portal_entry_visibility: PortalEntryVisibility, portal_notify_thresholds: bool}
     */
    public function settings(): array
    {
        return [
            'portal_person_display' => PortalPersonDisplay::from($this->string('portal_person_display')->toString()),
            'portal_entry_visibility' => PortalEntryVisibility::from($this->string('portal_entry_visibility')->toString()),
            'portal_notify_thresholds' => $this->boolean('portal_notify_thresholds'),
        ];
    }
}
