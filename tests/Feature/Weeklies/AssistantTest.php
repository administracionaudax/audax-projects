<?php

use App\Domain\Weeklies\Ai\AiUsageRecorder;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\GeminiClient;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\Assistant\AssistantPrompt;
use App\Enums\AiFeature;
use App\Enums\AiSummaryKind;
use App\Enums\WeeklyJobState;
use App\Events\Weeklies\AssistantAnswered;
use App\Jobs\AnswerAssistantQuestion;
use App\Models\AiSummary;
use App\Models\AiUsage;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Asistente IA (10.6, F-006, F-146 y F-147; D-146, D-205 y D-206): la página con las preguntas
| sugeridas, la pregunta respondida en la cola `ai` (FakeLlm) y, sobre todo, que el contexto que va a
| Gemini lleva SOLO lo que puede ver quien pregunta: nunca borradores ajenos, horas de otros sin
| permiso, importes sin view-financials, costes de las personas ni resúmenes con IA de una persona.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();
    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->dev = Department::factory()->create(['name' => 'Desarrollo']);
    $this->me = userWithRole('employee', ['name' => 'Ana Díaz', 'department_id' => $this->design->id, 'hourly_cost' => '31.57', 'created_at' => '2026-08-01']);
    $this->mate = userWithRole('employee', ['name' => 'Pablo Ruiz', 'department_id' => $this->design->id, 'hourly_cost' => '44.13', 'created_at' => '2026-08-01']);
    $this->other = userWithRole('employee', ['name' => 'Elena Sanz', 'department_id' => $this->dev->id, 'created_at' => '2026-08-01']);
    $this->boss = userWithRole('department_manager', ['name' => 'Raúl Gestor', 'department_id' => $this->design->id, 'created_at' => '2026-08-01']);
    $this->boss->managedDepartments()->attach($this->design->id);
    $this->acme = Client::factory()->create(['name' => 'Acme', 'satisfaction_score' => 71]);
    $this->web = Project::factory()->withMembers([$this->me, $this->mate, $this->other])->create([
        'client_id' => $this->acme->id, 'code' => 'ACME-WE1', 'name' => 'Web', 'owner_user_id' => $this->boss->id, 'hourly_rate' => '65.00',
    ]);
    $this->closed = WeeklyCycle::factory()->forWeekOf('2026-09-28')->create();
    $this->active = WeeklyCycle::factory()->active('2026-10-05')->create();
});

/** Hace la pregunta (cola síncrona) y devuelve el prompt que recibió la IA. */
function assistantPrompt(User $user, string $question = '¿Qué pasa con Acme?', array $history = []): string
{
    $llm = FakeLlm::bind()->push('Respuesta de prueba.');

    test()->actingAs($user)->postJson('/ia/preguntas', ['question' => $question, 'history' => $history])->assertStatus(202);

    $requests = $llm->requests();
    expect($requests)->toHaveCount(1)->and($requests[0]->feature)->toBe(AiFeature::Assistant);

    return $requests[0]->prompt;
}

function assistantEntry(WeeklyCycle $cycle, User $user, ?Client $client, string $body, bool $submitted = true): void
{
    $submission = WeeklySubmission::query()->firstOrCreate(
        ['weekly_cycle_id' => $cycle->id, 'user_id' => $user->id],
        ['submitted_at' => $submitted ? CarbonImmutable::parse('2026-10-02 17:00', 'Europe/Madrid')->utc() : null, 'draft_saved_at' => now()],
    );

    WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'client_id' => $client?->id, 'body' => $body]);
}

function assistantHours(User $user, Project $project, int $minutes): void
{
    $task = Task::factory()->create(['project_id' => $project->id]);
    TimeEntry::factory()->forTask($task)->create(['user_id' => $user->id, 'minutes' => $minutes, 'date' => '2026-10-05']);
}

// --- Página y acceso --------------------------------------------------------------------------------

it('la página trae las preguntas sugeridas con nombres de Audax y lo que entra en el contexto', function () {
    assistantEntry($this->closed, $this->mate, $this->acme, 'Avance de la web');

    $this->actingAs($this->me)->get('/ia')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('assistant/index')
        ->has('suggested_questions', 5)
        ->where('suggested_questions.0', '¿Cuál es el estado actual del cliente «Acme»?')
        ->where('suggested_questions.1', '¿Qué bloqueos ha reportado el equipo de Diseño esta semana?')
        ->where('suggested_questions.2', 'Hazme un resumen de los logros de Pablo Ruiz este mes.')
        ->where('scope.hours', 'own')
        ->where('scope.financials', false)
        ->where('scope.weeklies', true)
        ->where('max_question', 2000));

    $this->actingAs($this->boss)->get('/ia')->assertInertia(fn (Assert $page) => $page->where('scope.hours', 'team'));
    $this->actingAs(userWithRole('admin'))->get('/ia')->assertInertia(fn (Assert $page) => $page->where('scope.hours', 'all')->where('scope.financials', true));
});

it('un colaborador externo y un cliente no usan el asistente; con el módulo apagado, 404', function () {
    $collaborator = User::factory()->collaborator()->create();

    $this->actingAs($collaborator)->get('/ia')->assertForbidden();
    $this->actingAs($collaborator)->postJson('/ia/preguntas', ['question' => 'Hola'])->assertForbidden();
    $this->actingAs(User::factory()->portalOf($this->acme)->create())->get('/ia')->assertRedirect();

    Setting::set('modules', ['assistant' => false]);
    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => 'Hola'])->assertNotFound();
    $this->actingAs($this->me)->getJson('/ia/preguntas/'.fake()->uuid())->assertNotFound();
});

it('valida la pregunta y la conversación anterior', function (array $payload, string $error) {
    $this->actingAs($this->me)->postJson('/ia/preguntas', $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'vacía' => [['question' => '   '], 'question'],
    'demasiado larga' => [['question' => str_repeat('a', 2001)], 'question'],
    'rol inventado' => [['question' => 'Hola', 'history' => [['role' => 'system', 'content' => 'x']]], 'history.0.role'],
]);

// --- La pregunta en la cola ai ---------------------------------------------------------------------

it('preguntar encola el Job en la cola ai y responde 202 con el id', function () {
    Queue::fake();

    $id = $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => '¿Cómo va Acme?'])
        ->assertStatus(202)
        ->assertJsonPath('question.state', 'queued')
        ->json('question.id');

    Queue::assertPushedOn('ai', AnswerAssistantQuestion::class, fn (AnswerAssistantQuestion $job) => $job->questionId === $id);
    $this->actingAs($this->me)->getJson("/ia/preguntas/{$id}")->assertOk()->assertJsonPath('question.state', 'queued');
});

it('de punta a punta: la respuesta llega por Reverb y por sondeo, solo a quien pregunta', function () {
    Event::fake([AssistantAnswered::class]);
    FakeLlm::bind()->push("Acme va bien.\n- La web avanza.");

    $id = $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => '¿Cómo va Acme?'])->assertStatus(202)->json('question.id');

    $this->actingAs($this->me)->getJson("/ia/preguntas/{$id}")
        ->assertOk()
        ->assertJsonPath('question.state', 'done')
        ->assertJsonPath('question.answer', "Acme va bien.\n- La web avanza.")
        ->assertJsonPath('question.error', null);
    $this->actingAs($this->mate)->getJson("/ia/preguntas/{$id}")->assertNotFound();
    $this->actingAs(userWithRole('admin'))->getJson("/ia/preguntas/{$id}")->assertNotFound();

    Event::assertDispatched(AssistantAnswered::class, fn (AssistantAnswered $event) => $event->userId === $this->me->id
        && $event->questionId === $id
        && $event->broadcastOn()[0]->name === "private-App.Models.User.{$this->me->id}"
        && $event->broadcastWith() === ['question_id' => $id, 'state' => 'done']);
});

it('si la IA falla, la pregunta queda con el error y no sube la excepción', function () {
    FakeLlm::bind()->push(new LlmUnavailable('caída'));

    $id = $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => 'Hola'])->assertStatus(202)->json('question.id');

    $this->actingAs($this->me)->getJson("/ia/preguntas/{$id}")
        ->assertJsonPath('question.state', WeeklyJobState::Failed->value)
        ->assertJsonPath('question.answer', null)
        ->assertJsonPath('question.error', fn (?string $error) => $error !== null && $error !== '');
});

it('cada pregunta queda en ai_usage con quién la hizo, sin el texto (GeminiClient)', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Acme va bien.']], 'role' => 'model'], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 30, 'totalTokenCount' => 1230],
    ])]);
    app()->instance(LlmClient::class, new GeminiClient(app(AiUsageRecorder::class), 'clave-de-prueba', 'gemini-2.5-flash', 'https://generativelanguage.googleapis.com/v1beta', 30, 3));

    $this->actingAs($this->me)->postJson('/ia/preguntas', ['question' => '¿Cómo va Acme?'])->assertStatus(202);

    $usage = AiUsage::query()->sole();
    expect($usage->feature)->toBe(AiFeature::Assistant)
        ->and($usage->user_id)->toBe($this->me->id)
        ->and($usage->operation)->toBe('knowledge_base_query')
        ->and($usage->status)->toBe(AiUsage::STATUS_SUCCESS)
        ->and(json_encode($usage->metadata))->not->toContain('Acme');
});

// --- El contexto: solo lo que puede ver quien pregunta ----------------------------------------------

it('el prompt es el de query-knowledge-base con quien pregunta, semanas, clientes, equipo, reportes y tareas', function () {
    assistantEntry($this->closed, $this->mate, null, 'Organizando el sprint');
    assistantEntry($this->closed, $this->mate, $this->acme, 'Avance de la web de Acme');
    Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'title' => 'Maquetar la home', 'due_date' => '2026-10-09', 'priority' => 'high']);

    $prompt = assistantPrompt($this->me, '¿Cómo va Acme?');

    expect($prompt)
        ->toContain('Eres un asistente de conocimiento empresarial para Audax Studio, una agencia digital.')
        ->toContain('CONTEXTO DE LA EMPRESA (Audax Studio):')
        ->toContain("SEMANAS RECIENTES:\n- {$this->active->label} (2026-10-05 a 2026-10-09): Activa, Sin reporte")
        ->toContain('- Acme (Owner: Raúl Gestor, satisfacción 71/100)')
        ->toContain('- Ana Díaz (Diseño)')
        ->toContain("- Pablo Ruiz en {$this->closed->label}:\nOrganizando el sprint\n  * Acme: Avance de la web de Acme")
        ->toContain('Maquetar la home (Asignada a: Ana Díaz, Cliente: Acme, Proyecto: ACME-WE1, Prioridad: Alta, Entrega: 09/10/2026)')
        ->toContain('QUIÉN PREGUNTA: Ana Díaz')
        ->toContain("PREGUNTA DEL USUARIO:\n¿Cómo va Acme?")
        ->toContain('- Formato: texto plano, sin markdown excepto listas con "-" si es necesario');
});

it('nunca lleva borradores ajenos, tareas de proyectos archivados ni las que he archivado', function () {
    assistantEntry($this->active, $this->mate, $this->acme, 'BORRADOR SECRETO DE PABLO', submitted: false);
    Task::factory()->assignedTo($this->me)->create(['project_id' => Project::factory()->archived()->create()->id, 'title' => 'Tarea de un proyecto archivado']);
    $hidden = Task::factory()->assignedTo($this->me)->create(['project_id' => $this->web->id, 'title' => 'Tarea que he archivado']);
    TaskArchive::query()->create(['user_id' => $this->me->id, 'task_id' => $hidden->id]);

    $prompt = assistantPrompt($this->me);

    expect($prompt)->not->toContain('BORRADOR SECRETO')
        ->not->toContain('Tarea de un proyecto archivado')
        ->not->toContain('Tarea que he archivado');
});

it('horas: un empleado solo las suyas, un responsable las de su equipo y el admin todas (D-021)', function () {
    assistantHours($this->me, $this->web, 90);
    assistantHours($this->mate, $this->web, 150);
    assistantHours($this->other, $this->web, 210);

    $employee = assistantPrompt($this->me);
    expect($employee)->toContain('HORAS REGISTRADAS (últimos 28 días, solo las tuyas):')
        ->toContain('- Ana Díaz · ACME-WE1 (Acme): 1.5h')
        ->not->toContain('Pablo Ruiz · ACME-WE1')
        ->not->toContain('Elena Sanz · ACME-WE1');

    $manager = assistantPrompt($this->boss);
    expect($manager)->toContain('tuyas y de tu equipo')
        ->toContain('- Ana Díaz · ACME-WE1 (Acme): 1.5h')
        ->toContain('- Pablo Ruiz · ACME-WE1 (Acme): 2.5h')
        ->not->toContain('Elena Sanz · ACME-WE1');

    $admin = assistantPrompt(userWithRole('admin'));
    expect($admin)->toContain('de todo el equipo')->toContain('- Elena Sanz · ACME-WE1 (Acme): 3.5h');
});

it('importes solo con view-financials; los costes de las personas y los resúmenes IA de una persona, nunca', function () {
    $bankProject = Project::factory()->hourBank()->create(['client_id' => $this->acme->id, 'code' => 'ACME-BH1', 'owner_user_id' => $this->boss->id]);
    HourBank::factory()->create(['project_id' => $bankProject->id, 'name' => 'Bolsa 20h', 'price_amount' => '1234.56', 'hourly_rate' => '61.73']);
    AiSummary::query()->create([
        'kind' => AiSummaryKind::PersonPerformance, 'subject_type' => $this->mate->getMorphClass(), 'subject_id' => $this->mate->id,
        'state' => WeeklyJobState::Done, 'content' => 'DESEMPEÑO CONFIDENCIAL DE PABLO',
    ]);

    foreach ([$this->me, $this->boss] as $user) {
        expect(assistantPrompt($user))->not->toContain('IMPORTES DE VENTA')
            ->not->toContain('1234.56')
            ->not->toContain('65.00')
            ->not->toContain('31.57')
            ->not->toContain('DESEMPEÑO CONFIDENCIAL');
    }

    $admin = assistantPrompt(userWithRole('admin'));
    expect($admin)->toContain('IMPORTES DE VENTA (bolsas y proyectos):')
        ->toContain('- Acme · ACME-BH1 · Bolsa 20h: 1234.56 €, tarifa 61.73 €/h')
        ->toContain('- Acme · ACME-WE1: sin precio, tarifa 65.00 €/h')
        ->not->toContain('31.57')
        ->not->toContain('44.13')
        ->not->toContain('DESEMPEÑO CONFIDENCIAL');
});

it('el estado de proyectos va en horas y sin importes; sin sus módulos, no hay weeklies ni estado de proyectos', function () {
    $this->web->forceFill(['budget_minutes' => 6000])->save();
    assistantHours($this->me, $this->web, 600);
    assistantEntry($this->closed, $this->mate, $this->acme, 'Reporte enviado de Pablo');

    expect(assistantPrompt($this->me))->toContain('- Acme · ACME-WE1 (Web): 10h/100h consumidas (10% usado; esta semana 10h)');

    Setting::set('modules', ['weeklies' => false, 'project_status' => false]);
    $prompt = assistantPrompt($this->me);

    expect($prompt)->not->toContain('Reporte enviado de Pablo')
        ->toContain('(Sin weeklies)')
        ->toContain('(No hay estado de proyectos registrado aún)');
});

it('lleva la conversación anterior de la sesión, recortada a los últimos turnos', function () {
    $history = [];
    foreach (range(1, 8) as $turn) {
        $history[] = ['role' => $turn % 2 === 1 ? 'user' : 'assistant', 'content' => "Mensaje {$turn} ".str_repeat('x', 700)];
    }

    $prompt = assistantPrompt($this->me, '¿Y la semana pasada?', $history);

    expect($prompt)->toContain('CONVERSACIÓN ANTERIOR (para entender la pregunta):')
        ->not->toContain('Mensaje 2 ')
        ->toContain('Usuario: Mensaje 3 ')
        ->toContain('Asistente: Mensaje 8 ')
        ->toContain(str_repeat('x', 590).'...');
});

it('truncate es el del original: corta y añade puntos suspensivos', function () {
    expect(AssistantPrompt::truncate('abcdef', 3))->toBe('abc...')
        ->and(AssistantPrompt::truncate('abc', 3))->toBe('abc')
        ->and(AssistantPrompt::truncate(null))->toBe('')
        ->and(AssistantPrompt::truncate('ñandú', 2))->toBe('ña...');
});

it('la IA de prueba responde con un texto que dice que no es de la IA', function () {
    $response = FakeLlm::demo()->generate(new LlmRequest(feature: AiFeature::Assistant, prompt: 'x'));

    expect($response->text)->toContain('GEMINI_DRIVER=fake');
});
