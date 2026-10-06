<?php

use App\Domain\Import\ClickUp\Chat\ChatImporter;
use App\Domain\Import\ClickUp\Chat\ChatPeople;
use App\Domain\Import\ClickUp\Chat\ChatText;
use App\Domain\Import\ClickUp\ImportReport;
use App\Domain\Import\ClickUp\PeopleFile;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Events\Chat\MessagePosted;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\ImportRef;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\MessageReaction;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Importación del chat de ClickUp (D-274 a D-279) con el volcado INVENTADO de
| tests/fixtures/clickup-chat: un canal general (Daily, con hilo, menciones, reacciones y
| adjuntos), el de una carpeta (cliente Naranjas), el de una lista con proyecto, el de una lista
| de Audax Interno (Marketing), uno privado (Estratégico), uno vacío, uno sin correspondencia, uno
| excluido en clasificacion.json, un directo, un directo «contigo mismo» y un grupo.
| Las personas: Ana (admin, dueña del token), Luis, Rosa (antigua, desactivada), Amparo
| (colaboradora), Beto (sin pareja → «Usuario de ClickUp») y la cuenta antigua de Luis (por nombre).
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->fixture = base_path('tests/fixtures/clickup-chat');
    $this->ana = User::factory()->admin()->create(['name' => 'Ana Pérez', 'email' => 'ana@empresa.es']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis Gil', 'email' => 'luis@empresa.es']);
    $this->rosa = User::factory()->employee()->inactive()->create(['name' => 'Rosa Antigua', 'email' => 'rosa@antiguos.empresa.invalid']);
    $this->amparo = User::factory()->collaborator()->create(['name' => 'Amparo Colaboradora', 'email' => 'amparo@gmail.com']);
    $this->eva = User::factory()->employee()->create(['name' => 'Eva', 'email' => 'eva@empresa.es']);

    // Lo que dejó app:import-clickup: la carpeta del cliente y las dos listas.
    $this->client = Client::factory()->create(['name' => 'Naranjas', 'icon' => '🍊']);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Web']);
    $this->project->addMember($this->luis);
    $this->internal = Project::factory()->internal()->create(['name' => 'Marketing']);
    foreach ([['folder', 'f-1', 'client', $this->client->id], ['list', 'l-1', 'project', $this->project->id], ['list', 'l-2', 'project', $this->internal->id]] as [$kind, $external, $type, $id]) {
        ImportRef::query()->create(['source' => 'clickup', 'kind' => $kind, 'external_id' => $external, 'local_type' => $type, 'local_id' => $id]);
    }

    $this->import = fn (bool $dryRun = false, bool $onlyDirect = false): ImportReport => app(ChatImporter::class)
        ->run($this->fixture, PeopleFile::load($this->fixture.'/personas.json'), $dryRun, $onlyDirect);
    $this->conversation = fn (string $channel): Conversation => Conversation::query()->findOrFail(
        ImportRef::query()->where(['source' => 'clickup', 'kind' => 'chat_channel', 'external_id' => $channel])->value('local_id'),
    );
    $this->message = fn (string $external): Message => Message::withTrashed()->findOrFail(
        ImportRef::query()->where(['source' => 'clickup', 'kind' => 'chat_message', 'external_id' => $external])->value('local_id'),
    );
});

it('lleva cada canal a su sitio y deja fuera los vacíos, los excluidos y las notas personales', function () {
    Event::fake([MessagePosted::class]);

    $report = ($this->import)();

    expect($report->get('team', ImportReport::CREATED))->toBe(3)
        ->and($report->get('client', ImportReport::CREATED))->toBe(1)
        ->and($report->get('project', ImportReport::CREATED))->toBe(1)
        ->and($report->get('group', ImportReport::CREATED))->toBe(2)
        ->and($report->get('direct', ImportReport::CREATED))->toBe(1)
        ->and($report->get('team', ImportReport::SKIPPED))->toBe(1)
        ->and(array_keys($report->warnings()))->toContain('Mensajes directos contigo mismo (notas): no se importan.')
        ->and(collect(array_keys($report->warnings()))->contains(fn (string $warning): bool => str_contains($warning, 'Leads')))->toBeTrue();

    $daily = ($this->conversation)('c-daily');
    expect($daily->type)->toBe(ConversationType::Team)
        ->and($daily->name)->toBe('Daily')
        ->and($daily->icon)->toBe('🔹');

    expect(($this->conversation)('c-client')->client_id)->toBe($this->client->id)
        ->and(($this->conversation)('c-list')->project_id)->toBe($this->project->id)
        // Una lista de Audax Interno (sin cliente) es un canal de equipo.
        ->and(($this->conversation)('c-internal')->type)->toBe(ConversationType::Team)
        ->and(($this->conversation)('c-internal')->name)->toBe('Marketing')
        // Un canal privado no se abre a la plantilla: es un grupo con sus miembros.
        ->and(($this->conversation)('c-private')->type)->toBe(ConversationType::Group)
        ->and(($this->conversation)('gdm-1')->name)->toBe('Ana, Luis y Rosa')
        ->and(($this->conversation)('dm-1')->direct_key)->toBe(Conversation::directKey($this->ana->id, $this->luis->id));

    expect(ImportRef::query()->where('kind', 'chat_channel')->whereIn('external_id', ['c-empty', 'c-skipme', 'dm-self'])->exists())->toBeFalse()
        ->and(Message::query()->where('body', 'No me importes')->exists())->toBeFalse();

    // Sin avisos, sin tiempo real y con una sola entrada de auditoría.
    Notification::assertNothingSent();
    Event::assertNotDispatched(MessagePosted::class);
    expect(Activity::query()->where('event', ChatImporter::AUDIT_EVENT)->count())->toBe(1);
});

it('respeta fechas, autores, hilos, menciones, reacciones, adjuntos y el texto enriquecido', function () {
    $report = ($this->import)();

    $plan = ($this->message)('m-3');
    expect($plan->user_id)->toBe($this->ana->id)
        ->and($plan->created_at?->equalTo(CarbonImmutable::createFromTimestampMs(1788253200000 + 30 * 60000)))->toBeTrue()
        ->and($plan->edited_at)->not->toBeNull()
        ->and($plan->body)->toBe("**Plan del día**\n☑ **Audax:** Facturas\n☐ Revisar --- web\n• Uno\n\nPara <@{$this->luis->id}> y @todos")
        ->and(MessageMention::query()->where('message_id', $plan->id)->whereNotNull('user_id')->pluck('user_id')->all())->toBe([$this->luis->id])
        ->and(MessageMention::query()->where('message_id', $plan->id)->where('everyone', true)->exists())->toBeTrue();

    // La respuesta, como respuesta (hilo) a su mensaje.
    expect(($this->message)('m-3r')->parent_id)->toBe($plan->id)
        ->and($report->get('replies', ImportReport::CREATED))->toBe(1);

    // Autores: la antigua (desactivada), «Usuario de ClickUp» y la cuenta antigua por su nombre.
    $placeholder = User::query()->where('email', ChatPeople::PLACEHOLDER_EMAIL)->sole();
    expect(($this->message)('m-2')->user_id)->toBe($this->rosa->id)
        ->and(($this->message)('m-4')->user_id)->toBe($placeholder->id)
        ->and($placeholder->is_active)->toBeFalse()
        ->and($placeholder->name)->toBe('Usuario de ClickUp')
        ->and(($this->message)('i-2')->user_id)->toBe($this->luis->id);

    // La tarjeta de Loom con el texto en varias líneas queda como la URL; el título del post, en negrita.
    expect(($this->message)('m-1')->body)->toBe('Buenos días https://www.loom.com/share/abc')
        ->and(($this->message)('m-5')->body)->toBe("**Semana 36**\n\nResumen de la semana");

    // Reacciones con emoji del selector; las que el chat no tiene, en los avisos.
    expect(MessageReaction::query()->where('message_id', ($this->message)('m-1')->id)->orderBy('emoji')->pluck('emoji')->all())->toBe(['❤️', '👍'])
        ->and(MessageReaction::query()->where('message_id', ($this->message)('m-3r')->id)->value('emoji'))->toBe('🎉')
        ->and(array_keys($report->warnings()))->toContain('Reacciones que el chat no tiene: «unicornio_inventado».');

    // La imagen descargada es un adjunto del mensaje; el vídeo queda como enlace.
    $photo = ($this->message)('m-2');
    $attachment = Attachment::query()->where('attachable_type', $photo->getMorphClass())->where('attachable_id', $photo->id)->sole();
    expect($attachment->original_name)->toBe('foto.png')
        ->and($attachment->mime)->toBe('image/png')
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue()
        ->and($photo->body)->toBe("Mirad la foto y el vídeo\n📎 [video.mov](https://t1.p.clickup-attachments.com/t1/bb/video.mov)");
});

it('deja como participantes a quien toca y todo lo importado como leído', function () {
    ($this->import)();

    $active = fn (string $channel): array => ConversationParticipant::query()
        ->where('conversation_id', ($this->conversation)($channel)->id)->whereNull('left_at')->orderBy('user_id')->pluck('user_id')->all();

    // Canal de equipo: toda la plantilla activa (también los gestores de los proyectos de la
    // factoría) y la colaboradora que era miembro en ClickUp.
    $staff = User::query()->active()->internal()->withoutCollaborators()->pluck('id')->push($this->amparo->id)->sort()->values()->all();
    expect($active('c-daily'))->toBe($staff)
        ->and($staff)->toContain($this->eva->id)
        // Canal privado → grupo: solo sus miembros.
        ->and($active('c-private'))->toBe([$this->ana->id, $this->luis->id])
        // Grupo: la antigua queda como antigua (con su histórico).
        ->and($active('gdm-1'))->toBe([$this->ana->id, $this->luis->id]);

    foreach ([$this->ana, $this->luis] as $person) {
        $this->actingAs($person)->getJson('/chat/conversaciones')->assertOk()
            ->assertJsonPath('conversations.*.unread', fn (array $unread): bool => array_sum($unread) === 0);
    }

    // La colaboradora ve el canal de equipo (era miembro) y el de su cliente no (no tiene proyectos suyos).
    $this->actingAs($this->amparo)->get('/chat/'.($this->conversation)('c-daily')->id)->assertOk();
    $this->actingAs($this->amparo)->get('/chat/'.($this->conversation)('c-client')->id)->assertForbidden();

    $daily = ($this->conversation)('c-daily');
    expect($daily->last_message_at?->equalTo(CarbonImmutable::createFromTimestampMs(1788253200000 + 50 * 60000)))->toBeTrue();
});

it('es idempotente: una segunda ejecución no duplica nada', function () {
    ($this->import)();
    $counts = [Conversation::query()->count(), Message::query()->count(), MessageReaction::query()->count(), MessageMention::query()->count(), Attachment::query()->count()];

    $report = ($this->import)();

    expect([Conversation::query()->count(), Message::query()->count(), MessageReaction::query()->count(), MessageMention::query()->count(), Attachment::query()->count()])->toBe($counts)
        ->and($report->get('messages', ImportReport::CREATED))->toBe(0)
        ->and($report->get('messages', ImportReport::UNCHANGED))->toBeGreaterThan(0)
        ->and($report->get('team', ImportReport::UNCHANGED))->toBe(3);
});

it('la simulación no guarda nada ni copia ficheros', function () {
    $report = ($this->import)(dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->get('messages', ImportReport::CREATED))->toBeGreaterThan(5)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(User::query()->where('email', ChatPeople::PLACEHOLDER_EMAIL)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Activity::query()->where('event', ChatImporter::AUDIT_EVENT)->exists())->toBeFalse();
});

it('con --solo-directos trae solo los directos y los grupos (la opción para el resto del equipo)', function () {
    $report = ($this->import)(onlyDirect: true);

    expect($report->get('direct', ImportReport::CREATED))->toBe(1)
        ->and($report->get('group', ImportReport::CREATED))->toBe(1)
        ->and($report->get('team', ImportReport::CREATED))->toBe(0)
        ->and(Conversation::query()->whereIn('type', [ConversationType::Team, ConversationType::Client, ConversationType::Project])->exists())->toBeFalse();
});

it('el comando muestra el informe y falla sin fichero de personas', function () {
    $this->artisan('app:import-clickup-chat', ['ruta' => $this->fixture, '--dry-run' => true])
        ->expectsOutputToContain('Simulación de la importación del chat de ClickUp')
        ->expectsOutputToContain('Canales de equipo')
        ->assertSuccessful();

    $this->artisan('app:import-clickup-chat', ['ruta' => $this->fixture, '--personas' => '/no/existe.json'])
        ->assertFailed();
});

it('pasa el texto de ClickUp al formato del chat', function () {
    $text = ChatText::convert(
        "# Título\n* [ ] tarea\n[@Ana](#user_mention#1) [@Nadie](#user_mention#9) @here\n[tarea](#task_mention#abc) y [web](https://audaxstudio.com)",
        fn (string $id): ?int => $id === '1' ? 42 : null,
        fn (string $id): ?string => $id === '9' ? 'Nadie Conocido' : null,
    );

    expect($text['body'])->toBe("**Título**\n☐ tarea\n<@42> @Nadie Conocido @todos\ntarea y [web](https://audaxstudio.com)")
        ->and($text['mentions'])->toBe([42])
        ->and($text['everyone'])->toBeTrue()
        ->and($text['attachments'])->toBe([]);

    expect(mb_strlen(ChatText::convert(str_repeat('a', 12_000), fn () => null, fn () => null)['body']))->toBe(10_000);
});

it('no importa sin el volcado', function () {
    expect(fn () => app(ChatImporter::class)->run('/no/existe', PeopleFile::load($this->fixture.'/personas.json')))
        ->toThrow(RuntimeException::class);
});

it('el texto del mensaje importado sale como texto (no de sistema)', function () {
    ($this->import)();

    expect(Message::query()->where('type', MessageType::System)->exists())->toBeFalse();
});
