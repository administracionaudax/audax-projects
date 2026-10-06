<?php

use App\Enums\SuggestionStatus;
use App\Enums\WeeklyCycleStatus;
use App\Models\AiUsage;
use App\Models\Client;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\HelpFaq;
use App\Models\HelpFaqSection;
use App\Models\HelpRelease;
use App\Models\HelpUpdateLike;
use App\Models\SuggestionCategory;
use App\Models\SuggestionPost;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Contrato 10.1: tablas, restricciones y relaciones de la Weekly, la ayuda y las sugerencias.
| Cada escritura que la base debe rechazar va en un punto de guardado (inSavepoint, tests/Pest.php):
| en PostgreSQL el error abortaría la transacción del test y las comprobaciones siguientes fallarían.
*/

it('solo puede haber una semana activa', function () {
    WeeklyCycle::factory()->active()->create();

    expect(inSavepoint(fn () => WeeklyCycle::factory()->active('2026-10-12')->create()))->toThrow(QueryException::class);

    // Cerradas, todas las que hagan falta.
    WeeklyCycle::factory()->count(3)->create();
    expect(WeeklyCycle::query()->closed()->count())->toBe(3)
        ->and(WeeklyCycle::query()->active()->sole()->status)->toBe(WeeklyCycleStatus::Active);
});

it('una weekly por persona y semana; un apunte por cliente', function () {
    $submission = WeeklySubmission::factory()->create();

    expect(inSavepoint(fn () => WeeklySubmission::factory()->create([
        'weekly_cycle_id' => $submission->weekly_cycle_id,
        'user_id' => $submission->user_id,
    ])))->toThrow(QueryException::class);

    $entry = WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id]);

    expect(inSavepoint(fn () => WeeklyEntry::factory()->create([
        'weekly_submission_id' => $submission->id,
        'client_id' => $entry->client_id,
    ])))->toThrow(QueryException::class)
        ->and(WeeklyEntry::query()->count())->toBe(1);
});

it('borrar a una persona no borra sus weeklies: la base lo impide (D-145)', function () {
    $submission = WeeklySubmission::factory()->submitted()->create();

    expect(inSavepoint(fn () => DB::table('users')->where('id', $submission->user_id)->delete()))->toThrow(QueryException::class);
    expect(WeeklySubmission::query()->count())->toBe(1);
});

it('borrar una semana borra su contenido (F-069)', function () {
    $cycle = WeeklyCycle::factory()->create();
    $submission = WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $cycle->id]);
    WeeklyEntry::factory()->count(2)->create(['weekly_submission_id' => $submission->id]);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $cycle->id]);
    ClientSatisfactionSnapshot::factory()->create(['weekly_cycle_id' => $cycle->id]);

    $cycle->delete();

    expect(WeeklySubmission::query()->count())->toBe(0)
        ->and(WeeklyEntry::query()->count())->toBe(0)
        ->and(WeeklyExemption::query()->count())->toBe(0)
        ->and(ClientSatisfactionSnapshot::query()->count())->toBe(0)
        ->and(Client::query()->count())->toBeGreaterThan(0);
});

it('las relaciones y los casts de la weekly funcionan', function () {
    $cycle = WeeklyCycle::factory()->active()->create(['report' => ['global_summary' => 'Hola', 'team_risks' => [], 'client_updates' => []]]);
    $submission = WeeklySubmission::factory()->submitted('2026-10-08 10:00:00')->create(['weekly_cycle_id' => $cycle->id]);
    WeeklyEntry::factory()->general()->create(['weekly_submission_id' => $submission->id, 'position' => 1]);
    WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'position' => 0]);

    $loaded = WeeklyCycle::query()->with(['submissions.entries.client', 'submissions.user'])->findOrFail($cycle->id);

    expect($loaded->isActive())->toBeTrue()
        ->and($loaded->start_date->toDateString())->toBe('2026-10-05')
        ->and($loaded->reportData()?->globalSummary)->toBe('Hola')
        ->and($loaded->submissions->first()?->isSubmitted())->toBeTrue()
        ->and($loaded->submissions->first()?->entries->pluck('position')->all())->toBe([0, 1])
        ->and($loaded->submissions->first()?->entries->last()?->client)->toBeNull();
});

it('los clientes empiezan con satisfacción 50 y las personas pueden tener puesto', function () {
    $client = Client::factory()->create(['icon' => '🚀']);
    $user = User::factory()->create(['job_title' => 'Diseñadora']);

    expect($client->fresh()?->satisfaction_score)->toBe(50)
        ->and($client->fresh()?->icon)->toBe('🚀')
        ->and($user->fresh()?->job_title)->toBe('Diseñadora');
});

it('el uso de IA se guarda sin fecha de actualización', function () {
    $usage = AiUsage::factory()->create();

    expect($usage->fresh()?->created_at)->not->toBeNull()
        ->and($usage->fresh()?->estimated_cost_usd)->toBe('0.000800');
});

it('la ayuda y las sugerencias guardan su contenido y precargan la categoría Bugs (F-169)', function () {
    $section = HelpFaqSection::factory()->create();
    HelpFaq::query()->create(['help_faq_section_id' => $section->id, 'question' => '¿Qué?', 'answer' => 'Esto.']);
    $release = HelpRelease::factory()->create(['major_version' => 1, 'month_number' => 10, 'week_of_month' => 1]);
    $user = userWithRole('employee');
    HelpUpdateLike::query()->create(['likeable_type' => $release->getMorphClass(), 'likeable_id' => $release->id, 'user_id' => $user->id]);

    $bugs = SuggestionCategory::query()->where('slug', SuggestionCategory::BUGS_SLUG)->sole();
    $post = SuggestionPost::factory()->create(['suggestion_board_id' => $bugs->suggestion_board_id, 'suggestion_category_id' => $bugs->id]);

    expect($release->versionLabel())->toBe('V.1.10.1')
        ->and($release->likes()->count())->toBe(1)
        ->and($section->faqs()->count())->toBe(1)
        ->and($post->fresh()?->status)->toBe(SuggestionStatus::Open)
        ->and($post->category?->board->slug)->toBe('sugerencias')
        ->and(inSavepoint(fn () => $section->delete()))->toThrow(QueryException::class)
        ->and($section->faqs()->count())->toBe(1);
});
