<?php

namespace App\Domain\Audit;

use App\Domain\Time\Week;
use App\Enums\ConversationType;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Message;
use App\Models\PersonalDataExport;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\RecurringTaskRule;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Support\Duration;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

/**
 * Elementos citados por una página de la auditoría (D-074), con una consulta por tipo (nunca una
 * por fila) y la papelera incluida: así se nombran también los borrados, pero solo enlazan los
 * que siguen existiendo. Los que ya no están en la base (borrados de verdad) no aparecen: la
 * entrada se nombra con los datos que guardó (AuditEntries).
 */
final class AuditSubjects
{
    /**
     * @param  iterable<Activity>  $activities
     * @return array<string, array<int, AuditSubject>> subject_type → id → elemento
     */
    public function load(iterable $activities): array
    {
        $ids = [];

        foreach ($activities as $activity) {
            if ($activity->subject_type !== null && $activity->subject_id !== null) {
                $ids[$activity->subject_type][] = (int) $activity->subject_id;
            }
        }

        $subjects = [];

        foreach ($ids as $type => $list) {
            $class = Relation::getMorphedModel($type) ?? $type;
            $subjects[$type] = $this->loadType($class, array_values(array_unique($list)));
        }

        return $subjects;
    }

    /**
     * Personas que nombran los elementos (semanas, ausencias, exportaciones), para cargarlas junto
     * a las demás.
     *
     * @param  array<string, array<int, AuditSubject>>  $subjects
     * @return list<int>
     */
    public static function userIds(array $subjects): array
    {
        $ids = [];

        foreach ($subjects as $byId) {
            foreach ($byId as $subject) {
                if ($subject->personId !== null) {
                    $ids[] = $subject->personId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, AuditSubject>
     */
    private function loadType(string $class, array $ids): array
    {
        return match ($class) {
            Project::class => $this->map(
                Project::query()->withTrashed()->whereKey($ids)->get(['id', 'code', 'name', 'deleted_at']),
                fn (Project $project): AuditSubject => AuditSubject::named("{$project->code} · {$project->name}", route('projects.show', ['project' => $project->id], false), $project->trashed()),
            ),
            HourBank::class => $this->map(
                HourBank::query()->withTrashed()->whereKey($ids)->get(['id', 'project_id', 'name', 'deleted_at']),
                fn (HourBank $bank): AuditSubject => AuditSubject::named($bank->name, route('projects.hour-banks.show', ['project' => $bank->project_id, 'hourBank' => $bank->id], false), $bank->trashed()),
            ),
            Task::class => $this->map(
                Task::query()->withTrashed()->whereKey($ids)->get(['id', 'project_id', 'title', 'deleted_at']),
                fn (Task $task): AuditSubject => AuditSubject::named($task->title, route('tasks.show', ['task' => $task->id], false), $task->trashed()),
            ),
            TimeEntry::class => $this->map(
                TimeEntry::query()->whereKey($ids)->get(['id', 'user_id', 'date', 'minutes']),
                fn (TimeEntry $entry): AuditSubject => new AuditSubject(
                    'audit.subjects.time_entry',
                    ['date' => $entry->date->format('d/m/Y'), 'duration' => Duration::format($entry->minutes)],
                    route('time.index', ['semana' => Week::containing($entry->date)->iso(), 'persona' => $entry->user_id], false),
                    personId: $entry->user_id,
                ),
            ),
            TimesheetPeriod::class => $this->map(
                TimesheetPeriod::query()->whereKey($ids)->get(['id', 'user_id', 'week_start']),
                fn (TimesheetPeriod $period): AuditSubject => new AuditSubject(
                    'audit.subjects.timesheet',
                    ['date' => $period->week_start->format('d/m/Y')],
                    route('time.index', ['semana' => Week::containing($period->week_start)->iso(), 'persona' => $period->user_id], false),
                    personId: $period->user_id,
                ),
            ),
            TimeEntryLock::class => $this->map(
                TimeEntryLock::query()->whereKey($ids)->get(['id', 'date_from', 'date_to']),
                fn (TimeEntryLock $lock): AuditSubject => new AuditSubject(
                    'audit.subjects.time_lock',
                    ['from' => $lock->date_from->format('d/m/Y'), 'to' => $lock->date_to->format('d/m/Y')],
                    route('time.locks.index', [], false),
                ),
            ),
            Client::class => $this->map(
                Client::query()->withTrashed()->whereKey($ids)->get(['id', 'name', 'deleted_at']),
                fn (Client $client): AuditSubject => AuditSubject::named($client->name, route('clients.show', ['client' => $client->id], false), $client->trashed()),
            ),
            Absence::class => $this->map(
                Absence::query()->whereKey($ids)->get(['id', 'user_id', 'type', 'start_date', 'end_date']),
                fn (Absence $absence): AuditSubject => new AuditSubject(
                    'audit.subjects.absence',
                    [
                        'type' => $absence->type->label(),
                        'from' => $absence->start_date->format('d/m/Y'),
                        'to' => $absence->end_date->format('d/m/Y'),
                    ],
                    route('absences.team.index', ['mes' => $absence->start_date->format('Y-m')], false),
                    personId: $absence->user_id,
                ),
            ),
            Holiday::class => $this->map(
                Holiday::query()->whereKey($ids)->get(['id', 'name', 'date']),
                fn (Holiday $holiday): AuditSubject => new AuditSubject(
                    'audit.subjects.holiday',
                    ['name' => $holiday->name, 'date' => $holiday->date->format('d/m/Y')],
                    route('admin.holidays.index', ['anio' => $holiday->date->year], false),
                ),
            ),
            ProjectTemplate::class => $this->map(
                ProjectTemplate::query()->withTrashed()->whereKey($ids)->get(['id', 'name', 'deleted_at']),
                fn (ProjectTemplate $template): AuditSubject => AuditSubject::named($template->name, route('templates.edit', ['template' => $template->id], false), $template->trashed()),
            ),
            RecurringTaskRule::class => $this->map(
                RecurringTaskRule::query()->whereKey($ids)->get(['id', 'project_id', 'title']),
                fn (RecurringTaskRule $rule): AuditSubject => AuditSubject::named($rule->title, route('projects.settings', ['project' => $rule->project_id], false)),
            ),
            TaskStatus::class => $this->map(
                TaskStatus::query()->whereKey($ids)->get(['id', 'name']),
                fn (TaskStatus $status): AuditSubject => AuditSubject::named($status->name, route('admin.statuses.index', [], false)),
            ),
            PersonalDataExport::class => $this->map(
                PersonalDataExport::query()->whereKey($ids)->get(['id', 'subject_user_id']),
                fn (PersonalDataExport $export): AuditSubject => new AuditSubject(
                    'audit.subjects.export',
                    [],
                    route('admin.users.edit', ['user' => $export->subject_user_id], false),
                    personId: $export->subject_user_id,
                ),
            ),
            // Envíos de informes (Fase 9, D-141): la programación y cada envío, con su título.
            ReportSchedule::class => $this->map(
                ReportSchedule::query()->whereKey($ids)->get(['id', 'title']),
                fn (ReportSchedule $schedule): AuditSubject => AuditSubject::named($schedule->title, route('reports.schedules.show', ['schedule' => $schedule->id], false)),
            ),
            ReportDelivery::class => $this->map(
                ReportDelivery::query()->whereKey($ids)->get(['id', 'title', 'schedule_id']),
                fn (ReportDelivery $delivery): AuditSubject => AuditSubject::named($delivery->title, $delivery->schedule_id !== null ? route('reports.schedules.show', ['schedule' => $delivery->schedule_id], false) : null),
            ),
            // Chat (Fase 6): el mensaje moderado, con su autor y su conversación, y el grupo.
            Message::class => $this->messages($ids),
            Conversation::class => $this->conversations($ids),
            User::class => $this->map(
                User::query()->whereKey($ids)->get(['id', 'name', 'client_id']),
                fn (User $user): AuditSubject => AuditSubject::named($user->name, $user->client_id === null ? route('admin.users.edit', ['user' => $user->id], false) : null),
            ),
            default => [],
        };
    }

    /**
     * Grupos y canales de equipo del chat (los cambios de D-119 y D-272), con enlace a la
     * conversación.
     *
     * @param  list<int>  $ids
     * @return array<int, AuditSubject>
     */
    private function conversations(array $ids): array
    {
        $names = AuditValues::conversationNames($ids);

        return $this->map(
            Conversation::query()->whereKey($ids)->get(['id', 'type']),
            fn (Conversation $conversation): AuditSubject => new AuditSubject(
                $conversation->type === ConversationType::Group ? 'audit.subjects.group' : 'audit.subjects.named',
                ['name' => $names[$conversation->id] ?? '—'],
                route('chat.show', ['conversation' => $conversation->id], false),
            ),
        );
    }

    /**
     * Mensajes del chat (también los borrados por su autor): «Mensaje de Ana en Chat de …», con
     * enlace al mensaje en su conversación (el admin la abre en modo moderación, D-119).
     *
     * @param  list<int>  $ids
     * @return array<int, AuditSubject>
     */
    private function messages(array $ids): array
    {
        $messages = Message::query()->withTrashed()->whereKey($ids)->get(['id', 'conversation_id', 'user_id', 'deleted_at']);
        $conversations = AuditValues::conversationNames(array_values(array_unique(array_map('intval', $messages->pluck('conversation_id')->all()))));

        return $this->map($messages, fn (Message $message): AuditSubject => new AuditSubject(
            'audit.subjects.message',
            ['conversation' => $conversations[$message->conversation_id] ?? '—'],
            $message->trashed() ? null : route('chat.show', ['conversation' => $message->conversation_id, 'mensaje' => $message->id], false),
            $message->trashed(),
            personId: $message->user_id,
        ));
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  Closure(TModel): AuditSubject  $describe
     * @return array<int, AuditSubject>
     */
    private function map(Collection $models, Closure $describe): array
    {
        $subjects = [];

        foreach ($models as $model) {
            $subjects[(int) $model->getKey()] = $describe($model);
        }

        return $subjects;
    }
}
