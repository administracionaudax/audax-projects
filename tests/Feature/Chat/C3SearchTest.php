<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Search\Sources\MessageSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Búsqueda del chat (SPEC §3 y §12, aceptación de la F6): mensajes, nombres de archivos y
| transcripciones SOLO de las conversaciones que se pueden ver (D-071). Una palabra dicha en un
| audio aparece para quien ve la conversación y no para los demás.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->app->instance(TranscriptionService::class, new FakeTranscriber('Mañana revisamos el presupuesto de la bodega con el cliente'));

    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->outsider = User::factory()->employee()->create(['name' => 'Olga']);
    $this->project = Project::factory()->create(['name' => 'Web corporativa', 'code' => 'ARR-WEB']);
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->chat = $this->directory->forProject($this->project);
    $this->direct = $this->directory->direct($this->ana, $this->luis);
    $this->group = $this->directory->group($this->ana, 'Diseño', [$this->luis->id]);

    $this->audio = function ($conversation, ?User $author = null): Message {
        $samples = str_repeat("\0\0", 16000);
        $path = (string) tempnam(sys_get_temp_dir(), 'c3wav');
        file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples);

        return $this->writer->post($author ?? $this->ana, $conversation, null, audio: new UploadedFile($path, 'nota.wav', null, null, true), audioDurationMs: 1000);
    };
    $this->chatSearch = fn (User $user, string $query) => $this->actingAs($user)->getJson('/chat/buscar?'.$query);
    $this->ids = fn (User $user, string $query): array => collect(($this->chatSearch)($user, $query)->assertOk()->json('results'))->pluck('id')->all();
    $this->globalIds = fn (User $user, string $q): array => collect($this->actingAs($user)->getJson('/buscar?q='.urlencode($q))->assertOk()->json('results'))
        ->where('type', 'message')->pluck('id')->all();
});

it('una palabra dicha en un audio aparece para quien ve la conversación y no para los demás', function () {
    $audio = ($this->audio)($this->chat);

    expect(($this->ids)($this->luis, 'q=presupuesto'))->toBe([$audio->id])
        ->and(($this->globalIds)($this->luis, 'presupuesto'))->toBe([$audio->id])
        ->and(($this->ids)($this->outsider, 'q=presupuesto'))->toBe([])
        ->and(($this->globalIds)($this->outsider, 'presupuesto'))->toBe([]);

    ($this->chatSearch)($this->luis, 'q=presupuesto')
        ->assertJsonPath('results.0.match', 'transcription')
        ->assertJsonPath('results.0.is_audio', true)
        ->assertJsonPath('results.0.url', "/chat/{$this->chat->id}?mensaje={$audio->id}")
        ->assertJsonPath('results.0.conversation', ['type' => 'project', 'label' => 'Web corporativa', 'code' => 'ARR-WEB'])
        ->assertJsonPath('results.0.author', 'Ana');
});

it('en la búsqueda global, el resultado lleva al mensaje con su conversación y su autor', function () {
    $audio = ($this->audio)($this->chat);

    $result = collect($this->actingAs($this->luis)->getJson('/buscar?q=presupuesto')->json('results'))->firstWhere('type', 'message');

    expect($result['id'])->toBe($audio->id)
        ->and($result['url'])->toBe("/chat/{$this->chat->id}?mensaje={$audio->id}")
        ->and($result['title'])->toContain('presupuesto')
        ->and($result['subtitle'])->toStartWith('Audio · Web corporativa · Ana · ');
});

it('busca en el texto de los mensajes y en los nombres de los archivos', function () {
    $text = $this->writer->post($this->ana, $this->chat, 'El logotipo definitivo va en **negrita**');
    $file = $this->writer->post($this->luis, $this->chat, null, files: [UploadedFile::fake()->create('Logotipo-final.pdf', 10, 'application/pdf')]);

    ($this->chatSearch)($this->ana, 'q=logotipo')
        ->assertJsonPath('results.0.id', $file->id)
        ->assertJsonPath('results.0.match', 'file')
        ->assertJsonPath('results.0.file_name', 'Logotipo-final.pdf')
        ->assertJsonPath('results.0.excerpt', 'Logotipo-final.pdf')
        ->assertJsonPath('results.1.id', $text->id)
        ->assertJsonPath('results.1.match', 'message')
        ->assertJsonPath('results.1.excerpt', 'El logotipo definitivo va en negrita');
});

it('el admin encuentra en los chats de proyecto y de grupo, pero nunca en las directas ajenas', function () {
    $inProject = $this->writer->post($this->ana, $this->chat, 'Reunión de arranque');
    $inGroup = $this->writer->post($this->ana, $this->group, 'Reunión de diseño');
    $inDirect = $this->writer->post($this->ana, $this->direct, 'Reunión privada');

    expect(($this->ids)($this->admin, 'q=reuni'))->toBe([$inGroup->id, $inProject->id])
        ->and(($this->ids)($this->luis, 'q=reuni'))->toBe([$inDirect->id, $inGroup->id, $inProject->id])
        ->and(($this->ids)($this->outsider, 'q=reuni'))->toBe([]);
});

it('en una directa, la conversación se llama como la otra persona', function () {
    $this->writer->post($this->ana, $this->direct, 'Te llamo luego');

    ($this->chatSearch)($this->luis, 'q=llamo')->assertJsonPath('results.0.conversation.label', 'Ana');
    ($this->chatSearch)($this->ana, 'q=llamo')->assertJsonPath('results.0.conversation.label', 'Luis');
});

it('quien sale del proyecto deja de encontrar lo de su chat', function () {
    $this->writer->post($this->ana, $this->chat, 'Entrega el viernes');

    expect(($this->ids)($this->luis, 'q=viernes'))->toHaveCount(1);

    $this->project->members()->detach($this->luis->id);

    expect(($this->ids)($this->luis, 'q=viernes'))->toBe([]);
});

it('no encuentra mensajes borrados ni ocultos, ni el texto de sus audios', function () {
    $deleted = ($this->audio)($this->chat);
    $hidden = $this->writer->post($this->ana, $this->chat, 'Presupuesto confidencial');
    $this->writer->delete($this->ana, $deleted);
    $this->writer->setHidden($this->admin, $hidden, true);

    expect(($this->ids)($this->luis, 'q=presupuesto'))->toBe([])
        ->and(($this->ids)($this->admin, 'q=presupuesto'))->toBe([])
        ->and(($this->globalIds)($this->luis, 'presupuesto'))->toBe([]);
});

it('filtra por tipo de coincidencia', function () {
    $audio = ($this->audio)($this->chat);
    $text = $this->writer->post($this->ana, $this->chat, 'El presupuesto está aprobado');
    $file = $this->writer->post($this->ana, $this->chat, null, files: [UploadedFile::fake()->create('presupuesto.pdf', 5, 'application/pdf')]);

    expect(($this->ids)($this->luis, 'q=presupuesto'))->toBe([$file->id, $text->id, $audio->id])
        ->and(($this->ids)($this->luis, 'q=presupuesto&tipo=mensajes'))->toBe([$text->id])
        ->and(($this->ids)($this->luis, 'q=presupuesto&tipo=archivos'))->toBe([$file->id])
        ->and(($this->ids)($this->luis, 'q=presupuesto&tipo=audios'))->toBe([$audio->id]);

    ($this->chatSearch)($this->luis, 'q=presupuesto&tipo=otros')->assertUnprocessable();
});

it('se puede limitar a una conversación, solo si se puede ver', function () {
    $inProject = $this->writer->post($this->ana, $this->chat, 'Hola equipo');
    $this->writer->post($this->ana, $this->group, 'Hola grupo');

    expect(($this->ids)($this->luis, "q=hola&conversacion={$this->chat->id}"))->toBe([$inProject->id]);

    $this->actingAs($this->outsider)->get("/chat/buscar?q=hola&conversacion={$this->chat->id}")->assertForbidden();
    $this->actingAs($this->admin)->get("/chat/buscar?q=hola&conversacion={$this->direct->id}")->assertForbidden();
    $this->actingAs($this->luis)->get('/chat/buscar?q=hola&conversacion=999999')->assertNotFound();
});

it('pinta la página con los resultados y sigue la lista con «antes»', function () {
    $messages = collect(range(1, 23))->map(fn (int $i) => $this->writer->post($this->ana, $this->chat, "Tarea {$i} pendiente de revisión"));

    $this->actingAs($this->luis)
        ->get('/chat/buscar?q=revisi')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('chat/buscar')
            ->where('query', 'revisi')
            ->where('filters', ['type' => null, 'conversation' => null])
            ->where('conversation', null)
            ->where('minLength', 2)
            ->has('results', 20)
            ->where('results.0.id', $messages->last()->id)
            ->where('next', $messages[3]->id));

    $more = ($this->chatSearch)($this->luis, 'q=revisi&antes='.$messages[3]->id)->assertOk();

    expect(collect($more->json('results'))->pluck('id')->all())->toBe([$messages[2]->id, $messages[1]->id, $messages[0]->id])
        ->and($more->json('next'))->toBeNull();
});

it('sin consulta (o con menos de 2 caracteres) no busca', function (string $query) {
    $this->writer->post($this->ana, $this->chat, 'a');

    $this->actingAs($this->ana)
        ->get('/chat/buscar'.$query)
        ->assertInertia(fn (Assert $page) => $page->where('results', [])->where('next', null));
})->with(['', '?q=', '?q=a', '?q=%20a%20']);

it('en la conversación filtrada, su nombre llega a la página', function () {
    $this->actingAs($this->ana)
        ->get("/chat/buscar?q=hola&conversacion={$this->direct->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation', ['id' => $this->direct->id, 'type' => 'direct', 'label' => 'Luis']));
});

it('las menciones salen con el nombre en el fragmento', function () {
    $this->writer->post($this->ana, $this->chat, "Pregúntale a <@{$this->luis->id}> por el contrato");

    ($this->chatSearch)($this->ana, 'q=contrato')->assertJsonPath('results.0.excerpt', 'Pregúntale a @Luis por el contrato');
});

it('/chat/buscar es la búsqueda del chat (ninguna otra ruta la tapa)', function () {
    expect(Route::getRoutes()->match(request()->create('/chat/buscar'))->getName())->toBe('chat.search')
        ->and(route('chat.search', absolute: false))->toBe('/chat/buscar');
});

it('los caracteres de LIKE se buscan como texto', function () {
    $this->writer->post($this->ana, $this->chat, 'Descuento del 100% aplicado');
    $this->writer->post($this->ana, $this->chat, 'Descuento del 1000 aplicado');

    expect(($this->ids)($this->ana, 'q='.urlencode('100%')))->toHaveCount(1)
        ->and(($this->ids)($this->ana, 'q='.urlencode('_')))->toBe([]);
});

it('en PostgreSQL la búsqueda ignora las tildes (extensión unaccent)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql' || ! DB::table('pg_extension')->where('extname', 'unaccent')->exists()) {
        $this->markTestSkipped('Solo con PostgreSQL y la extensión unaccent (servidor y CI).');
    }

    $message = $this->writer->post($this->ana, $this->chat, 'Revisamos la ferretería mañana');

    expect(($this->ids)($this->luis, 'q=ferreteria'))->toBe([$message->id])
        ->and(($this->ids)($this->luis, 'q=MANANA'))->toBe([$message->id]);
});

it('la visibilidad en bloque de las conversaciones coincide con ConversationPolicy::view', function () {
    $left = $this->directory->group($this->ana, 'Antiguo', [$this->luis->id]);
    $this->directory->leave($left, $this->luis->id);
    $conversations = [$this->chat, $this->direct, $this->group, $left];
    $ids = array_map(fn ($conversation) => $conversation->id, $conversations);
    $client = User::factory()->client()->create();
    $inactive = User::factory()->employee()->create(['is_active' => false]);

    foreach ([$this->admin, $this->ana, $this->luis, $this->outsider, $client, $inactive] as $user) {
        $expected = array_values(array_map(
            fn ($conversation) => $conversation->id,
            array_filter($conversations, fn ($conversation) => $user->can('view', $conversation)),
        ));

        expect(MessageSource::visibleConversationIds($user, $ids))->toEqualCanonicalizing($expected);
    }
});

it('recorta el fragmento alrededor de la palabra, sin tildes ni mayúsculas y sin cortar palabras', function () {
    $text = 'Primero repasamos el calendario completo del proyecto y luego hablamos del presupuesto de la campaña de otoño que se presenta al cliente el lunes por la mañana en su oficina';

    $excerpt = MessageSource::excerpt($text, 'CAMPANA', 30);

    expect($excerpt)->toStartWith('…')
        ->and($excerpt)->toEndWith('…')
        ->and($excerpt)->toContain('campaña de otoño')
        ->and(mb_strlen($excerpt))->toBeLessThan(90)
        ->and(MessageSource::excerpt('Texto corto', 'nada', 30))->toBe('Texto corto')
        ->and(MessageSource::contains('Reunión en Cádiz', 'cadiz'))->toBeTrue()
        ->and(MessageSource::contains('Reunión en Cádiz', 'Sevilla'))->toBeFalse();
});
