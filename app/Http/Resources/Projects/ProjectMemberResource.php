<?php

namespace App\Http\Resources\Projects;

use App\Enums\ProjectAlert;
use App\Http\Resources\UserSummaryResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Miembro de un proyecto con su papel y sus alertas (D-005, D-023, D-032). Contrato:
 * resources/js/types/domain.ts (ProjectMember). El usuario llega de $project->members (pivote
 * `membership`).
 *
 * @mixin User
 */
class ProjectMemberResource extends UserSummaryResource
{
    public function __construct(User $resource, private readonly Project $project)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->resource->membership;
        $preferences = [];

        foreach (ProjectAlert::cases() as $alert) {
            $preferences[$alert->value] = $membership?->wantsAlert($alert) ?? true;
        }

        return [
            ...parent::toArray($request),
            'is_manager' => $membership->is_manager ?? false,
            'is_owner' => $this->resource->id === $this->project->owner_user_id,
            'alert_preferences' => $preferences,
        ];
    }
}
