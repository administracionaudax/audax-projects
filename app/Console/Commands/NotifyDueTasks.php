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

/**
 * Avisos de tareas que vencen mañana o ya vencidas (SPEC §13), en la app. Una sola notificación por
 * persona y día (resumen), sin repetir aunque el comando se ejecute varias veces el mismo día.
 * Solo tareas abiertas, con responsable interno y activo, de proyectos no archivados. «Hoy» y
 * «mañana» son los de Europe/Madrid.
 *
 * Se programa una vez al día en routes/console.php (lo hace el área de Horas).
 */
#[Signature('app:notify-due-tasks')]
#[Description('Avisa a cada persona de sus tareas que vencen mañana o ya vencidas (una vez al día)')]
class NotifyDueTasks extends Command
{
    public function handle(): int
    {
        $today = LocalTime::today();
        $tomorrow = $today->addDay()->toDateString();
        // Inicio del día de hoy en Madrid, en UTC: lo enviado desde entonces cuenta como «hoy».
        $dayStartsAt = $today->utc();

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

            foreach ($userTasks as $task) {
                if ($task->due_date?->toDateString() === $tomorrow) {
                    $dueTomorrow[] = $task->title;
                } elseif ($task->due_date !== null && $task->due_date->toDateString() < $today->toDateString()) {
                    $overdue[] = $task->title;
                }
            }

            if ($dueTomorrow === [] && $overdue === []) {
                continue;
            }

            $user->notify(new TasksDueNotification($dueTomorrow, $overdue));
            $sent++;
        }

        $this->info("Avisos enviados: {$sent}.");

        return self::SUCCESS;
    }
}
