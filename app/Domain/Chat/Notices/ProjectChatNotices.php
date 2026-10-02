<?php

namespace App\Domain\Chat\Notices;

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Mensajes de sistema en el chat del proyecto (SPEC §12): los avisos de la Fase 1 que interesan a
 * todo el equipo del proyecto, con clave y datos (el texto lo pinta la interfaz):
 * - `hour_bank.threshold`: la bolsa llega al 90 % o se agota (100 %). Los umbrales más bajos
 *   (75 % por defecto) solo avisan a gestores, responsables y admins (D-035),
 * - `milestone.completed`: un hito pasa a un estado «hecho».
 * Si el proyecto aún no tiene conversación, se crea con sus miembros (ConversationDirectory).
 */
final class ProjectChatNotices
{
    public const int BANK_THRESHOLD_MIN = 90;

    public function __construct(
        private readonly ConversationDirectory $directory,
        private readonly MessageWriter $writer,
    ) {}

    public function hourBankThreshold(HourBankThresholdReached $event): void
    {
        if ($event->threshold < self::BANK_THRESHOLD_MIN) {
            return;
        }

        $bank = $event->hourBank;
        $project = Project::query()->find($bank->project_id);

        if ($project === null) {
            return;
        }

        $this->writer->system($this->directory->forProject($project), 'hour_bank.threshold', [
            'bank_id' => $bank->id,
            'bank' => $bank->name,
            'threshold' => $event->threshold,
        ]);
    }

    /**
     * Tras guardar una tarea: si es un hito que acaba de completarse, se avisa al confirmar la
     * transacción (así un cambio que se deshace no deja el aviso).
     */
    public function taskUpdated(Task $task): void
    {
        if (! $task->is_milestone || ! $task->wasChanged('completed_at') || $task->completed_at === null) {
            return;
        }

        $taskId = $task->id;
        $title = $task->title;
        $projectId = $task->project_id;

        DB::afterCommit(function () use ($taskId, $title, $projectId): void {
            $project = Project::query()->find($projectId);

            if ($project === null) {
                return;
            }

            $this->writer->system($this->directory->forProject($project), 'milestone.completed', [
                'task_id' => $taskId,
                'task' => $title,
            ]);
        });
    }
}
