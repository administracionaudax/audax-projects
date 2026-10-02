<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Http\Resources\Chat\HomeChatSummary;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Inicio, tarjeta «Menciones» (SPEC §5.1 y §12): las menciones recientes a quien mira y sus
| conversaciones con mensajes sin leer, en una prop diferida (no retrasa la primera pintura).
*/

beforeEach(function () {
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana Pérez']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis Gil']);
    $this->eva = User::factory()->employee()->create(['name' => 'Eva Sanz']);
    $this->project = Project::factory()->create(['name' => 'Web corporativa', 'code' => 'ARR-WEB']);
    foreach ([$this->ana, $this->luis, $this->eva] as $user) {
        $this->project->addMember($user);
    }
    $this->chat = $this->directory->forProject($this->project);
    $this->dm = $this->directory->direct($this->ana, $this->luis);
});

it('la tarjeta llega en una prop diferida', function () {
    $this->actingAs($this->ana)->get('/')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home', false)
            ->missing('chat_summary')
            ->has('chat.unread')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('chat_summary.unread_total', 0)
                ->where('chat_summary.conversations', [])
                ->where('chat_summary.mentions', [])));
});

it('enseña las menciones recientes (personales y @todos) y las conversaciones sin leer', function () {
    // No cuentan: las propias, las de otra persona, las ocultadas y las de hace más de 14 días.
    $this->writer->post($this->ana, $this->chat, "<@{$this->luis->id}> listo");
    $personal = $this->writer->post($this->luis, $this->chat, "**Revisa** la maqueta, <@{$this->ana->id}>");
    $everyone = $this->writer->post($this->eva, $this->chat, '@todos reunión a las 12');
    $this->writer->post($this->luis, $this->dm, '¿Lo ves?');
    $this->writer->post($this->luis, $this->chat, "<@{$this->eva->id}> tú también");
    $hidden = $this->writer->post($this->luis, $this->chat, "<@{$this->ana->id}> oculto");
    $this->writer->setHidden(User::factory()->admin()->create(), $hidden, true);
    $old = $this->writer->post($this->luis, $this->chat, "<@{$this->ana->id}> antiguo");
    DB::table('messages')->where('id', $old->id)->update(['created_at' => now()->subDays(20)]);

    $summary = app(HomeChatSummary::class)->for($this->ana);

    expect($summary['unread_total'])->toBe(5)
        ->and($summary['conversations'])->toBe([
            ['id' => $this->chat->id, 'type' => 'project', 'title' => 'Web corporativa', 'unread' => 4, 'url' => "/chat/{$this->chat->id}"],
            ['id' => $this->dm->id, 'type' => 'direct', 'title' => 'Luis Gil', 'unread' => 1, 'url' => "/chat/{$this->dm->id}"],
        ])
        ->and(array_column($summary['mentions'], 'id'))->toBe([$everyone->id, $personal->id])
        ->and($summary['mentions'][0])->toMatchArray(['author' => 'Eva Sanz', 'conversation' => 'Web corporativa', 'everyone' => true, 'unread' => true])
        ->and($summary['mentions'][1])->toMatchArray([
            'author' => 'Luis Gil',
            'excerpt' => 'Revisa la maqueta, @Ana Pérez',
            'everyone' => false,
            'url' => "/chat/{$this->chat->id}?mensaje={$personal->id}",
        ]);

    // Tras leer, siguen las menciones (ya leídas) y desaparecen los no leídos.
    $this->writer->markRead($this->ana, $this->chat, $old->id);
    $summary = app(HomeChatSummary::class)->for($this->ana);

    expect($summary['conversations'])->toHaveCount(1)
        ->and($summary['mentions'][0]['unread'])->toBeFalse();
});

it('no enseña lo de las conversaciones silenciadas en los no leídos ni lo de las que ya dejó', function () {
    $this->writer->post($this->luis, $this->dm, 'Uno');
    $this->directory->mute($this->ana, $this->dm, true);
    $this->writer->post($this->luis, $this->chat, "<@{$this->ana->id}> mira");
    $this->directory->leave($this->chat, $this->ana->id);

    $summary = app(HomeChatSummary::class)->for($this->ana);

    expect($summary['unread_total'])->toBe(0)
        ->and($summary['conversations'])->toBe([])
        ->and($summary['mentions'])->toBe([]);
});

it('cabe en pocas consultas aunque haya muchas menciones y conversaciones', function () {
    foreach (range(1, 12) as $i) {
        $other = User::factory()->employee()->create();
        $this->writer->post($other, $this->directory->direct($other, $this->ana), "<@{$this->ana->id}> hola {$i}");
    }

    DB::enableQueryLog();
    $summary = app(HomeChatSummary::class)->for($this->ana);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($summary['conversations'])->toHaveCount(HomeChatSummary::CONVERSATIONS)
        ->and($summary['mentions'])->toHaveCount(HomeChatSummary::MENTIONS)
        ->and($queries)->toBeLessThanOrEqual(7);
});
