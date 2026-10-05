<?php

namespace App\Domain\Weeklies\Insights;

use App\Domain\Weeklies\WeeklyClientSubscriptions;
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
        private readonly WeeklyClientSubscriptions $subscriptions,
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
                // «Unirme a este cliente» de la Weekly (F-133, D-221): una suscripción, no una membresía.
                'subscription' => [
                    'subscribed' => $this->subscriptions->isSubscribed($viewer, $client),
                    'can_join' => $client->is_active,
                ],
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
     * Mis proyectos abiertos en el cliente (solo para enlazarlos: la Weekly no toca la membresía,
     * D-221).
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public static function myProjects(Client $client, User $viewer): array
    {
        return array_values(Project::query()
            ->where('client_id', $client->id)
            ->notArchived()
            ->where(fn (Builder $query) => $query->where('owner_user_id', $viewer->id)
                ->orWhereHas('members', fn (Builder $members) => $members->whereKey($viewer->id)))
            ->orderBy('code')
            ->get(['id', 'client_id', 'code', 'name'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ])
            ->all());
    }
}
