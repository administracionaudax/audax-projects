<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Http\Controllers\Chat\Media\MediaPayload;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Rendimiento de C3 (audios, adjuntos, transcripciones y búsqueda) con datos realistas: el
| DatabaseSeeder de desarrollo (DemoDataSeeder) y chats con mensajes, menciones, archivos y audios.
|--------------------------------------------------------------------------
| Para cada página y endpoint, con una empleada (Elena) y el admin:
|   1. las consultas caben en su presupuesto (medido con la caché caliente, más 3),
|   2. ninguna SQL se repite más de 4 veces (síntoma de N+1),
|   3. el número de consultas NO crece al añadir más conversaciones, mensajes, archivos y audios.
| PERF_REPORT=1 php -d memory_limit=1G vendor/bin/pest tests/Feature/Chat/C3PerformanceTest.php
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
    $this->app->instance(TranscriptionService::class, new FakeTranscriber('Revisamos el presupuesto de la campaña con el cliente'));

    $this->admin = User::query()->where('email', 'admin@example.com')->sole();
    $this->elena = User::query()->where('email', 'empleado@example.com')->sole();
    $this->raul = User::query()->where('email', 'responsable@example.com')->sole();
    $this->project = Project::query()->where('code', 'ARR-WEB')->sole();
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->chat = $this->directory->forProject($this->project);
    $this->round = 0;

    $this->wav = function (): UploadedFile {
        $samples = str_repeat("\0\0", 16000);
        $path = (string) tempnam(sys_get_temp_dir(), 'c3perf');
        file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples);

        return new UploadedFile($path, 'nota.wav', null, null, true);
    };

    // Una ronda de datos: el chat del proyecto, un grupo nuevo y la directa con Raúl, con texto,
    // menciones, archivos (PDF e imagen) y audios transcritos que dicen «presupuesto».
    $this->populate = function (): void {
        $this->round++;
        $members = $this->project->members()->pluck('users.id')->all();
        $others = User::query()->whereKey($members)->where('id', '!=', $this->elena->id)->get();
        $group = $this->directory->group($this->elena, 'Grupo '.$this->round, $others->take(2)->modelKeys());
        $direct = $this->directory->direct($this->elena, $this->raul);

        foreach ([$this->chat, $group, $direct] as $conversation) {
            $author = $conversation->is($direct) ? $this->raul : $others->first();

            foreach (range(1, 3) as $i) {
                $this->writer->post($author, $conversation, "Presupuesto {$this->round}.{$i}: <@{$this->elena->id}> revisa la **propuesta**");
            }

            $this->writer->post($this->elena, $conversation, 'Adjunto el presupuesto', files: [
                UploadedFile::fake()->create("presupuesto-{$this->round}.pdf", 20, 'application/pdf'),
                UploadedFile::fake()->image("presupuesto-{$this->round}.png", 300, 200),
            ]);

            foreach (range(1, 2) as $i) {
                $this->writer->post($author, $conversation, null, audio: ($this->wav)(), audioDurationMs: 1000);
            }
        }
    };

    $this->measure = function (User $user, string $url, array $headers = []): array {
        $this->actingAs($user)->get($url, $headers);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($user)->get($url, $headers);
        app('events')->forget(QueryExecuted::class);

        $counts = array_count_values($queries);
        arsort($counts);

        return [
            'status' => $response->getStatusCode(),
            'total' => count($queries),
            'repeats' => $counts === [] ? 0 : (int) reset($counts),
            'repeated' => Str::limit((string) array_key_first($counts), 160),
        ];
    };

    $this->pages = function (User $user): array {
        $audios = Message::query()->where('type', 'audio')->orderByDesc('id')->limit(20)->pluck('id')->implode(',');
        $audio = Attachment::query()->where('attachable_type', Message::class)->where('mime', 'like', 'audio/%')
            ->whereIn('attachable_id', Message::query()->select('id')->where('conversation_id', $this->chat->id))
            ->orderBy('id')->firstOrFail();
        $json = ['Accept' => 'application/json'];

        return [
            'chat.search' => ['/chat/buscar?q=presupuesto', []],
            'chat.search.json' => ['/chat/buscar?q=presupuesto', $json],
            'chat.search.conversation' => ["/chat/buscar?q=presupuesto&conversacion={$this->chat->id}", []],
            'chat.search.audios' => ['/chat/buscar?q=presupuesto&tipo=audios', []],
            'search' => ['/buscar?q=presupuesto', $json],
            'chat.media.transcriptions' => ["/chat/transcripciones?mensajes={$audios}", $json],
            'chat.media.audio' => [MediaPayload::audioUrl($audio), []],
            ...($user->isAdmin() ? ['admin.transcriptions.index' => ['/admin/transcripciones', []]] : []),
        ];
    };
});

it('las páginas y endpoints de C3 caben en su presupuesto de consultas y no crecen con los datos', function () {
    // Presupuesto: lo medido con la caché caliente (el máximo de los dos roles) más 3. Incluye las
    // props compartidas de las páginas Inertia.
    $budgets = [
        'chat.search' => 8,
        'chat.search.json' => 5,
        'chat.search.conversation' => 11,
        'chat.search.audios' => 7,
        'search' => 10,
        'chat.media.transcriptions' => 7,
        'chat.media.audio' => 7,
        'admin.transcriptions.index' => 12,
    ];

    ($this->populate)();
    $first = [];

    foreach ([$this->elena, $this->admin] as $user) {
        foreach (($this->pages)($user) as $label => [$url, $headers]) {
            $result = ($this->measure)($user, $url, $headers);
            $first[$user->email][$label] = $result;

            if (getenv('PERF_REPORT')) {
                fwrite(STDERR, sprintf("%-28s %-26s %3d  %3d q (máx. %d rep.)\n", $user->email, $label, $result['status'], $result['total'], $result['repeats']));
            }

            expect($result['status'])->toBe(200, "{$label} ({$user->email})")
                ->and($result['total'])->toBeLessThanOrEqual($budgets[$label], "{$label} ({$user->email}): {$result['total']} consultas")
                ->and($result['repeats'])->toBeLessThanOrEqual(4, "{$label} ({$user->email}) repite: {$result['repeated']}");
        }
    }

    ($this->populate)();
    ($this->populate)();

    foreach ([$this->elena, $this->admin] as $user) {
        foreach (($this->pages)($user) as $label => [$url, $headers]) {
            $result = ($this->measure)($user, $url, $headers);

            expect($result['total'])->toBeLessThanOrEqual($first[$user->email][$label]['total'], "{$label} ({$user->email}) crece con los datos: {$first[$user->email][$label]['total']} → {$result['total']}");
        }
    }
});
