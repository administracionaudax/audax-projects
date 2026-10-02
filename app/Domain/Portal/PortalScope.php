<?php

namespace App\Domain\Portal;

use App\Enums\PortalPersonDisplay;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lo que puede ver un usuario del portal (SPEC §11, D-064). TODA consulta del portal sale de aquí:
 * - sus proyectos y bolsas: los de SU cliente (cualquier estado, para el histórico),
 * - sus horas: las de esos proyectos en los estados que permite su cliente (por defecto,
 *   aprobadas y bloqueadas; nunca borradores),
 * - un proyecto solo si el admin lo ha abierto al portal; su Gantt, solo si también está abierto,
 * - las personas, con el nombre, las iniciales o «Equipo», según el cliente,
 * - nunca costes, tarifas, importes, comentarios internos ni el chat.
 */
final class PortalScope
{
    private function __construct(
        /** El usuario del portal; null en el alcance de un cliente sin usuario (forClient). */
        public readonly ?User $user,
        public readonly Client $client,
    ) {}

    /**
     * @throws HttpException 403 si no es un usuario del portal activo de un cliente activo
     */
    public static function for(User $user): self
    {
        $client = $user->isClient() && $user->is_active && $user->client_id !== null
            ? Client::query()->find($user->client_id)
            : null;

        if ($client === null || ! $client->is_active) {
            throw new HttpException(403, __('portal.errors.no_access'));
        }

        return new self($user, $client);
    }

    /**
     * Lo que ve un cliente sin pasar por uno de sus usuarios, para lo que corre fuera de una
     * petición del portal (los avisos de bolsa, D-065): todo depende del cliente, así que es lo
     * mismo que ve cualquiera de sus usuarios. Nunca lanza: no comprueba que el cliente esté activo
     * (lo decide quien lo usa; los avisos se saltan un cliente desactivado).
     */
    public static function forClient(Client $client): self
    {
        return new self(null, $client);
    }

    /**
     * @return Builder<Project>
     */
    public function projects(): Builder
    {
        return Project::query()->where('client_id', $this->client->id);
    }

    /**
     * @return Builder<HourBank>
     */
    public function hourBanks(): Builder
    {
        return HourBank::query()->whereIn('project_id', $this->projects()->select('id'));
    }

    /**
     * Horas visibles para el cliente (de sus proyectos y en los estados que permite). Las columnas
     * van cualificadas, para poder unir otras tablas (PortalBankFigures::entries).
     *
     * @return Builder<TimeEntry>
     */
    public function entries(): Builder
    {
        return TimeEntry::query()
            ->whereIn('time_entries.project_id', $this->projects()->select('id'))
            ->whereIn('time_entries.status', $this->visibleStatuses());
    }

    /**
     * @return Builder<TimeEntry>
     */
    public function bankEntries(HourBank $bank): Builder
    {
        return $this->entries()->where('time_entries.hour_bank_id', $bank->id);
    }

    /**
     * @return list<string>
     */
    public function visibleStatuses(): array
    {
        return $this->client->portal_entry_visibility->statuses();
    }

    public function ownsProject(Project $project): bool
    {
        return $project->client_id === $this->client->id;
    }

    public function ownsBank(HourBank $bank): bool
    {
        $project = $bank->relationLoaded('project') ? $bank->project : Project::query()->find($bank->project_id);

        return $project !== null && $this->ownsProject($project);
    }

    /**
     * Vista del proyecto (tareas y estados): solo si el admin la ha abierto al portal.
     */
    public function canViewProject(Project $project): bool
    {
        return $this->ownsProject($project) && $project->portal_project_visible;
    }

    /**
     * Gantt de solo lectura con hitos: se abre aparte de la vista del proyecto.
     */
    public function canViewGantt(Project $project): bool
    {
        return $this->ownsProject($project) && $project->portal_gantt_visible;
    }

    /**
     * Horas por tarea en la vista del proyecto (nunca por persona).
     */
    public function canViewTaskHours(Project $project): bool
    {
        return $this->canViewProject($project) && $project->portal_show_task_hours;
    }

    public function personLabel(?User $person): string
    {
        return self::label($this->client->portal_person_display, $person);
    }

    public static function label(PortalPersonDisplay $display, ?User $person): string
    {
        if ($person === null || $display === PortalPersonDisplay::Team) {
            return __('portal.person.team');
        }

        if ($display === PortalPersonDisplay::Name) {
            return $person->name;
        }

        $initials = '';
        foreach (preg_split('/\s+/u', trim($person->name)) ?: [] as $word) {
            if ($word !== '') {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1)).'.';
            }
        }

        return $initials === '' ? __('portal.person.team') : $initials;
    }
}
