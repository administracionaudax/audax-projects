<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Tasks\TasksDueNotification;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Avisos de tareas que vencen mañana o ya vencidas (SPEC §13), en la app. Una sola notificación por
 * persona y día (resumen), sin repetir aunque el comando se ejecute varias veces el mismo día.
 * Solo tareas abiertas, con responsable interno y activo, de proyectos no archivados y que ese
 * responsable ve (un colaborador externo, solo las de sus proyectos, D-134). «Hoy» y «mañana» son
 * los de Europe/Madrid.
 *
 * Para no repetir, el propio comando reclama cada aviso con Cache::add (atómico) hasta pasado el
 * día en Madrid, antes de mandarlo a la cola; si la caché se vaciara, el aviso ya guardado en la
 * campana hoy también lo evita. El aviso va por la cola (notify, D-073): la campana por `default`
 * y el email de task.due por `mail`, como el resto de las notificaciones, sin esperar al SMTP.
 *
 * Se programa una vez al día en routes/console.php (lo hace el área de Horas), con
 * withoutOverlapping() y onOneServer().
 */
#[Signature('app:notify-due-tasks')]
#[Description('Avisa a cada persona de sus tareas que vencen mañana o ya vencidas (una vez al día)')]
class NotifyDueTasks extends Command
{
    /**
     * Clave con la que una ejecución reclama el aviso de una persona para un día (Y-m-d de Madrid).
     */
    public static function claimKey(int $userId, string $date): string
    {
        return "tasks-due:{$userId}:{$date}";
    }

    public function handle(): int
    {
        $today = LocalTime::today();
        $tomorrow = $today->addDay()->toDateString();
        // Inicio del día de hoy en Madrid, en UTC: lo enviado desde entonces cuenta como «hoy».
        $dayStartsAt = $today->utc();
        // Hasta pasado el día de hoy en Madrid (con margen por el cambio de hora).
        $claimUntil = $today->addDay()->addHours(3);

        /** @var Collection<int, Task> $tasks */
        $tasks = Task::query()
            ->open()
            ->whereNotNull('assignee_user_id')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $tomorrow)
            ->whereHas('project', fn (Builder $project) => $project->notArchived())
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'title', 'due_date', 'assignee_user_id', 'project_id']);

        if ($tasks->isEmpty()) {
            $this->info('No hay tareas que avisar.');

            return self::SUCCESS;
        }

        $alreadyNotified = User::query()
            ->whereKey($tasks->pluck('assignee_user_id')->unique()->all())
            ->whereHas('notifications', fn (Builder $notifications) => $notifications
                ->where('type', TasksDueNotification::class)
                ->where('created_at', '>=', $dayStartsAt))
            ->pluck('id')
            ->all();

        $recipients = User::query()
            ->whereKey($tasks->pluck('assignee_user_id')->unique()->all())
            ->whereKeyNot($alreadyNotified)
            ->active()
            ->internal()
            ->get()
            ->keyBy('id');

        $sent = 0;

        foreach ($tasks->groupBy('assignee_user_id') as $userId => $userTasks) {
            $user = $recipients->get((int) $userId);

            if (! $user instanceof User) {
                continue;
            }

            $dueTomorrow = [];
            $overdue = [];
            // Un colaborador externo solo oye hablar de las tareas de sus proyectos (D-134), como
            // en Mis tareas (Task::visibleTo): no de las que se quedaron a su nombre en otros.
            $visible = $user->visibleProjectIds();

            foreach ($userTasks as $task) {
                if ($visible !== null && ! in_array($task->project_id, $visible, true)) {
                    continue;
                }

                if ($task->due_date?->toDateString() === $tomorrow) {
                    $dueTomorrow[] = $task->title;
                } elseif ($task->due_date !== null && $task->due_date->toDateString() < $today->toDateString()) {
                    $overdue[] = $task->title;
                }
            }

            if ($dueTomorrow === [] && $overdue === []) {
                continue;
            }

            $claim = self::claimKey($user->id, $today->toDateString());

            if (! Cache::add($claim, true, $claimUntil)) {
                continue;
            }

            try {
                $user->notify(new TasksDueNotification($dueTomorrow, $overdue));
            } catch (Throwable $exception) {
                Cache::forget($claim);

                throw $exception;
            }

            $sent++;
        }

        $this->info("Avisos enviados: {$sent}.");

        return self::SUCCESS;
    }
}
