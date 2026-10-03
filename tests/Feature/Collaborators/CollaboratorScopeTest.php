<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Models\ActiveTimer;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission as PermissionModel;

/*
| Alcance de un colaborador externo en las rutas que sí tiene (D-134): solo ve los proyectos de los
| que es miembro, sus tareas, sus adjuntos y su chat; imputa solo en ellos (nunca en internos) y
| solo ve sus horas. "Hoy" es el viernes 25/09/2026 en Madrid.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->sara = User::factory()->collaborator()->create(['name' => 'Sara Colaboradora']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana Plantilla']);

    // Suyo (es miembro) y ajeno (no lo es); y el interno de la agencia.
    $this->own = Project::factory()->withMembers([$this->sara, $this->ana])->create(['name' => 'Proyecto Faro', 'code' => 'FARO']);
    $this->foreign = Project::factory()->withMembers([$this->ana])->create(['name' => 'Proyecto Niebla', 'code' => 'NIEBLA']);
    $this->internal = Project::factory()->internal()->withMembers([$this->ana])->create(['name' => 'Proyecto Interno', 'code' => 'INTERNO']);

    $this->ownTask = Task::factory()->create(['project_id' => $this->own->id, 'title' => 'Tarea Faro', 'assignee_user_id' => $this->sara->id, 'due_date' => '2026-09-25']);
    $this->foreignTask = Task::factory()->create(['project_id' => $this->foreign->id, 'title' => 'Tarea Niebla', 'assignee_user_id' => $this->sara->id, 'due_date' => '2026-09-25']);
    $this->internalTask = Task::factory()->create(['project_id' => $this->internal->id, 'title' => 'Tarea Interna']);

    $this->props = fn (string $uri, ?User $user = null): array => $this->actingAs($user ?? $this->sara)->get($uri)->assertOk()->viewData('page')['props'];
});

it('solo ve sus proyectos en el listado, sin consumo de bolsas ni clientes ajenos', function () {
    $props = ($this->props)('/proyectos');

    expect(collect($props['projects']['data'])->pluck('id')->all())->toBe([$this->own->id])
        ->and($props['projects']['data'][0]['hour_banks'])->toBeNull()
        ->and(collect($props['options']['clients'])->pluck('id')->all())->toBe([$this->own->client_id])
        ->and($props['options']['departments'])->toBe([]);
});

it('no abre un proyecto ajeno ni sus pestañas, tareas o adjuntos', function () {
    $this->actingAs($this->sara)->get("/proyectos/{$this->own->id}")->assertOk();
    $this->actingAs($this->sara)->get("/proyectos/{$this->own->id}/tareas")->assertOk();

    foreach (['', '/tareas', '/archivos', '/chat', '/gantt'] as $tab) {
        $this->actingAs($this->sara)->get("/proyectos/{$this->foreign->id}{$tab}")->assertForbidden();
    }

    $this->actingAs($this->sara)->get("/tareas/{$this->foreignTask->id}")->assertForbidden();
    $this->actingAs($this->sara)->post("/tareas/{$this->foreignTask->id}/comentarios", ['body' => '<p>Hola</p>'])->assertForbidden();
    $this->actingAs($this->sara)->patch("/tareas/{$this->foreignTask->id}", ['title' => 'Cambio'])->assertForbidden();

    $attachment = Attachment::factory()->create([
        'attachable_type' => (new Task)->getMorphClass(),
        'attachable_id' => $this->foreignTask->id,
        'project_id' => $this->foreign->id,
        'user_id' => $this->ana->id,
    ]);
    $url = URL::temporarySignedRoute('attachments.show', now()->addHour(), ['attachment' => $attachment->id], absolute: false);

    $this->actingAs($this->sara)->get($url)->assertForbidden();
    expect(Gate::forUser($this->ana)->allows('view', $attachment))->toBeTrue();
});

it('ve las tareas de su proyecto como un miembro: las abre, las edita y las comenta', function () {
    $this->actingAs($this->sara)->get("/tareas/{$this->ownTask->id}")->assertRedirect();
    $this->actingAs($this->sara)->patch("/tareas/{$this->ownTask->id}", ['title' => 'Tarea Faro revisada'])->assertSessionHasNoErrors();

    expect($this->ownTask->fresh()->title)->toBe('Tarea Faro revisada');
});

it('en Inicio y Mis tareas solo aparecen las tareas de sus proyectos y las tarjetas que le afectan', function () {
    $this->actingAs($this->sara)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('home')
        ->missing('indicators')
        ->missing('absences')
        ->missing('workload')
        ->where('auth.user.is_collaborator', true));

    $home = ($this->props)('/');
    $titles = collect([...$home['tasks']['overdue'], ...$home['tasks']['today'], ...$home['tasks']['week']])->pluck('title')->all();
    expect($titles)->toBe(['Tarea Faro']);

    $tasks = ($this->props)('/mis-tareas')['tasks'];
    expect(collect($tasks)->pluck('title')->all())->toBe(['Tarea Faro']);
});

it('la navegación compartida le quita clientes, bolsas, carga, ausencias, informes y datos económicos', function () {
    // Aunque alguien le diera el permiso por error, nunca ve datos económicos.
    PermissionModel::findOrCreate('view-financials', 'web');
    $this->sara->givePermissionTo('view-financials');

    $this->actingAs($this->sara)->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.viewClients', false)
        ->where('auth.can.viewHourBanks', false)
        ->where('auth.can.viewWorkload', false)
        ->where('auth.can.viewAbsences', false)
        ->where('auth.can.viewReports', false)
        ->where('auth.can.viewFinancials', false)
        ->where('auth.can.approveTime', false)
        ->where('auth.can.createProjects', false));

    $this->actingAs($this->ana)->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.is_collaborator', false)
        ->where('auth.can.viewClients', true)
        ->where('auth.can.viewWorkload', true)
        ->where('auth.can.viewAbsences', true)
        ->where('auth.can.viewReports', true));

    foreach (['view-financials', 'view-hour-banks', 'approve-time', 'lock-time', 'manage-users', 'manage-settings'] as $ability) {
        expect(Gate::forUser($this->sara)->allows($ability))->toBeFalse();
    }
});

it('la búsqueda global solo le devuelve sus proyectos, sus tareas y sus secciones; ni clientes ni personas', function () {
    $results = collect($this->actingAs($this->sara)->getJson('/buscar?q=proyecto')->assertOk()->json('results'));

    expect($results->where('type', 'project')->pluck('id')->all())->toBe([$this->own->id])
        ->and($results->whereIn('type', ['client', 'person'])->all())->toBe([]);

    $tasks = collect($this->actingAs($this->sara)->getJson('/buscar?q=tarea')->json('results'))->where('type', 'task');
    expect($tasks->pluck('id')->all())->toBe([$this->ownTask->id]);

    expect(collect($this->actingAs($this->sara)->getJson('/buscar?q=ana')->json('results'))->where('type', 'person')->all())->toBe([])
        ->and(collect($this->actingAs($this->sara)->getJson('/buscar?q=clientes')->json('results'))->pluck('url')->all())->not->toContain('/clientes')
        ->and(collect($this->actingAs($this->sara)->getJson('/buscar?q=informes')->json('results'))->pluck('url')->all())->not->toContain('/informes');
});

it('imputa en las tareas de sus proyectos, pero nunca en uno ajeno ni en el interno', function () {
    $payload = fn (Task $task): array => ['task_id' => $task->id, 'date' => '2026-09-24', 'minutes' => 60];

    $this->actingAs($this->sara)->post('/horas/entradas', $payload($this->ownTask))->assertSessionHasNoErrors();
    // Las tareas que no ve ni las encuentra.
    $this->actingAs($this->sara)->post('/horas/entradas', $payload($this->foreignTask))->assertForbidden();
    $this->actingAs($this->sara)->post('/horas/entradas', $payload($this->internalTask))->assertForbidden();

    // Aunque alguien le añada al proyecto interno, ahí no imputa.
    $this->internal->addMember($this->sara);
    $this->actingAs($this->sara)->post('/horas/entradas', $payload($this->internalTask))
        ->assertSessionHasErrors(['task_id' => __('time.errors.collaborator_internal')]);

    expect(TimeEntry::query()->pluck('task_id')->all())->toBe([$this->ownTask->id]);

    // Tampoco lo imputa por él un admin en el proyecto interno.
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/horas/entradas', [...$payload($this->internalTask), 'user_id' => $this->sara->id])->assertSessionHasErrors('task_id');
});

it('el temporizador solo arranca en las tareas de sus proyectos', function () {
    $this->actingAs($this->sara)->post('/temporizador', ['task_id' => $this->internalTask->id])->assertForbidden();
    $this->actingAs($this->sara)->post('/temporizador', ['task_id' => $this->foreignTask->id])->assertForbidden();

    $this->internal->addMember($this->sara);
    $this->actingAs($this->sara)->post('/temporizador', ['task_id' => $this->internalTask->id])
        ->assertSessionHasErrors(['task_id' => __('time.errors.collaborator_internal')]);
    expect(ActiveTimer::query()->count())->toBe(0);

    $this->actingAs($this->sara)->post('/temporizador', ['task_id' => $this->ownTask->id])->assertSessionHasNoErrors();
    expect(ActiveTimer::query()->sole()->task_id)->toBe($this->ownTask->id);
});

it('el buscador de imputación no le ofrece tareas ajenas ni del proyecto interno, y solo imputa para sí', function () {
    $ids = collect($this->actingAs($this->sara)->getJson('/horas/tareas?q=tarea')->assertOk()->json('tasks'))->pluck('id')->all();
    $suggested = collect($this->actingAs($this->sara)->getJson('/horas/tareas')->json('tasks'))->pluck('id')->all();

    expect($ids)->toBe([$this->ownTask->id])
        ->and($suggested)->toBe([$this->ownTask->id])
        ->and(collect($this->actingAs($this->sara)->getJson('/horas/opciones')->json('people'))->pluck('id')->all())->toBe([$this->sara->id]);

    $this->actingAs($this->sara)->getJson("/horas/tareas?user_id={$this->ana->id}")->assertForbidden();
});

it('solo ve sus horas: ni la hoja de otra persona ni las de otros en sus tareas', function () {
    TimeEntry::factory()->forTask($this->ownTask)->on('2026-09-24')->minutes(120)->create(['user_id' => $this->ana->id]);
    TimeEntry::factory()->forTask($this->ownTask)->on('2026-09-24')->minutes(30)->create(['user_id' => $this->sara->id]);

    $this->actingAs($this->sara)->get("/horas?persona={$this->ana->id}")->assertForbidden();
    $this->actingAs($this->sara)->get("/proyectos/{$this->own->id}/horas")->assertForbidden();

    expect(TimeEntry::query()->visibleTo($this->sara)->sum('minutes'))->toBe(30)
        ->and(($this->props)('/horas')['people'])->toBe([]);
});

it('solo ve el chat de sus proyectos: nada de conversaciones ajenas, directas ni grupos', function () {
    $directory = app(ConversationDirectory::class);
    $ownChat = $directory->forProject($this->own);
    $foreignChat = $directory->forProject($this->foreign);

    $this->actingAs($this->sara)->get("/chat/{$ownChat->id}")->assertOk();
    $this->actingAs($this->sara)->get("/chat/{$foreignChat->id}")->assertForbidden();
    $this->actingAs($this->sara)->getJson("/chat/{$foreignChat->id}/mensajes")->assertForbidden();

    // Nadie le abre una directa ni le mete en un grupo.
    $this->actingAs($this->ana)->post('/chat/directas', ['user_id' => $this->sara->id])->assertSessionHasErrors('user_id');
    $this->actingAs($this->ana)->post('/chat/grupos', ['name' => 'Café', 'user_ids' => [$this->sara->id, User::factory()->employee()->create()->id]]);
    $group = Conversation::query()->where('type', 'group')->first();
    expect($group?->hasParticipant($this->sara) ?? false)->toBeFalse();

    // Ni en la lista de personas del chat de los demás.
    expect(collect($this->actingAs($this->ana)->getJson('/chat/personas')->json('people'))->pluck('id')->all())->not->toContain($this->sara->id);

    // Si ya estaba en una directa (antes de ser colaborador), deja de verla.
    $direct = $directory->direct($this->ana, User::factory()->employee()->create());
    $directory->join($direct, $this->sara->id);
    $this->actingAs($this->sara)->get("/chat/{$direct->id}")->assertForbidden();

    $listed = collect($this->actingAs($this->sara)->getJson('/chat/conversaciones')->assertOk()->json('conversations'))->pluck('id')->all();
    expect($listed)->toBe([$ownChat->id]);
});

it('solo menciona a miembros de su proyecto, y nadie le menciona fuera de los suyos', function () {
    $outsider = User::factory()->employee()->create();
    $mention = fn (User $user): string => sprintf('<span data-type="mention" data-id="%d" data-label="%s">@%s</span>', $user->id, e($user->name), e($user->name));

    $this->actingAs($this->sara)
        ->post("/tareas/{$this->ownTask->id}/comentarios", ['body' => '<p>'.$mention($this->ana).' '.$mention($outsider).'</p>'])
        ->assertSessionHasNoErrors();
    expect(TaskComment::query()->sole()->mentioned_user_ids)->toBe([$this->ana->id]);

    $this->actingAs($this->ana)
        ->post("/tareas/{$this->foreignTask->id}/comentarios", ['body' => '<p>'.$mention($this->sara).'</p>'])
        ->assertSessionHasNoErrors();
    expect(TaskComment::query()->where('task_id', $this->foreignTask->id)->sole()->mentioned_user_ids)->toBe([]);
});

it('como responsable de una tarea solo elige miembros, y solo es responsable en sus proyectos', function () {
    $outsider = User::factory()->employee()->create();

    $this->actingAs($this->sara)->patch("/tareas/{$this->ownTask->id}", ['assignee_user_id' => $outsider->id])->assertSessionHasErrors('assignee_user_id');
    $this->actingAs($this->sara)->patch("/tareas/{$this->ownTask->id}", ['assignee_user_id' => $this->ana->id])->assertSessionHasNoErrors();

    $foreignOther = Task::factory()->create(['project_id' => $this->foreign->id]);
    $this->actingAs($this->ana)->patch("/tareas/{$foreignOther->id}", ['assignee_user_id' => $this->sara->id])->assertSessionHasErrors('assignee_user_id');

    $users = collect(($this->props)("/proyectos/{$this->own->id}/tareas")['users'])->pluck('id')->sort()->values()->all();
    expect($users)->toBe(collect([$this->sara->id, $this->ana->id, $this->own->owner_user_id])->sort()->values()->all());
});
