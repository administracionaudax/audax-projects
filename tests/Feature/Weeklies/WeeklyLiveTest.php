<?php

use App\Domain\Weeklies\WeeklyAway;
use App\Enums\WeeklyAwayReason;
use App\Enums\WeeklyJobState;
use App\Events\Weeklies\WeeklyChanged;
use App\Models\Absence;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;

/*
| La Weekly en vivo (10.9b, D-229): cada cambio que se ve en el resumen, el histórico o el informe
| avisa por `weekly.changed` en el canal privado `weeklies`, como el tiempo real de WeeklySync.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->me = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
});

it('el canal «weeklies» es de quien usa la Weekly y el evento no lleva contenido', function () {
    $callback = app(BroadcastManager::class)->driver()->getChannels()->get('weeklies');

    expect($callback($this->me))->toBeTrue()
        ->and($callback(User::factory()->collaborator()->create()))->toBeFalse();

    $event = new WeeklyChanged(7, 'submission');
    expect($event->broadcastAs())->toBe('weekly.changed')
        ->and($event->broadcastWith())->toBe(['cycle_id' => 7, 'reason' => 'submission'])
        ->and($event->broadcastOn()[0]->name)->toBe('private-weeklies');
});

it('avisa al enviar, pero no al guardar un borrador', function () {
    Event::fake([WeeklyChanged::class]);

    $submission = WeeklySubmission::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->me->id]);
    $submission->forceFill(['draft_saved_at' => now()])->save();
    Event::assertNotDispatched(WeeklyChanged::class);

    $submission->forceFill(['submitted_at' => now()])->save();
    Event::assertDispatched(WeeklyChanged::class, fn (WeeklyChanged $event) => $event->cycleId === $this->cycle->id && $event->reason === 'submission');
});

it('avisa con las exenciones, las ausencias y «Estoy fuera»', function () {
    Event::fake([WeeklyChanged::class]);

    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->me->id]);
    Absence::factory()->for($this->me)->approved()->between('2026-10-08', '2026-10-09')->create();
    app(WeeklyAway::class)->set($this->me, WeeklyAwayReason::Vacation, null);

    Event::assertDispatchedTimes(WeeklyChanged::class, 3);
    Event::assertDispatched(WeeklyChanged::class, fn (WeeklyChanged $event) => $event->cycleId === $this->cycle->id && $event->reason === 'exemption');
});

it('avisa con el plazo, el cierre y el informe terminado, no con cada paso de la generación', function () {
    Event::fake([WeeklyChanged::class]);

    $this->cycle->forceFill(['report_state' => WeeklyJobState::Running])->save();
    Event::assertNotDispatched(WeeklyChanged::class);

    $this->cycle->forceFill(['report_state' => WeeklyJobState::Done])->save();
    Event::assertDispatched(WeeklyChanged::class, fn (WeeklyChanged $event) => $event->reason === 'report');

    $this->cycle->forceFill(['deadline_date' => '2026-10-12'])->save();
    Event::assertDispatched(WeeklyChanged::class, fn (WeeklyChanged $event) => $event->reason === 'cycle');
});

it('silenciado (importación y datos de ejemplo) no avisa', function () {
    Event::fake([WeeklyChanged::class]);

    WeeklyChanged::muted(fn () => WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->me->id]));

    Event::assertNotDispatched(WeeklyChanged::class);
});
