<?php

namespace App\Domain\Weeklies\Insights;

use App\Enums\AiSummaryKind;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * La prop `weekly` de la ficha de cliente (F-129 a F-133, D-194): los datos de la pestaña abierta
 * (resumen, historial, equipo o satisfacción), con el resumen con IA donde toca y lo que puede hacer
 * quien mira. Solo se calcula la pestaña abierta.
 */
final class ClientWeeklyTabs
{
    public function __construct(
        private readonly ClientInsights $insights,
        private readonly AiSummaries $summaries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Client $client, string $tab, User $viewer): array
    {
        return match ($tab) {
            'historial' => ['tab' => $tab, 'weeks' => $this->insights->history($client)],
            'equipo' => [
                'tab' => $tab,
                ...$this->insights->team($client),
                'ai' => AiSummaries::present($this->summaries->find(AiSummaryKind::ClientTeamActivity, $client)),
                'my_projects' => self::myProjects($client, $viewer),
            ],
            'satisfaccion' => ['tab' => $tab, ...$this->insights->satisfaction($client)],
            default => [
                'tab' => 'resumen',
                ...$this->insights->summary($client),
                'ai' => AiSummaries::present($this->summaries->find(AiSummaryKind::ClientSummary, $client)),
            ],
        };
    }

    /**
     * Mis proyectos abiertos en el cliente, con si los puedo dejar (no los gestiono, D-156).
     *
     * @return list<array{id: int, code: string, name: string, can_leave: bool}>
     */
    public static function myProjects(Client $client, User $viewer): array
    {
        return array_values(Project::query()
            ->where('client_id', $client->id)
            ->notArchived()
            ->whereHas('members', fn (Builder $members) => $members->whereKey($viewer->id))
            ->with(['members' => fn ($members) => $members->whereKey($viewer->id)])
            ->orderBy('code')
            ->get(['id', 'client_id', 'code', 'name', 'owner_user_id'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'can_leave' => $project->owner_user_id !== $viewer->id && ! (bool) $project->members->first()?->membership?->is_manager,
            ])
            ->all());
    }

    /**
     * Proyectos abiertos del cliente a los que me puedo unir (F-133): los que aún no son míos, si el
     * cliente está activo. La forma de WeeklyJoinableProject.
     *
     * @return list<array<string, mixed>>
     */
    public static function joinableProjects(Client $client, User $viewer): array
    {
        if (! $client->is_active) {
            return [];
        }

        return array_values(Project::query()
            ->where('client_id', $client->id)
            ->notArchived()
            ->whereDoesntHave('members', fn (Builder $members) => $members->whereKey($viewer->id))
            ->orderBy('code')
            ->get(['id', 'client_id', 'code', 'name'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'client' => ['id' => $client->id, 'name' => $client->name, 'icon' => $client->icon],
            ])
            ->all());
    }
}
