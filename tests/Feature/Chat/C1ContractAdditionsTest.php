<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Events\Chat\MessageUpdated;
use App\Http\Resources\Chat\MessageHtml;
use App\Http\Resources\Chat\MessagePreview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

/*
| Añadidos de C1 al contrato del chat (aditivos): ConversationDirectory::mute, unreadCounts y
| unreadTotal; MessageWriter::setLinkPreview (sin evento si no cambia) y linkTask (en
| C1TaskFromMessageTest). Y el texto de los mensajes fuera del chat: vista previa en texto plano
| y HTML saneado para la descripción de una tarea.
*/

beforeEach(function () {
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->dm = $this->directory->direct($this->ana, $this->luis);
});

it('silencia solo a quien participa, y solo guarda si cambia', function () {
    $participant = $this->directory->mute($this->ana, $this->dm, true);
    expect($participant->muted)->toBeTrue()
        ->and($this->directory->mute($this->ana, $this->dm, true)->updated_at?->equalTo($participant->updated_at))->toBeTrue();

    expect(fn () => $this->directory->mute(User::factory()->employee()->create(), $this->dm, true))->toThrow(AuthorizationException::class);
});

it('cuenta los no leídos por conversación, de las pedidas o de todas, en una consulta', function () {
    $project = Project::factory()->create();
    $project->addMember($this->ana);
    $chat = $this->directory->forProject($project);
    $this->writer->post($this->luis, $this->dm, 'Uno');
    $this->writer->post($this->luis, $this->dm, 'Dos');
    $this->writer->system($chat, 'milestone.completed', ['task_id' => 1, 'task' => 'Hito']);

    expect($this->directory->unreadCounts($this->ana))->toBe([$this->dm->id => 2, $chat->id => 1])
        ->and($this->directory->unreadCounts($this->ana, [$chat->id]))->toBe([$chat->id => 1])
        ->and($this->directory->unreadCounts($this->ana, []))->toBe([])
        ->and($this->directory->unreadCounts($this->luis))->toBe([])
        ->and($this->directory->unreadTotal($this->ana))->toBe(3);
});

it('guarda la previsualización solo si cambia (y entonces avisa)', function () {
    $message = $this->writer->post($this->ana, $this->dm, 'https://audaxstudio.com');
    $preview = ['url' => 'https://audaxstudio.com', 'title' => 'Audax', 'description' => null, 'domain' => 'audaxstudio.com'];
    Event::fake([MessageUpdated::class]);

    $this->writer->setLinkPreview($message, $preview);
    $this->writer->setLinkPreview($message->fresh(), $preview);
    $this->writer->setLinkPreview($message->fresh(), null);

    Event::assertDispatchedTimes(MessageUpdated::class, 2);
    expect($message->fresh()->link_preview)->toBeNull();
});

it('la vista previa en texto plano quita el markdown, resuelve menciones y recorta', function () {
    $users = [$this->ana->id => $this->ana];

    expect(MessagePreview::plain("**Hola** <@{$this->ana->id}>, _mira_ [esto](https://a.es) y `código`\n\nfin", $users))
        ->toBe('Hola @Ana, mira esto y código fin')
        ->and(MessagePreview::plain('snake_case_y 2*3*4 se quedan', []))->toBe('snake_case_y 2*3*4 se quedan')
        ->and(MessagePreview::plain('<@999999> ya no existe', []))->toBe('@persona ya no existe')
        ->and(MessagePreview::plain(str_repeat('a', 200), [], 10))->toBe('aaaaaaaaa…')
        ->and(MessagePreview::plain(null, []))->toBe('');
});

it('el HTML de la descripción escapa todo y solo convierte el formato ligero', function () {
    $html = MessageHtml::from("**Negrita** y *cursiva* <b onclick=x>no</b>\n`<script>` [web](https://audaxstudio.com) [mal](javascript:alert(1))\n\nhttps://a.es/x_y_z.", [$this->ana->id => $this->ana]);

    expect($html)->toBe('<p><strong>Negrita</strong> y <em>cursiva</em> &lt;b onclick=x&gt;no&lt;/b&gt;<br><code>&lt;script&gt;</code> <a href="https://audaxstudio.com">web</a> [mal](javascript:alert(1))</p>'
        .'<p><a href="https://a.es/x_y_z">https://a.es/x_y_z</a>.</p>');
});
