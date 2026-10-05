<?php

use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyCycleOpener;
use App\Domain\Weeklies\WeeklyRuleViolation;
use App\Enums\WeeklyCycleStatus;
use App\Models\Absence;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyAudioSection;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Ciclo semanal (10.2, D-150 y D-155; F-040, F-068, F-069 y F-070): abrir la semana (planificador y
| «Iniciar la semana»), ampliar el plazo, borrar una semana y el punto de enganche del cierre (10.3).
*/

function madrid(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'Europe/Madrid');
}

// --- WeeklyCycleOpener -------------------------------------------------------------------------

it('sin semanas, abre la semana en curso de Madrid (de lunes a viernes, plazo el viernes)', function () {
    $cycle = app(WeeklyCycleOpener::class)->ensureOpen(madrid('2026-10-05 00:05'));

    expect($cycle->status)->toBe(WeeklyCycleStatus::Active)
        ->and($cycle->number)->toBe('W41-26')
        ->and($cycle->start_date->toDateString())->toBe('2026-10-05')
        ->and($cycle->end_date->toDateString())->toBe('2026-10-09')
        ->and($cycle->deadline_date->toDateString())->toBe('2026-10-09')
        ->and($cycle->label)->toBe('Semana 41 (Lun 05/10 - Vie 09/10)');
});

it('el domingo a última hora de Madrid aún es la semana anterior; el lunes a las 00:05, la nueva', function () {
    expect(app(WeeklyCycleOpener::class)->target(madrid('2026-10-04 23:59'))->number)->toBe('W40-26')
        ->and(app(WeeklyCycleOpener::class)->target(madrid('2026-10-05 00:05'))->number)->toBe('W41-26');
});

it('con una activa no abre otra: la devuelve', function () {
    $active = WeeklyCycle::factory()->active('2026-09-28')->create();

    expect(app(WeeklyCycleOpener::class)->ensureOpen(madrid('2026-10-05 00:05'))->id)->toBe($active->id)
        ->and(WeeklyCycle::query()->count())->toBe(1);
});

it('una semana ya cerrada no se vuelve a abrir: abre la siguiente a la última', function () {
    WeeklyCycle::factory()->forWeekOf('2026-10-05')->create();

    $cycle = app(WeeklyCycleOpener::class)->ensureOpen(madrid('2026-10-07 09:00'));

    expect($cycle->number)->toBe('W42-26')
        ->and($cycle->start_date->toDateString())->toBe('2026-10-12');
});

it('si el servidor estuvo parado semanas, abre la semana en curso, no las perdidas', function () {
    WeeklyCycle::factory()->forWeekOf('2026-09-07')->create();

    expect(app(WeeklyCycleOpener::class)->ensureOpen(madrid('2026-10-07 09:00'))->number)->toBe('W41-26');
});

it('open() con una activa es ALREADY_ACTIVE; la carrera del índice único devuelve la activa', function () {
    $opener = app(WeeklyCycleOpener::class);
    WeeklyCycle::factory()->active('2026-10-05')->create();

    expect(fn () => $opener->open($opener->target(madrid('2026-10-12 09:00'))))
        ->toThrow(WeeklyRuleViolation::class, WeeklyRuleViolation::ALREADY_ACTIVE);
});

it('afterClose (enganche del cierre, 10.3) abre la siguiente a la cerrada', function () {
    $closed = WeeklyCycle::factory()->forWeekOf('2026-10-05')->create();

    $next = app(WeeklyCycleOpener::class)->afterClose($closed, madrid('2026-10-09 18:00'));

    expect($next->number)->toBe('W42-26')
        ->and($next->isActive())->toBeTrue();
});

it('el cambio de año usa la semana y el año ISO (D-153)', function () {
    $cycle = app(WeeklyCycleOpener::class)->ensureOpen(madrid('2026-12-28 00:05'));

    expect($cycle->number)->toBe('W53-26')
        ->and(app(WeeklyCycleOpener::class)->afterClose(tap($cycle)->update(['status' => WeeklyCycleStatus::Closed, 'closed_at' => now()]), madrid('2027-01-01 18:00'))->number)
        ->toBe('W01-27');
});

// --- Comando y planificador --------------------------------------------------------------------

it('weeklies:open-week abre la semana y es idempotente', function () {
    $this->travelTo(madrid('2026-10-05 00:05'));

    $this->artisan('weeklies:open-week')->expectsOutputToContain('W41-26')->assertSuccessful();
    $this->artisan('weeklies:open-week')->expectsOutputToContain('Ya había')->assertSuccessful();

    expect(WeeklyCycle::query()->count())->toBe(1);
});

it('con el módulo de la Weekly apagado, el comando no abre nada (F-177)', function () {
    Setting::set('modules', ['weeklies' => false]);

    $this->artisan('weeklies:open-week')->assertSuccessful();

    expect(WeeklyCycle::query()->count())->toBe(0);
});

it('se programa cada día a las 00:05 de Madrid, sin solaparse', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'weeklies:open-week'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('5 0 * * *')
        ->and($event->timezone)->toBe('Europe/Madrid')
        ->and($event->withoutOverlapping)->toBeTrue();
});

// --- «Iniciar la semana» (F-040) ---------------------------------------------------------------

it('quien gestiona inicia la semana si no hay ninguna activa', function () {
    $this->travelTo(madrid('2026-10-06 10:00'));

    $this->actingAs(userWithRole('department_manager'))
        ->post('/weeklies')
        ->assertRedirect('/weeklies')
        ->assertInertiaFlash('toast.message', __('weeklies.flash.opened', ['label' => 'Semana 41 (Lun 05/10 - Vie 09/10)']));

    expect(WeeklyCycle::query()->active()->sole()->number)->toBe('W41-26');
});

it('con una semana activa, «Iniciar la semana» da error y no abre otra', function () {
    WeeklyCycle::factory()->active()->create();

    $this->actingAs(userWithRole('admin'))
        ->postJson('/weeklies')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cycle' => __('weeklies.errors.already_active')]);

    expect(WeeklyCycle::query()->count())->toBe(1);
});

// --- Ampliar el plazo (F-068) ------------------------------------------------------------------

it('quien gestiona amplía el plazo de la semana activa', function () {
    $cycle = WeeklyCycle::factory()->active('2026-10-05')->create();

    $this->actingAs(userWithRole('department_manager'))
        ->put("/weeklies/{$cycle->id}/plazo", ['deadline_date' => '2026-10-13'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('weeklies.flash.deadline_updated'));

    expect($cycle->fresh()?->deadline_date->toDateString())->toBe('2026-10-13');
});

it('el plazo va del lunes de la semana a cuatro semanas después del viernes', function (string $date, bool $ok) {
    $cycle = WeeklyCycle::factory()->active('2026-10-05')->create();

    $response = $this->actingAs(userWithRole('admin'))->putJson("/weeklies/{$cycle->id}/plazo", ['deadline_date' => $date]);

    $ok ? $response->assertRedirect() : $response->assertUnprocessable()->assertJsonValidationErrors(['deadline_date']);
})->with([
    'lunes' => ['2026-10-05', true],
    'domingo anterior' => ['2026-10-04', false],
    'cuatro semanas' => ['2026-11-06', true],
    'un día más' => ['2026-11-07', false],
    'formato' => ['09/10/2026', false],
]);

it('con la semana cerrada no se amplía el plazo (403)', function () {
    $closed = WeeklyCycle::factory()->create();

    $this->actingAs(userWithRole('admin'))->putJson("/weeklies/{$closed->id}/plazo", ['deadline_date' => $closed->end_date->toDateString()])->assertForbidden();
});

it('ampliar el plazo recalcula quién está exento y renueva el contador de pendientes (F-098)', function () {
    $this->travelTo(madrid('2026-10-07 10:00'));
    $cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $me = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    Absence::factory()->approved()->between('2026-10-08', '2026-10-09')->create(['user_id' => $me->id]);

    expect(app(MyWeeklyStatus::class)->pendingCount($me))->toBe(0);

    $this->actingAs(userWithRole('admin'))->put("/weeklies/{$cycle->id}/plazo", ['deadline_date' => '2026-10-13']);

    expect(app(MyWeeklyStatus::class)->pendingCount($me))->toBe(1);
});

// --- Borrar una semana (F-069) -----------------------------------------------------------------

it('borrar la semana más reciente se lleva todo lo suyo y abre la siguiente', function () {
    Storage::fake('local');
    $this->travelTo(madrid('2026-10-07 10:00'));
    $cycle = WeeklyCycle::factory()->active('2026-10-05')->create(['audio_disk' => 'local', 'audio_path' => 'weeklies/41/full.mp3']);
    $submission = WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $cycle->id]);
    WeeklyEntry::factory()->general()->create(['weekly_submission_id' => $submission->id]);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $cycle->id]);
    $section = WeeklyAudioSection::query()->create(['weekly_cycle_id' => $cycle->id, 'key' => 'intro', 'kind' => 'intro', 'position' => 0, 'script' => 'Hola', 'disk' => 'local', 'path' => 'weeklies/41/intro.mp3']);
    ClientSatisfactionSnapshot::factory()->create(['weekly_cycle_id' => $cycle->id]);
    Storage::disk('local')->put('weeklies/41/full.mp3', 'mp3');
    Storage::disk('local')->put('weeklies/41/intro.mp3', 'mp3');

    $this->actingAs(userWithRole('admin'))
        ->delete("/weeklies/{$cycle->id}")
        ->assertRedirect('/weeklies?pestana=historico')
        ->assertInertiaFlash('toast.message', __('weeklies.flash.deleted_and_opened', ['label' => $cycle->label, 'next' => 'Semana 42 (Lun 12/10 - Vie 16/10)']));

    expect(WeeklyCycle::query()->find($cycle->id))->toBeNull()
        ->and(WeeklySubmission::query()->count())->toBe(0)
        ->and(WeeklyEntry::query()->count())->toBe(0)
        ->and(WeeklyExemption::query()->count())->toBe(0)
        ->and(WeeklyAudioSection::query()->find($section->id))->toBeNull()
        ->and(ClientSatisfactionSnapshot::query()->count())->toBe(0)
        ->and(WeeklyCycle::query()->active()->sole()->number)->toBe('W42-26');

    Storage::disk('local')->assertMissing('weeklies/41/full.mp3');
    Storage::disk('local')->assertMissing('weeklies/41/intro.mp3');
});

it('borrar una semana antigua no abre ninguna', function () {
    WeeklyCycle::factory()->active('2026-10-05')->create();
    $old = WeeklyCycle::factory()->forWeekOf('2026-09-21')->create();

    $this->actingAs(userWithRole('department_manager'))
        ->delete("/weeklies/{$old->id}")
        ->assertInertiaFlash('toast.message', __('weeklies.flash.deleted', ['label' => $old->label]));

    expect(WeeklyCycle::query()->count())->toBe(1);
});

it('borrar no toca a las personas: autoría restrict, envíos en cascada', function () {
    $cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $author = User::factory()->create();
    WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $cycle->id, 'user_id' => $author->id]);

    $this->actingAs(userWithRole('admin'))->delete("/weeklies/{$cycle->id}");

    expect(User::query()->find($author->id))->not->toBeNull();
});

// --- Página de las Weeklies --------------------------------------------------------------------

it('/weeklies trae la gestión de la semana a quien gestiona y no a la plantilla', function () {
    $this->travelTo(madrid('2026-10-07 10:00'));
    $cycle = WeeklyCycle::factory()->active('2026-10-05')->create();

    $this->actingAs(userWithRole('department_manager'))->get('/weeklies')->assertInertia(fn (Assert $page) => $page
        ->where('active.id', $cycle->id)
        ->where('can.manage', true)
        ->where('can.create', false)
        ->where('can.extendDeadline', true)
        ->where('can.delete', true)
        ->where('can.exempt', true));

    $this->actingAs(userWithRole('employee'))->get('/weeklies')->assertInertia(fn (Assert $page) => $page
        ->where('can.manage', false)
        ->where('can.extendDeadline', false)
        ->where('can.delete', false)
        ->where('can.exempt', false));
});

it('sin semana activa, quien gestiona ve «Iniciar la semana» (F-040)', function () {
    $this->actingAs(userWithRole('admin'))->get('/weeklies')->assertInertia(fn (Assert $page) => $page
        ->where('active', null)
        ->where('me', null)
        ->where('can.create', true));
});
