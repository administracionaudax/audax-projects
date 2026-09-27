<?php

namespace App\Domain\Templates;

use App\Domain\HourBanks\FirstHourBank;
use App\Domain\Projects\ProjectCreator;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crear un proyecto desde una plantilla (SPEC §6, D-058), todo o nada en una transacción:
 * 1. el proyecto con su gestor principal y sus miembros (ProjectCreator),
 * 2. si es de bolsas, su primera bolsa (FirstHourBank), a la que irán todas las tareas,
 * 3. las tareas, subtareas, hitos y dependencias de la plantilla (ProjectTemplateService::apply),
 *    con fechas contadas desde $start.
 * Si algo falla al aplicar la plantilla, no se crea nada y el error se da en `template_id`.
 */
final class ProjectFromTemplate
{
    public function __construct(
        private readonly ProjectCreator $creator,
        private readonly FirstHourBank $firstBank,
        private readonly ProjectTemplateService $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  datos validados del proyecto
     * @param  list<int>  $memberIds
     * @param  array<string, mixed>|null  $bankData  primera bolsa (proyectos de bolsas)
     * @return array{project: Project, tasks: int}
     *
     * @throws ValidationException
     */
    public function create(array $attributes, array $memberIds, User $creator, ProjectTemplate $template, CarbonImmutable $start, ?array $bankData): array
    {
        try {
            return DB::transaction(function () use ($attributes, $memberIds, $creator, $template, $start, $bankData): array {
                $project = $this->creator->create($attributes, $memberIds, $creator);
                $bank = $project->usesHourBanks() && $bankData !== null ? $this->firstBank->create($project, $bankData)['bank'] : null;
                $tasks = $this->templates->apply($template, $project, $start, $creator, $bank);

                return ['project' => $project, 'tasks' => count($tasks)];
            });
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['template_id' => self::firstMessage($exception)]);
        }
    }

    public static function firstMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return $exception->getMessage();
    }
}
