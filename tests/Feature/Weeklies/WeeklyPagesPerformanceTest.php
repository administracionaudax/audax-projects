<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AiUsage;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\HelpFaqSection;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpTutorial;
use App\Models\HelpUpdateLike;
use App\Models\Project;
use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use App\Models\SuggestionComment;
use App\Models\SuggestionCommentReaction;
use App\Models\SuggestionPost;
use App\Models\SuggestionStatusEvent;
use App\Models\SuggestionVote;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Presupuesto de consultas de las páginas de la Weekly (10.2, D-046): no crecen con la plantilla ni
| con el histórico (sin N+1).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->active = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->manager = userWithRole('department_manager', ['created_at' => '2026-08-01 08:00:00']);

    $this->grow = function (int $people): void {
        $closed = WeeklyCycle::factory()->create();

        foreach (range(1, $people) as $i) {
            $user = userWithRole('employee', ['created_at' => '2026-08-01 08:00:00']);
            $client = Client::factory()->create();
            Project::factory()->withMembers([$user, $this->manager])->create(['client_id' => $client->id]);
            $submission = WeeklySubmission::factory()->submitted('2026-10-06 10:00:00')->create(['weekly_cycle_id' => $this->active->id, 'user_id' => $user->id]);
            WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'client_id' => $client->id]);
            WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $user->id]);

            if ($i % 3 === 0) {
                WeeklyExemption::factory()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $user->id]);
            }

            WeeklyReminderLog::query()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $user->id, 'template' => 'manual', 'channel' => 'email', 'trigger_key' => "manual:{$closed->id}", 'status' => 'sent', 'sent_by' => $this->manager->id]);
        }
    };

    $this->measure = function (string $uri): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->manager)->get($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
});

it('/weeklies, /mi-espacio y mi weekly no crecen con la plantilla ni con el histórico', function (string $uri, int $budget) {
    ($this->grow)(3);
    $uri = str_replace('{active}', (string) $this->active->id, $uri);
    ($this->measure)($uri); // En caliente: permisos, ajustes y semana activa ya en caché.
    $small = ($this->measure)($uri);

    ($this->grow)(12);
    $large = ($this->measure)($uri);

    expect($large)->toBeLessThanOrEqual($budget)
        ->and($large - $small)->toBeLessThanOrEqual(1);
})->with([
    'resumen e histórico' => ['/weeklies', 32],
    'mis weeklies' => ['/mi-espacio', 20],
    'mi weekly' => ['/mi-espacio?semana={active}', 34],
    // 10.5: reglas, plantillas, pendientes y el registro (paginado, con su semana y quién lo envió).
    'avisos de la weekly' => ['/weeklies/avisos', 24],
]);

it('la tarjeta de Inicio de quien gestiona no crece con la plantilla', function () {
    ($this->grow)(3);
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'home', 'X-Inertia-Partial-Data' => 'weekly', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request())];

    $measure = function () use ($headers): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->manager)->get('/', $headers)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $small = $measure();
    ($this->grow)(12);

    expect($measure() - $small)->toBeLessThanOrEqual(1);
});

it('un colaborador externo cuenta como ninguno en el estado del equipo', function () {
    User::factory()->collaborator()->count(3)->create(['created_at' => '2026-08-01 08:00:00']);

    $this->actingAs($this->manager)->get('/weeklies')->assertInertia(fn ($page) => $page->where('active.team.counts.expected', 1));
});

it('el informe de la semana no crece con los clientes, los reportes ni las secciones del audio (10.3)', function () {
    $withReport = function (): void {
        $clients = Client::query()->orderBy('id')->get();
        $this->active->forceFill(['report' => [
            'global_summary' => 'Resumen',
            'team_risks' => [],
            'client_updates' => $clients->map(fn (Client $client): array => ['client_id' => $client->id, 'client_name' => $client->name, 'status' => 'on_track', 'executive_summary' => 'x'])->all(),
        ], 'audio_disk' => 'local', 'audio_path' => 'weeklies/audio.mp3'])->save();
        $this->active->audioSections()->delete();
        foreach ($clients as $position => $client) {
            $this->active->audioSections()->create(['key' => "client-{$client->id}", 'kind' => 'client', 'client_id' => $client->id, 'position' => $position, 'disk' => 'local', 'path' => "weeklies/{$client->id}.mp3", 'duration_ms' => 1000]);
        }
    };

    ($this->grow)(3);
    $withReport();
    $uri = "/weeklies/{$this->active->id}";
    ($this->measure)($uri);
    $small = ($this->measure)($uri);

    ($this->grow)(12);
    $withReport();
    $large = ($this->measure)($uri);

    if (getenv('PERF_REPORT')) {
        fwrite(STDERR, "informe: {$small} → {$large} consultas\n");
    }

    expect($large)->toBeLessThanOrEqual(20)
        ->and($large - $small)->toBeLessThanOrEqual(1);
});

it('«Uso de IA» no crece con las llamadas registradas (10.3)', function () {
    $admin = userWithRole('admin');
    $measure = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/admin/uso-ia')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    AiUsage::factory()->count(5)->create(['user_id' => $admin->id]);
    $measure();
    $small = $measure();
    AiUsage::factory()->count(60)->create();
    $large = $measure();

    if (getenv('PERF_REPORT')) {
        fwrite(STDERR, "uso de IA: {$small} → {$large} consultas\n");
    }

    expect($large)->toBeLessThanOrEqual(12)
        ->and($large - $small)->toBeLessThanOrEqual(0);
});

it('las tareas de Mi espacio y el asistente no crecen con las tareas ni los proyectos (10.6)', function (string $uri, int $budget) {
    $more = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $project = Project::factory()->withMembers([$this->manager])->create(['client_id' => Client::factory()->create()->id]);
            $task = Task::factory()->assignedTo($this->manager)->create(['project_id' => $project->id, 'created_by' => userWithRole('employee')->id, 'description' => '<p>Nota</p>']);
            Task::factory()->assignedTo($this->manager)->completed()->create(['project_id' => $project->id]);
            TaskArchive::query()->create(['user_id' => $this->manager->id, 'task_id' => $task->id]);
        }
    };

    WeeklyCycle::factory()->create();
    $more(3);
    ($this->measure)($uri);
    $small = ($this->measure)($uri);

    $more(12);
    $large = ($this->measure)($uri);

    if (getenv('PERF_REPORT')) {
        fwrite(STDERR, "{$uri}: {$small} → {$large} consultas\n");
    }

    expect($large)->toBeLessThanOrEqual($budget)
        ->and($large - $small)->toBeLessThanOrEqual(0);
})->with([
    // Lo de mis weeklies más mis tareas (pendientes y hechas), el catálogo, los estados y la tanda.
    'tareas de Mi espacio' => ['/mi-espacio?pestana=tareas', 34],
    'asistente' => ['/ia', 8],
]);

it('el centro de ayuda y las sugerencias no crecen con el contenido (10.7)', function (string $uri, int $budget) {
    $board = SuggestionBoard::query()->firstOrFail();
    $category = SuggestionCategory::query()->firstOrFail();
    $first = null;
    $more = function (int $count) use ($board, $category, &$first): void {
        foreach (range(1, $count) as $i) {
            $author = userWithRole('employee');
            $release = HelpRelease::factory()->create();
            $release->changes()->create(['description' => 'Cambio', 'position' => 1]);
            HelpUpdateLike::query()->create(['likeable_type' => $release->getMorphClass(), 'likeable_id' => $release->id, 'user_id' => $author->id]);
            HelpManualUpdate::query()->create(['published_on' => '2026-09-01', 'title' => "Novedad {$i}", 'subtitle' => 's', 'body' => '<p>x</p>']);
            $tutorial = HelpTutorial::query()->create(['title' => "Tutorial {$i}", 'help_release_id' => $release->id, 'position' => $i]);
            Attachment::factory()->create(['attachable_type' => $tutorial->getMorphClass(), 'attachable_id' => $tutorial->id, 'project_id' => null, 'mime' => 'video/mp4']);
            $section = HelpFaqSection::factory()->create();
            $section->faqs()->create(['question' => '¿Qué?', 'answer' => '<p>Esto</p>', 'position' => 1]);

            foreach (['open', 'planned', 'beta'] as $status) {
                $post = SuggestionPost::factory()->create(['suggestion_board_id' => $board->id, 'suggestion_category_id' => $category->id, 'author_id' => $author->id, 'status' => $status]);
                $first ??= $post;
                SuggestionVote::query()->create(['suggestion_post_id' => $post->id, 'user_id' => $author->id]);
                SuggestionVote::query()->insertOrIgnore(['suggestion_post_id' => $first->id, 'user_id' => $author->id, 'created_at' => now()]);
                $comment = SuggestionComment::query()->create(['suggestion_post_id' => $first->id, 'author_id' => $author->id, 'body' => '<p>x</p>']);
                SuggestionComment::query()->create(['suggestion_post_id' => $first->id, 'author_id' => $author->id, 'parent_id' => $comment->id, 'body' => '<p>y</p>']);
                SuggestionCommentReaction::query()->create(['suggestion_comment_id' => $comment->id, 'user_id' => $author->id, 'reaction' => 'heart']);
                SuggestionStatusEvent::query()->create(['suggestion_post_id' => $first->id, 'to_status' => $status, 'changed_by' => $author->id]);
                Attachment::factory()->create(['attachable_type' => $comment->getMorphClass(), 'attachable_id' => $comment->id, 'project_id' => null]);
            }
        }
    };

    $more(2);
    $uri = fn (): string => str_replace('{post}', (string) $first?->id, $uri);
    ($this->measure)($uri());
    $small = ($this->measure)($uri());

    $more(8);
    $large = ($this->measure)($uri());

    if (getenv('PERF_REPORT')) {
        fwrite(STDERR, "{$uri()}: {$small} → {$large} consultas\n");
    }

    expect($large)->toBeLessThanOrEqual($budget)
        ->and($large - $small)->toBeLessThanOrEqual(0);
})->with([
    // La versión de la semana (una inserción ignorada), las versiones con sus cambios, las
    // actualizaciones, los «me gusta» con quién y el selector de versiones.
    'general' => ['/ayuda', 12],
    'tutoriales' => ['/ayuda?pestana=tutoriales', 10],
    'preguntas frecuentes' => ['/ayuda?pestana=preguntas', 7],
    // Tableros, recuentos, la categoría Bugs, las personas y una consulta por columna (con sus votos).
    'roadmap' => ['/ayuda?pestana=sugerencias', 27],
    'feedback' => ['/ayuda?pestana=sugerencias&vista=feedback', 18],
    'detalle de una sugerencia' => ['/ayuda/sugerencias/{post}', 28],
]);
