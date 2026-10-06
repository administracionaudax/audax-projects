<?php

use App\Domain\Audit\AuditCatalog;
use App\Domain\Privacy\Export\Sections\DayPlanCommentsSection;
use App\Domain\Privacy\Export\Sections\DayPlansSection;
use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Privacy\RetentionPolicy;
use App\Models\DayPlan;
use App\Models\DayPlanComment;
use App\Models\DayPlanItem;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| RGPD y auditoría del plan del día (docs/PLAN-CARGAS.md §10 y R2; D-256): es un dato de desempeño.
| La exportación de datos personales lleva mis líneas y los comentarios (los míos y los de mis
| líneas), nunca las de otra persona; la retención (un año por defecto) borra los días antiguos sin
| tocar nunca las horas; y los cambios quedan en la auditoría.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->elena = userWithRole('employee', ['name' => 'Elena']);
    $this->other = userWithRole('employee', ['name' => 'Otra']);
    $this->manager = userWithRole('department_manager', ['name' => 'Raúl']);
    $this->rows = fn (object $section, User $user): array => iterator_to_array($section->rows($user), false);
});

it('la exportación lleva mis líneas, mi nota y los comentarios de mis líneas o míos', function () {
    $line = DayPlanItem::factory()->notDone('Sin respuesta')->create(['user_id' => $this->elena->id, 'date' => '2026-10-06', 'text' => 'Banners', 'planned_minutes' => 90, 'carry_count' => 1]);
    DayPlan::query()->whereKey($line->day_plan_id)->update(['note' => 'Médico a las 12']);
    $foreign = DayPlanItem::factory()->create(['user_id' => $this->other->id, 'date' => '2026-10-06', 'text' => 'De otra']);
    DayPlanComment::query()->create(['day_plan_item_id' => $line->id, 'user_id' => $this->manager->id, 'body' => '¿Qué pasó?']);
    DayPlanComment::query()->create(['day_plan_item_id' => $foreign->id, 'user_id' => $this->manager->id, 'body' => 'No es tuyo']);

    expect(config('privacy.export_sections'))->toContain(DayPlansSection::class, DayPlanCommentsSection::class);

    $rows = ($this->rows)(new DayPlansSection, $this->elena);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'date' => '2026-10-06',
            'text' => 'Banners',
            'planned_minutes' => 90,
            'status' => 'No hecha',
            'not_done_reason' => 'Sin respuesta',
            'carry_count' => 1,
            'note' => 'Médico a las 12',
            'deleted' => false,
        ]);

    $comments = ($this->rows)(new DayPlanCommentsSection, $this->elena);
    expect(array_column($comments, 'body'))->toBe(['¿Qué pasó?'])
        ->and($comments[0]['author'])->toBe('Raúl');

    // El responsable ve en su exportación los comentarios que ha escrito.
    expect(array_column(($this->rows)(new DayPlanCommentsSection, $this->manager), 'body'))->toBe(['¿Qué pasó?', 'No es tuyo']);
});

it('la retención borra los días antiguos con sus líneas y comentarios, nunca las horas', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);
    $old = DayPlanItem::factory()->create(['user_id' => $this->elena->id, 'date' => '2025-09-30']);
    DayPlanComment::query()->create(['day_plan_item_id' => $old->id, 'user_id' => $this->manager->id, 'body' => 'Antiguo']);
    $entry = TimeEntry::factory()->create(['user_id' => $this->elena->id, 'task_id' => $task->id, 'project_id' => $project->id, 'date' => '2025-09-30', 'minutes' => 60, 'day_plan_item_id' => $old->id]);
    $recent = DayPlanItem::factory()->create(['user_id' => $this->elena->id, 'date' => '2026-09-30']);

    expect(app(RetentionPolicy::class)->months(RetentionPolicy::DAY_PLANS))->toBe(12);

    $this->artisan('app:prune-data')->assertSuccessful();

    expect(DayPlanItem::withTrashed()->pluck('id')->all())->toBe([$recent->id])
        ->and(DayPlan::query()->count())->toBe(1)
        ->and(DB::table('day_plan_comments')->count())->toBe(0)
        ->and($entry->fresh()->minutes)->toBe(60)
        ->and($entry->fresh()->day_plan_item_id)->toBeNull();

    Setting::set('retention_day_plans_months', 120);
    expect(app(RetentionPolicy::class)->months(RetentionPolicy::DAY_PLANS))->toBe(120);
});

it('los cambios del plan quedan en la auditoría, sin el orden ni el recordatorio', function () {
    $line = DayPlanItem::factory()->create(['user_id' => $this->elena->id, 'date' => '2026-10-07', 'text' => 'Banners']);
    $line->update(['position' => 5]);
    $line->update(['text' => 'Banners BF']);

    $events = DB::table('activity_log')->where('log_name', 'day_plan_items')->where('subject_id', $line->id)->pluck('event')->all();

    expect($events)->toBe(['created', 'updated'])
        ->and(AuditCatalog::ENTITIES['day_plan'])->toBe(['day_plans', 'day_plan_items', 'day_plan_comments']);
});

it('el texto informativo pendiente del asesor menciona el plan del día', function () {
    expect(app(PrivacyNotice::class)->text())->toContain('plan del día');
});
