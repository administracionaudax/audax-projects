<?php

use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\Tasks\MySpaceTasks;
use App\Domain\Weeklies\Tasks\TaskSuggestionPrompt;
use App\Enums\AiFeature;
use App\Enums\DictationContext;
use App\Enums\WeeklyJobState;
use App\Jobs\SuggestTasksFromWeekly;
use App\Models\Client;
use App\Models\Dictation;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\TaskStatus;
use App\Models\TaskSuggestionBatch;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Tareas de «Mi espacio» (10.6, F-055 a F-063, D-203 y D-204): mis tareas agrupadas por cliente con el
| archivado personal y las notas (la descripción en texto plano, con dictado), y «Generar tareas con
| IA» desde la última weekly cerrada: propuestas sin duplicados que la persona revisa antes de crearlas
| con TaskWriter. La IA, siempre FakeLlm.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();
    $this->me = userWithRole('employee', ['name' => 'Ana Díaz', 'created_at' => '2026-08-01']);
    $this->boss = userWithRole('department_manager', ['name' => 'Raúl Gestor', 'created_at' => '2026-08-01']);
    $this->acme = Client::factory()->create(['name' => 'Acme', 'icon' => '🍷']);
    $this->web = Project::factory()->withMembers([$this->me])->create(['client_id' => $this->acme->id, 'code' => 'ACME-WE1', 'name' => 'Web', 'owner_user_id' => $this->boss->id]);
    $this->closed = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create();
    WeeklyCycle::factory()->active('2026-10-05')->create();
});

/** Una weekly enviada de $user en $cycle con sus apuntes (cliente → texto; null = General). */
function tasksWeekly(WeeklyCycle $cycle, User $user, array $entries, bool $submitted = true): WeeklySubmission
{
    $submission = WeeklySubmission::factory()->create([
        'weekly_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'submitted_at' => $submitted ? CarbonImmutable::parse('2026-10-02 17:00', 'Europe/Madrid')->utc() : null,
    ]);

    foreach ($entries as [$client, $body]) {
        WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'client_id' => $client?->id, 'body' => $body]);
    }

    return $submission;
}

// --- La pestaña -----------------------------------------------------------------------------------

it('la pestaña Tareas trae mis tareas con su cliente, sin las de otros ni las de proyectos archivados', function () {
    $mine = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'title' => 'Maquetar la home', 'created_by' => $this->boss->id, 'description' => '<p>Primera línea</p><p>Segunda</p>']);
    $internal = Project::factory()->internal()->withMembers([$this->me])->create(['code' => 'INT-1']);
    $general = Task::factory()->assignedTo($this->me)->create(['project_id' => $internal->id, 'title' => 'Ordenar el Drive', 'created_by' => $this->me->id]);
    Task::factory()->assignedTo($this->boss)->create(['project_id' => $this->web->id, 'title' => 'De otro']);
    Task::factory()->assignedTo($this->me)->create(['project_id' => Project::factory()->archived()->withMembers([$this->me])->create()->id, 'title' => 'Archivada']);
    $rich = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'description' => '<p>Con <strong>negrita</strong></p>']);

    $this->actingAs($this->me)->get('/mi-espacio?pestana=tareas')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('my-space/index')
        ->where('tab', 'tareas')
        ->has('my_tasks', 3)
        ->where('my_tasks', fn ($tasks) => collect($tasks)->pluck('id')->sort()->values()->all() === collect([$mine->id, $general->id, $rich->id])->sort()->values()->all())
        ->where('my_tasks', function ($tasks) use ($mine, $general, $rich) {
            $byId = collect($tasks)->keyBy('id');

            return $byId[$mine->id]['client']['name'] === 'Acme'
                && $byId[$mine->id]['client']['icon'] === '🍷'
                && $byId[$mine->id]['project']['code'] === 'ACME-WE1'
                && $byId[$mine->id]['assigner']['name'] === 'Raúl Gestor'
                && $byId[$mine->id]['notes'] === "Primera línea\nSegunda"
                && $byId[$mine->id]['notes_editable'] === true
                && $byId[$mine->id]['archived'] === false
                && $byId[$mine->id]['can'] === ['update' => true, 'delete' => true]
                && $byId[$general->id]['client'] === null
                && $byId[$general->id]['assigner'] === null
                && $byId[$rich->id]['notes_editable'] === false;
        })
        ->has('task_projects')
        ->where('task_statuses.done', TaskStatus::query()->where('category', 'done')->orderBy('position')->value('id'))
        ->where('suggestions', null)
        ->where('suggestion_source.id', $this->closed->id));
});

it('con la pestaña Reportes no se calcula nada de las tareas', function () {
    $this->actingAs($this->me)->get('/mi-espacio')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('my_tasks', null)
        ->where('task_projects', null)
        ->where('suggestions', null));
});

it('el catálogo: los proyectos abiertos en los que puedo crear, con sus bolsas abiertas; todo para un responsable', function () {
    $banks = Project::factory()->hourBank()->withMembers([$this->me])->create(['client_id' => $this->acme->id, 'code' => 'ACME-BH1']);
    $open = HourBank::factory()->create(['project_id' => $banks->id, 'name' => 'Bolsa 20h']);
    HourBank::factory()->closed()->create(['project_id' => $banks->id]);
    $foreign = Project::factory()->create(['code' => 'OTRO-1']);
    Project::factory()->withMembers([$this->me])->create(['status' => 'completed', 'code' => 'ACME-OLD']);

    $catalog = app(MySpaceTasks::class)->catalog($this->me);
    $codes = array_column($catalog, 'code');

    expect($codes)->toContain('ACME-WE1', 'ACME-BH1')->not->toContain('OTRO-1', 'ACME-OLD');
    $bank = collect($catalog)->firstWhere('code', 'ACME-BH1');
    expect($bank['uses_banks'])->toBeTrue()
        ->and(array_column($bank['banks'], 'id'))->toBe([$open->id]);

    expect(array_column(app(MySpaceTasks::class)->catalog($this->boss), 'code'))->toContain('OTRO-1', $foreign->code);
});

it('limita las hechas a las últimas y las ordena de la más nueva a la más antigua', function () {
    $old = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'created_at' => now()->subDays(3)]);
    $new = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'created_at' => now()->subDay()]);
    $done = Task::factory()->assignedTo($this->me)->completed()->create(['project_id' => $this->web->id, 'created_at' => now()->subDays(2)]);

    $list = app(MySpaceTasks::class)->list($this->me);

    expect(array_column($list, 'id'))->toBe([$new->id, $done->id, $old->id])
        ->and(collect($list)->firstWhere('id', $done->id)['completed'])->toBeTrue();
});

// --- Archivado personal (F-057) -------------------------------------------------------------------

it('archivar es personal: la tarea sigue igual para los demás y se puede recuperar', function () {
    $task = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'title' => 'Compartida']);

    $this->actingAs($this->me)->post("/mi-espacio/tareas/{$task->id}/archivar")->assertRedirect();
    $this->actingAs($this->me)->post("/mi-espacio/tareas/{$task->id}/archivar")->assertRedirect();

    expect(TaskArchive::query()->where('task_id', $task->id)->count())->toBe(1)
        ->and(collect(app(MySpaceTasks::class)->list($this->me))->firstWhere('id', $task->id)['archived'])->toBeTrue()
        ->and($task->fresh()->deleted_at)->toBeNull();

    // Para otra persona (el responsable), no está archivada.
    $task->forceFill(['assignee_user_id' => $this->boss->id])->save();
    expect(collect(app(MySpaceTasks::class)->list($this->boss))->firstWhere('id', $task->id)['archived'])->toBeFalse();

    $this->actingAs($this->me)->delete("/mi-espacio/tareas/{$task->id}/archivar")->assertRedirect();
    expect(TaskArchive::query()->count())->toBe(0);
});

it('un colaborador externo no archiva ni usa las tareas de Mi espacio', function () {
    $collaborator = User::factory()->collaborator()->create();
    $project = Project::factory()->withMembers([$collaborator])->create();
    $task = Task::factory()->assignedTo($collaborator)->create(['project_id' => $project->id]);

    $this->actingAs($collaborator)->post("/mi-espacio/tareas/{$task->id}/archivar")->assertForbidden();
    $this->actingAs($collaborator)->postJson('/mi-espacio/tareas/sugeridas')->assertForbidden();
    $this->actingAs($collaborator)->putJson("/mi-espacio/tareas/{$task->id}/notas", ['notes' => 'x'])->assertForbidden();
});

// --- Notas (F-060) --------------------------------------------------------------------------------

it('las notas guardan la descripción en texto plano, línea a línea', function () {
    $task = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'description' => null]);

    $this->actingAs($this->me)
        ->putJson("/mi-espacio/tareas/{$task->id}/notas", ['notes' => "Llamar a Marta\n\nY <revisar> el copy"])
        ->assertOk()
        ->assertJsonPath('task.notes', "Llamar a Marta\n\nY <revisar> el copy");

    expect($task->fresh()->description)->toBe('<p>Llamar a Marta</p><p></p><p>Y &lt;revisar&gt; el copy</p>');

    $this->actingAs($this->me)->putJson("/mi-espacio/tareas/{$task->id}/notas", ['notes' => ''])->assertOk();
    expect($task->fresh()->description)->toBeNull();
});

it('una descripción con formato no se pisa desde Mi espacio; quien no es del proyecto no la toca', function () {
    $rich = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'description' => '<ul><li>Uno</li></ul>']);
    $this->actingAs($this->me)->putJson("/mi-espacio/tareas/{$rich->id}/notas", ['notes' => 'Nada'])->assertUnprocessable()->assertJsonValidationErrors('notes');
    expect($rich->fresh()->description)->toBe('<ul><li>Uno</li></ul>');

    $foreign = Task::factory()->create(['project_id' => Project::factory()->create()->id]);
    $this->actingAs($this->me)->putJson("/mi-espacio/tareas/{$foreign->id}/notas", ['notes' => 'x'])->assertForbidden();
});

it('dicta las notas de una tarea: el dictado es de la tarea y de quien dicta', function () {
    Storage::fake('local');
    Queue::fake();
    $task = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id]);
    $upload = fn (array $overrides = []) => [
        'context' => 'task_note',
        'task_id' => $task->id,
        'audio' => UploadedFile::fake()->create('nota.webm', 40, 'audio/webm'),
        'duration_ms' => 9_000,
        ...$overrides,
    ];

    $response = $this->actingAs($this->me)->post('/mi-espacio/dictados', $upload(), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('dictation.context', 'task_note')
        ->assertJsonPath('dictation.task_id', $task->id);

    $dictation = Dictation::query()->findOrFail($response->json('dictation.id'));
    expect($dictation->context)->toBe(DictationContext::TaskNote)->and($dictation->weekly_cycle_id)->toBeNull();

    $foreign = Task::factory()->create(['project_id' => Project::factory()->create()->id]);
    $this->actingAs($this->me)->postJson('/mi-espacio/dictados', $upload(['task_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('task_id');

    $rich = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'description' => '<p><em>Formato</em></p>']);
    $this->actingAs($this->me)->postJson('/mi-espacio/dictados', $upload(['task_id' => $rich->id]))->assertUnprocessable()->assertJsonValidationErrors('task_id');
});

it('el dictado de una nota, de punta a punta, devuelve el texto (cola síncrona)', function () {
    Storage::fake('local');
    $this->app->instance(TranscriptionService::class, new FakeTranscriber('Mañana llamo al cliente para cerrar el presupuesto.'));
    $task = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id]);

    $id = $this->actingAs($this->me)->post('/mi-espacio/dictados', [
        'context' => 'task_note', 'task_id' => $task->id,
        'audio' => UploadedFile::fake()->create('nota.webm', 40, 'audio/webm'), 'duration_ms' => 9_000,
    ], ['Accept' => 'application/json'])->assertCreated()->json('dictation.id');

    $this->actingAs($this->me)->getJson("/mi-espacio/dictados/{$id}")
        ->assertOk()
        ->assertJsonPath('dictation.status', 'done')
        ->assertJsonPath('dictation.text', 'Mañana llamo al cliente para cerrar el presupuesto.');
});

// --- Tareas sugeridas por IA (F-062) --------------------------------------------------------------

it('sin ninguna weekly cerrada no se puede pedir', function () {
    $this->closed->delete();

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertUnprocessable()->assertJsonValidationErrors('suggestions');
});

it('pedir sugerencias encola el Job en la cola ai y no crea ninguna tarea', function () {
    Queue::fake();

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')
        ->assertStatus(202)
        ->assertJsonPath('suggestions.state', 'queued')
        ->assertJsonPath('suggestions.cycle.id', $this->closed->id);

    Queue::assertPushedOn('ai', SuggestTasksFromWeekly::class);
    expect(Task::query()->count())->toBe(0);

    // Con una en marcha no se encola otra.
    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertStatus(202);
    Queue::assertPushed(SuggestTasksFromWeekly::class, 1);
});

it('el prompt es el de extract-tasks con los reportes enviados de la última weekly cerrada, y nunca los borradores', function () {
    $llm = FakeLlm::bind()->push([]);
    tasksWeekly($this->closed, $this->boss, [[null, 'Organizar el equipo'], [$this->acme, 'Ana tiene que revisar el banner de Acme']]);
    tasksWeekly($this->closed, userWithRole('employee', ['name' => 'Pablo']), [[$this->acme, 'BORRADOR SECRETO']], submitted: false);

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertStatus(202);

    $llm->assertSent(function (LlmRequest $request) {
        return $request->feature === AiFeature::SuggestedTasks
            && $request->user?->id === $this->me->id
            && $request->subject?->is($this->closed)
            && str_contains($request->prompt, "USUARIO OBJETIVO:\n- id: {$this->me->id}\n- nombre: Ana Díaz")
            && str_contains($request->prompt, "AUTHOR_ID: {$this->boss->id} (Raúl Gestor)\nCONTENT:\nTEXTO GENERAL: Organizar el equipo")
            && str_contains($request->prompt, "[CLIENTE: Acme]\nAna tiene que revisar el banner de Acme")
            && str_contains($request->prompt, '{"id":'.$this->acme->id.',"name":"Acme"}')
            && ! str_contains($request->prompt, 'BORRADOR SECRETO');
    });
    expect(TaskSuggestionBatch::query()->sole()->state)->toBe(WeeklyJobState::Done);
});

it('sin reportes enviados en esa semana no llama a la IA', function () {
    $llm = FakeLlm::bind();

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertStatus(202);

    $llm->assertNothingSent();
    expect(TaskSuggestionBatch::query()->sole()->items)->toBe([]);
});

it('deduplica por cliente y descripción parecida, solo para mí, y sugiere el proyecto y la bolsa del cliente', function () {
    $banks = Project::factory()->hourBank()->withMembers([$this->me])->create(['client_id' => ($beta = Client::factory()->create(['name' => 'Beta']))->id, 'code' => 'BETA-BH1']);
    $bank = HourBank::factory()->create(['project_id' => $banks->id]);
    Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'title' => 'Revisar el banner de la home']);
    tasksWeekly($this->closed, $this->boss, [[$this->acme, 'Ana: revisar el banner; Ana: preparar la reunión']]);

    FakeLlm::bind()->push([
        ['description' => 'revisar el banner', 'assigneeId' => (string) $this->me->id, 'assignerId' => (string) $this->boss->id, 'clientId' => (string) $this->acme->id, 'status' => 'TODO'],
        ['description' => 'Revisar el banner', 'assigneeId' => (string) $this->me->id, 'clientId' => (string) $beta->id],
        ['description' => 'Preparar la reunión', 'assigneeId' => (string) $this->me->id, 'assignerId' => (string) $this->boss->id, 'clientId' => (string) $this->acme->id],
        ['description' => 'Preparar la reunión con Acme', 'assigneeId' => (string) $this->me->id, 'clientId' => (string) $this->acme->id],
        ['description' => 'Tarea de Raúl', 'assigneeId' => (string) $this->boss->id, 'clientId' => (string) $this->acme->id],
        ['description' => 'Algo general', 'assigneeId' => (string) $this->me->id, 'clientId' => null],
        ['description' => 'Cliente inventado', 'assigneeId' => (string) $this->me->id, 'clientId' => '999999'],
    ]);

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertStatus(202);

    $batch = TaskSuggestionBatch::query()->sole();
    $items = collect($batch->items);

    expect($items->pluck('title')->all())->toBe(['Revisar el banner', 'Preparar la reunión', 'Algo general', 'Cliente inventado'])
        ->and($batch->skipped)->toBe(2)
        ->and($items[0]['client_id'])->toBe($beta->id)
        ->and($items[0]['project_id'])->toBe($banks->id)
        ->and($items[0]['hour_bank_id'])->toBe($bank->id)
        ->and($items[1]['project_id'])->toBe($this->web->id)
        ->and($items[1]['hour_bank_id'])->toBeNull()
        ->and($items[1]['author_name'])->toBe('Raúl Gestor')
        ->and($items[2]['client_id'])->toBeNull()
        ->and($items[2]['project_id'])->toBeNull()
        ->and($items[3]['client_id'])->toBeNull()
        ->and(Task::query()->count())->toBe(1);
});

it('la deduplicación es la de App.tsx: mismo cliente y una descripción contiene a la otra', function (string $new, ?string $client, bool $duplicate) {
    $existing = [['title' => 'Revisar el diseño de la home', 'client_id' => 1], ['title' => 'Llamar', 'client_id' => null]];

    expect(TaskSuggestionPrompt::isDuplicate($new, $client === null ? null : (int) $client, $existing))->toBe($duplicate);
})->with([
    'igual' => ['Revisar el diseño de la home', '1', true],
    'mayúsculas y espacios' => ['  REVISAR EL DISEÑO DE LA HOME ', '1', true],
    'contenida' => ['revisar el diseño', '1', true],
    'la contiene' => ['Revisar el diseño de la home y el footer', '1', true],
    'otro cliente' => ['Revisar el diseño de la home', '2', false],
    'distinta' => ['Preparar la factura', '1', false],
    'general' => ['Llamar a Marta', null, true],
    'vacía, con tareas del cliente' => ['', '1', true],
]);

it('si la IA falla, la tanda queda con el error y se puede volver a pedir', function () {
    tasksWeekly($this->closed, $this->boss, [[$this->acme, 'Algo']]);
    FakeLlm::bind()->push(new LlmUnavailable('caída'), []);

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertStatus(202);
    $batch = TaskSuggestionBatch::query()->sole();
    expect($batch->state)->toBe(WeeklyJobState::Failed)->and($batch->error)->not->toBeNull();

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertStatus(202);
    expect($batch->fresh()->state)->toBe(WeeklyJobState::Done)->and($batch->fresh()->error)->toBeNull();
});

it('se crean solo las revisadas, con TaskWriter, para mí y en el proyecto elegido; las demás siguen o se descartan', function () {
    $batch = TaskSuggestionBatch::query()->create([
        'user_id' => $this->me->id, 'weekly_cycle_id' => $this->closed->id, 'state' => WeeklyJobState::Done,
        'items' => [
            ['key' => 'a', 'title' => 'Revisar el banner', 'client_id' => $this->acme->id, 'project_id' => $this->web->id, 'hour_bank_id' => null],
            ['key' => 'b', 'title' => 'Preparar la reunión', 'client_id' => $this->acme->id, 'project_id' => null, 'hour_bank_id' => null],
            ['key' => 'c', 'title' => 'Otra', 'client_id' => null, 'project_id' => null, 'hour_bank_id' => null],
        ],
    ]);

    $this->actingAs($this->me)->post('/mi-espacio/tareas/sugeridas/crear', [
        'tasks' => [['key' => 'a', 'title' => 'Revisar el banner de la home', 'project_id' => $this->web->id, 'priority' => 'high', 'due_date' => '2026-10-09']],
        'dismiss' => ['c'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $task = Task::query()->sole();
    expect($task->title)->toBe('Revisar el banner de la home')
        ->and($task->project_id)->toBe($this->web->id)
        ->and($task->assignee_user_id)->toBe($this->me->id)
        ->and($task->created_by)->toBe($this->me->id)
        ->and($task->priority->value)->toBe('high')
        ->and($task->due_date?->toDateString())->toBe('2026-10-09')
        ->and(array_column($batch->fresh()->items, 'key'))->toBe(['b']);
});

it('sin permiso en el proyecto, sin bolsa en uno de bolsas o con una propuesta que ya no está, no se crea ninguna', function () {
    $banks = Project::factory()->hourBank()->withMembers([$this->me])->create(['client_id' => $this->acme->id]);
    HourBank::factory()->create(['project_id' => $banks->id]);
    $foreign = Project::factory()->create();
    $batch = TaskSuggestionBatch::query()->create([
        'user_id' => $this->me->id, 'weekly_cycle_id' => $this->closed->id, 'state' => WeeklyJobState::Done,
        'items' => [['key' => 'a', 'title' => 'Uno'], ['key' => 'b', 'title' => 'Dos']],
    ]);

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas/crear', ['tasks' => [
        ['key' => 'a', 'title' => 'Uno', 'project_id' => $this->web->id],
        ['key' => 'b', 'title' => 'Dos', 'project_id' => $foreign->id],
    ]])->assertUnprocessable()->assertJsonValidationErrors('tasks.1.project_id');

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas/crear', ['tasks' => [
        ['key' => 'a', 'title' => 'Uno', 'project_id' => $banks->id],
    ]])->assertUnprocessable()->assertJsonValidationErrors('tasks.0.hour_bank_id');

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas/crear', ['tasks' => [
        ['key' => 'zzz', 'title' => 'Inventada', 'project_id' => $this->web->id],
    ]])->assertUnprocessable()->assertJsonValidationErrors('tasks.0.key');

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas/crear', ['tasks' => [
        ['key' => 'a', 'title' => '', 'project_id' => null],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['tasks.0.title', 'tasks.0.project_id']);

    expect(Task::query()->count())->toBe(0)
        ->and(array_column($batch->fresh()->items, 'key'))->toBe(['a', 'b']);
});

it('descartar quita propuestas sueltas o toda la tanda; las de otra persona no se tocan', function () {
    $mine = TaskSuggestionBatch::query()->create(['user_id' => $this->me->id, 'state' => WeeklyJobState::Done, 'items' => [['key' => 'a', 'title' => 'Uno'], ['key' => 'b', 'title' => 'Dos']]]);
    $theirs = TaskSuggestionBatch::query()->create(['user_id' => $this->boss->id, 'state' => WeeklyJobState::Done, 'items' => [['key' => 'a', 'title' => 'Uno']]]);

    $this->actingAs($this->me)->delete('/mi-espacio/tareas/sugeridas', ['keys' => ['a']])->assertRedirect();
    expect(array_column($mine->fresh()->items, 'key'))->toBe(['b']);

    $this->actingAs($this->me)->delete('/mi-espacio/tareas/sugeridas')->assertRedirect();
    expect($mine->fresh()->items)->toBe([])
        ->and(count($theirs->fresh()->items))->toBe(1);
});

it('la tanda de la página: estado, propuestas y semana de origen', function () {
    TaskSuggestionBatch::query()->create([
        'user_id' => $this->me->id, 'weekly_cycle_id' => $this->closed->id, 'state' => WeeklyJobState::Running,
        'items' => [],
    ]);

    $this->actingAs($this->me)->get('/mi-espacio?pestana=tareas')->assertInertia(fn (Assert $page) => $page
        ->where('suggestions.state', 'running')
        ->where('suggestions.stuck', false)
        ->where('suggestions.cycle.number', $this->closed->number));

    $this->travel(13)->minutes();
    $this->actingAs($this->me)->get('/mi-espacio?pestana=tareas')->assertInertia(fn (Assert $page) => $page->where('suggestions.stuck', true));
});

it('la IA de prueba propone una tarea para quien lo pide (GEMINI_DRIVER=fake fuera de los tests)', function () {
    tasksWeekly($this->closed, $this->boss, [[$this->acme, 'Algo']]);
    $this->app->instance(LlmClient::class, FakeLlm::demo());

    $this->actingAs($this->me)->postJson('/mi-espacio/tareas/sugeridas')->assertStatus(202);

    $items = TaskSuggestionBatch::query()->sole()->items;
    expect($items)->toHaveCount(1)->and($items[0]['title'])->toContain('GEMINI_DRIVER=fake');
});
