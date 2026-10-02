<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Models\Attachment;
use App\Models\AudioTranscription;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Rendimiento del chat (C1) con los datos de ejemplo (DemoDataSeeder) y conversaciones de verdad:
| mensajes con menciones, hilos, reacciones, fijados, adjuntos, audios transcritos, tareas y
| previsualizaciones, directas y grupos.
|--------------------------------------------------------------------------
| 1. Cada página y endpoint cabe en su presupuesto de consultas (DB::listen, con la caché caliente)
|    y ninguna consulta se repite por fila.
| 2. El número de consultas NO crece con los datos: más conversaciones, mensajes, reacciones,
|    adjuntos y menciones dan las mismas consultas (lo que detecta un N+1 aunque haya pocas filas).
| CHAT_PERF_REPORT=1 imprime la tabla de consultas.
*/

const CHAT_PERF_MAX_REPEATS = 3;

/**
 * Presupuestos: lo medido con los datos de ejemplo (el máximo de empleada y admin) más 3.
 * Incluyen las consultas de la sesión, del usuario y de las props compartidas.
 *
 * @var array<string, int>
 */
const CHAT_PERF_BUDGETS = [
    'chat.index' => 14,
    'chat.show' => 32,
    'chat.show.focus' => 34,
    'chat.conversations' => 11,
    'chat.people' => 5,
    'chat.messages.older' => 17,
    'chat.messages.poll' => 21,
    'chat.messages.show' => 15,
    'chat.pinned' => 7,
    'chat.messages.task' => 13,
    'projects.chat' => 29,
];

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->writer = app(MessageWriter::class);
    $this->directory = app(ConversationDirectory::class);
    $this->elena = User::query()->where('email', 'empleado@example.com')->sole();
    $this->admin = User::query()->where('email', 'admin@example.com')->sole();
    $this->project = Project::query()->where('code', 'ARR-WEB')->sole();
    $this->chat = $this->directory->forProject($this->project);
    $this->members = $this->project->members()->where('users.id', '!=', $this->elena->id)->get();

    // Más conversaciones, mensajes y relaciones de todo tipo (se puede llamar varias veces).
    $this->grow = function (int $round): void {
        $people = $this->members->values();
        $previous = null;

        foreach (range(1, 40) as $i) {
            $author = $i % 3 === 0 ? $this->elena : $people[$i % $people->count()];
            $body = $i % 5 === 0
                ? "Ronda {$round}, mensaje {$i} para <@{$this->elena->id}> y <@{$people[0]->id}>: **ojo** con https://audaxstudio.com"
                : "Ronda {$round}, mensaje {$i}";
            $message = $this->writer->post($author, $this->chat, $body, $i % 7 === 0 ? $previous?->id : null);

            if ($i % 3 === 0) {
                foreach ($people->take(2) as $reactor) {
                    $this->writer->toggleReaction($reactor, $message, $i % 2 === 0 ? '👍' : '🎉');
                }
            }

            if ($i % 10 === 0) {
                $this->writer->setPinned($people[0], $message, true);
            }

            if ($i % 6 === 0) {
                $this->writer->setLinkPreview($message, ['url' => 'https://audaxstudio.com', 'title' => 'Audax', 'description' => null, 'domain' => 'audaxstudio.com']);
            }

            if ($i % 8 === 0) {
                Attachment::factory()->create([
                    'attachable_type' => $message->getMorphClass(), 'attachable_id' => $message->id,
                    'project_id' => $this->project->id, 'user_id' => $author->id,
                    'mime' => 'image/png', 'original_name' => "captura-{$i}.png", 'path' => 'attachments/x/'.Str::uuid().'.png',
                ]);
            }

            if ($i % 9 === 0) {
                $task = Task::factory()->create(['project_id' => $this->project->id]);
                $message->forceFill(['task_id' => $task->id])->save();
            }

            $previous = $message;
        }

        // Un audio con su transcripción y un aviso del sistema.
        $audio = Message::query()->create(['conversation_id' => $this->chat->id, 'user_id' => $people[1]->id, 'type' => 'audio']);
        $file = Attachment::factory()->create([
            'attachable_type' => $audio->getMorphClass(), 'attachable_id' => $audio->id, 'project_id' => $this->project->id,
            'mime' => 'audio/webm', 'original_name' => 'nota.webm', 'path' => 'attachments/x/'.Str::uuid().'.webm',
        ]);
        AudioTranscription::query()->create(['message_id' => $audio->id, 'attachment_id' => $file->id, 'status' => 'done', 'text' => 'Hola equipo']);
        $this->writer->system($this->chat, 'hour_bank.threshold', ['bank_id' => 1, 'bank' => 'Bolsa', 'threshold' => 90]);

        // Directas y un grupo nuevos, con mensajes sin leer.
        foreach (range(1, 3) as $j) {
            $person = User::factory()->employee()->create(['name' => "Persona {$round}.{$j}"]);
            $direct = $this->directory->direct($this->elena, $person);
            foreach (range(1, 4) as $k) {
                $this->writer->post($k % 2 ? $person : $this->elena, $direct, "Directo {$k} con <@{$this->elena->id}>");
            }
        }

        $group = $this->directory->group($people[0], "Grupo {$round}", [$this->elena->id, $people[1]->id, $people[2]->id]);
        foreach (range(1, 4) as $k) {
            $this->writer->post($people[$k % 3], $group, "Grupo {$k}");
        }

        // Un proyecto nuevo con Elena y su chat.
        $project = Project::factory()->create(['owner_user_id' => $people[0]->id]);
        $project->addMember($this->elena);
        $this->writer->post($people[0], $this->directory->forProject($project), 'Arrancamos');
    };

    $this->pages = function (): array {
        $messages = Message::query()->where('conversation_id', $this->chat->id)->orderBy('id')->pluck('id');
        $first = (int) $messages->first();
        $last = (int) $messages->last();
        $middle = (int) $messages[intdiv($messages->count(), 2)];
        $text = (int) Message::query()->where('conversation_id', $this->chat->id)->where('type', 'text')->whereNull('task_id')->latest('id')->value('id');
        $c = $this->chat->id;
        $since = now()->subMinute()->toIso8601ZuluString();

        return [
            'chat.index' => ['/chat', false],
            'chat.show' => ["/chat/{$c}", false],
            'chat.show.focus' => ["/chat/{$c}?mensaje={$middle}", false],
            'chat.conversations' => ['/chat/conversaciones', true],
            'chat.people' => ['/chat/personas', true],
            'chat.messages.older' => ["/chat/{$c}/mensajes?antes={$last}", true],
            'chat.messages.poll' => ["/chat/{$c}/novedades?despues={$middle}&desde={$first}&cambios={$since}", true],
            'chat.messages.show' => ["/chat/mensajes/{$middle}", true],
            'chat.pinned' => ["/chat/{$c}/fijados", true],
            'chat.messages.task' => ["/chat/mensajes/{$text}/tarea", true],
            'projects.chat' => ["/proyectos/{$this->project->id}/chat", false],
        ];
    };

    $this->measure = function (User $user): array {
        $results = [];

        foreach (($this->pages)() as $label => [$url, $json]) {
            $user->refresh();
            // La primera petición calienta las cachés (ajustes, permisos), como en producción.
            $json ? $this->actingAs($user)->getJson($url) : $this->actingAs($user)->get($url);

            $queries = [];
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                $queries[] = $query->sql;
            });
            $response = $json ? $this->actingAs($user)->getJson($url) : $this->actingAs($user)->get($url);
            app('events')->forget(QueryExecuted::class);

            $counts = array_count_values($queries);
            arsort($counts);
            $results[$label] = [
                'status' => $response->getStatusCode(),
                'total' => count($queries),
                'repeats' => $counts === [] ? 0 : (int) reset($counts),
                'repeated' => Str::limit((string) array_key_first($counts), 200),
                'shapes' => array_count_values(array_map(fn (string $sql): string => (string) preg_replace('/in \([\d?, ]+\)/', 'in (…)', $sql), $queries)),
            ];
        }

        if (getenv('CHAT_PERF_REPORT')) {
            fwrite(STDERR, "\n=== {$user->email}\n".implode("\n", array_map(fn (string $label, array $r): string => sprintf('%-24s %3d %3d q (máx. %d rep.)', $label, $r['status'], $r['total'], $r['repeats']), array_keys($results), $results))."\n");
        }

        return $results;
    };
});

it('cada página y endpoint del chat cabe en su presupuesto de consultas, sin repetir consultas por fila', function () {
    ($this->grow)(1);
    $problems = [];

    foreach (['empleada' => $this->elena, 'admin' => $this->admin] as $who => $user) {
        foreach (($this->measure)($user) as $label => $result) {
            if ($result['status'] !== 200) {
                $problems[] = "{$who} {$label}: estado {$result['status']}";
            }

            if ($result['total'] > CHAT_PERF_BUDGETS[$label]) {
                $problems[] = "{$who} {$label}: {$result['total']} consultas (presupuesto ".CHAT_PERF_BUDGETS[$label].')';
            }

            if ($result['repeats'] > CHAT_PERF_MAX_REPEATS) {
                $problems[] = "{$who} {$label}: la misma consulta {$result['repeats']} veces: {$result['repeated']}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('el número de consultas del chat no crece con los datos (sin N+1)', function () {
    ($this->grow)(1);
    $before = ($this->measure)($this->elena);

    ($this->grow)(2);
    ($this->grow)(3);
    $after = ($this->measure)($this->elena);

    $grew = [];
    foreach ($before as $label => $result) {
        $now = $after[$label];

        if ($now['total'] > $result['total']) {
            $more = [];
            foreach ($now['shapes'] as $shape => $count) {
                if ($count > ($result['shapes'][$shape] ?? 0)) {
                    $more[] = ($result['shapes'][$shape] ?? 0)." → {$count}: ".Str::limit($shape, 200);
                }
            }

            $grew[] = "{$label}: {$result['total']} → {$now['total']}; crecen: ".implode(' | ', $more);
        }
    }

    expect($grew)->toBe([])
        ->and(Conversation::query()->count())->toBeGreaterThanOrEqual(16);
});
