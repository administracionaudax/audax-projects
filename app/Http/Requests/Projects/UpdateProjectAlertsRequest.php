<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectAlert;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alertas de un gestor en un proyecto (D-023): cada gestor edita las suyas y un admin, las de
 * cualquiera (ProjectPolicy::updateAlerts). Llegan como {alerts: {hour_bank_threshold: true…}}.
 */
class UpdateProjectAlertsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $manager */
        $manager = $this->route('user');

        return $this->user()?->can('updateAlerts', [$this->route('project'), $manager]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['alerts' => ['required', 'array']];

        foreach (ProjectAlert::values() as $alert) {
            $rules["alerts.{$alert}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, bool>
     */
    public function preferences(): array
    {
        $preferences = [];

        foreach (ProjectAlert::values() as $alert) {
            if ($this->has("alerts.{$alert}")) {
                $preferences[$alert] = $this->boolean("alerts.{$alert}");
            }
        }

        return $preferences;
    }
}
