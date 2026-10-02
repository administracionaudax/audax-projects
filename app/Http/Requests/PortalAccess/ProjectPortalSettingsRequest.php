<?php

namespace App\Http\Requests\PortalAccess;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Portal del cliente en los ajustes del proyecto (SPEC §11, D-064): abrir la vista del proyecto,
 * enseñar las horas totales por tarea (solo con la vista abierta: si se cierra, también se apagan) y
 * abrir el Gantt de solo lectura (aparte). Quién: quien gestiona el proyecto (ProjectPolicy::update).
 * Un proyecto sin cliente no se puede abrir.
 */
class ProjectPortalSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && Gate::allows('update', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'portal_project_visible' => ['required', 'boolean'],
            'portal_show_task_hours' => ['required', 'boolean'],
            'portal_gantt_visible' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $project = $this->route('project');

                if ($project instanceof Project && $project->client_id === null
                    && ($this->boolean('portal_project_visible') || $this->boolean('portal_gantt_visible'))) {
                    $validator->errors()->add('portal_project_visible', __('portal.access.errors.no_client'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'portal_project_visible' => __('portal.access.attributes.portal_project_visible'),
            'portal_show_task_hours' => __('portal.access.attributes.portal_show_task_hours'),
            'portal_gantt_visible' => __('portal.access.attributes.portal_gantt_visible'),
        ];
    }

    /**
     * @return array{portal_project_visible: bool, portal_show_task_hours: bool, portal_gantt_visible: bool}
     */
    public function settings(): array
    {
        $visible = $this->boolean('portal_project_visible');

        return [
            'portal_project_visible' => $visible,
            'portal_show_task_hours' => $visible && $this->boolean('portal_show_task_hours'),
            'portal_gantt_visible' => $this->boolean('portal_gantt_visible'),
        ];
    }
}
