<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Tasks\TaskWriter;
use App\Enums\MessageType;
use App\Enums\TaskStatusCategory;
use App\Models\Conversation;
use App\Models\HourBank;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Mensajes de sistema en el chat del proyecto (SPEC §12): la bolsa llega al 90 % o se agota
| (HourBankThresholdReached del HourBankLedger) y un hito se completa (cambio de estado de la
| tarea). Sin autor; la interfaz pinta el aviso con su clave y sus datos.
*/

beforeEach(function () {
    Notification::fake();
    TaskStatus::ensureDefaults();
    $this->owner = User::factory()->employee()->create();
    $this->project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    $this->bank = HourBank::factory()->hours(10)->allowOverage()->create(['project_id' => $this->project->id, 'name' => 'Bolsa T4']);
    $this->task = Task::factory()->inBank($this->bank)->create();
    $this->log = fn (int $minutes) => TimeEntry::factory()->forTask($this->task)->minutes($minutes)->on('2026-09-24')->create();
    $this->notices = fn (): array => Message::query()
        ->where('type', MessageType::System->value)
        ->orderBy('id')
        ->get()
        ->map(fn (Message $message): array => [$message->system_key, $message->system_payload])
        ->all();
});

it('avisa en el chat del proyecto cuando la bolsa llega al 90 % y cuando se agota, no antes', function () {
    ($this->log)(8 * 60);
    expect(($this->notices)())->toBe([]);

    ($this->log)(60);
    ($this->log)(60);

    expect(($this->notices)())->toBe([
        ['hour_bank.threshold', ['bank_id' => $this->bank->id, 'bank' => 'Bolsa T4', 'threshold' => 90]],
        ['hour_bank.threshold', ['bank_id' => $this->bank->id, 'bank' => 'Bolsa T4', 'threshold' => 100]],
    ]);

    // El aviso va al chat del proyecto (creado con sus miembros si no existía) y cuenta como no leído.
    $chat = Conversation::query()->where('project_id', $this->project->id)->sole();
    expect(Message::query()->where('type', 'system')->pluck('conversation_id')->unique()->all())->toBe([$chat->id])
        ->and(app(ConversationDirectory::class)->unreadTotal($this->owner))->toBe(2);
});

it('avisa cuando un hito se completa (una vez por cada vez que se completa)', function () {
    $milestone = Task::factory()->milestone()->create(['project_id' => $this->project->id, 'hour_bank_id' => $this->bank->id, 'title' => 'Entrega del diseño']);
    $done = TaskStatus::query()->where('category', TaskStatusCategory::Done->value)->firstOrFail();
    $todo = TaskStatus::defaultStatus();
    $writer = app(TaskWriter::class);

    $writer->update($this->owner, $milestone, ['status_id' => $done->id]);
    $writer->update($this->owner, $milestone->fresh(), ['title' => 'Entrega del diseño final']);

    expect(($this->notices)())->toBe([
        ['milestone.completed', ['task_id' => $milestone->id, 'task' => 'Entrega del diseño']],
    ]);

    $writer->update($this->owner, $milestone->fresh(), ['status_id' => $todo->id]);
    $writer->update($this->owner, $milestone->fresh(), ['status_id' => $done->id]);

    expect(($this->notices)())->toHaveCount(2);
});

it('una tarea normal que se completa no avisa en el chat', function () {
    $done = TaskStatus::query()->where('category', TaskStatusCategory::Done->value)->firstOrFail();

    app(TaskWriter::class)->update($this->owner, $this->task, ['status_id' => $done->id]);

    expect(($this->notices)())->toBe([]);
});

it('si el cambio se deshace (transacción), no queda aviso', function () {
    $milestone = Task::factory()->milestone()->create(['project_id' => $this->project->id, 'hour_bank_id' => $this->bank->id]);
    $done = TaskStatus::query()->where('category', TaskStatusCategory::Done->value)->firstOrFail();

    try {
        DB::transaction(function () use ($milestone, $done): void {
            app(TaskWriter::class)->update($this->owner, $milestone, ['status_id' => $done->id]);

            throw new RuntimeException('Algo falla después');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    expect(($this->notices)())->toBe([]);
});
