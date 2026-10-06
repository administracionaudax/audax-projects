<?php

use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyDraftData;
use App\Domain\Weeklies\WeeklyRuleViolation;
use App\Domain\Weeklies\WeeklySubmissionWriter;
use App\Enums\WeeklyEntrySource;
use App\Enums\WeeklyExemptionReason;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| WeeklySubmissionWriter (10.2, D-150 y D-157; F-044, F-051, F-052 y F-054): las reglas 1 a 7 del
| contrato, por el dominio y por las rutas «Mi weekly». Semana del 05/10/2026, plazo el viernes 09/10.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->writer = app(WeeklySubmissionWriter::class);
    $this->me = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
});

function draftOf(array $entries): WeeklyDraftData
{
    return WeeklyDraftData::fromArray($entries);
}

function writerViolation(Closure $call): ?string
{
    try {
        $call();
    } catch (WeeklyRuleViolation $violation) {
        return $violation->rule;
    }

    return null;
}

it('regla 1: con la semana cerrada no se escribe (solo lectura)', function () {
    $closed = WeeklyCycle::factory()->create();

    expect(writerViolation(fn () => $this->writer->saveDraft($this->me, $closed, draftOf([['client_id' => null, 'body' => 'x']]))))
        ->toBe(WeeklyRuleViolation::CYCLE_CLOSED)
        ->and(writerViolation(fn () => $this->writer->submit($this->me, $closed, draftOf([['client_id' => null, 'body' => 'x']]))))
        ->toBe(WeeklyRuleViolation::CYCLE_CLOSED)
        ->and(WeeklySubmission::query()->count())->toBe(0);
});

it('regla 1: si la semana se cierra después de cargarla, se rechaza igual (relee la semana)', function () {
    $stale = WeeklyCycle::query()->findOrFail($this->cycle->id);
    $this->cycle->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    expect(writerViolation(fn () => $this->writer->submit($this->me, $stale, draftOf([['client_id' => null, 'body' => 'x']]))))
        ->toBe(WeeklyRuleViolation::CYCLE_CLOSED);
});

it('regla 2: quien no participa esa semana no escribe', function (Closure $person) {
    $user = $person();

    expect(writerViolation(fn () => $this->writer->saveDraft($user, $this->cycle, draftOf([['client_id' => null, 'body' => 'x']]))))
        ->toBe(WeeklyRuleViolation::NOT_PARTICIPANT);
})->with([
    'alta después del viernes' => [fn () => userWithRole('employee', ['created_at' => '2026-10-10 09:00:00'])],
    'desactivado' => [fn () => userWithRole('employee', ['created_at' => '2026-09-01 08:00:00', 'is_active' => false])],
    'colaborador externo' => [fn () => User::factory()->collaborator()->create(['created_at' => '2026-09-01 08:00:00'])],
]);

it('regla 2: exento por ausencia o a mano no escribe (F-054); tras renunciar, sí (F-053)', function () {
    $absence = Absence::factory()->approved()->between('2026-10-08', '2026-10-12')->create(['user_id' => $this->me->id]);

    expect(writerViolation(fn () => $this->writer->saveDraft($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'x']]))))
        ->toBe(WeeklyRuleViolation::EXEMPT);

    WeeklyExemption::factory()->waived()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->me->id, 'absence_id' => $absence->id]);

    expect($this->writer->submit($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Hecho']]))->isSubmitted())->toBeTrue();

    $other = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $other->id, 'reason' => WeeklyExemptionReason::Manual]);

    expect(writerViolation(fn () => $this->writer->submit($other, $this->cycle, draftOf([['client_id' => null, 'body' => 'x']]))))
        ->toBe(WeeklyRuleViolation::EXEMPT);
});

it('regla 3: una sola fila por persona y semana; borrador y envío son la misma', function () {
    $draft = $this->writer->saveDraft($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Uno']]));
    $again = $this->writer->saveDraft($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Dos']]));
    $sent = $this->writer->submit($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Tres']]));

    expect($again->id)->toBe($draft->id)
        ->and($sent->id)->toBe($draft->id)
        ->and(WeeklySubmission::query()->count())->toBe(1);
});

it('regla 4: los apuntes se sustituyen enteros, sin vacíos, uno por cliente y en orden', function () {
    $acme = Client::factory()->create();
    $beta = Client::factory()->create();
    $acmeProject = Project::factory()->create(['client_id' => $acme->id]);
    $betaProject = Project::factory()->create(['client_id' => $beta->id]);

    $this->writer->saveDraft($this->me, $this->cycle, draftOf([
        ['client_id' => $beta->id, 'body' => 'Antiguo'],
    ]));

    $submission = $this->writer->saveDraft($this->me, $this->cycle, draftOf([
        ['client_id' => $acme->id, 'project_id' => $acmeProject->id, 'body' => '  Lanzamiento  ', 'source' => 'dictation'],
        ['client_id' => null, 'body' => 'Interno'],
        ['client_id' => $beta->id, 'body' => '   '],
        ['client_id' => $beta->id, 'project_id' => $acmeProject->id, 'body' => 'Proyecto de otro cliente'],
        ['client_id' => $acme->id, 'project_id' => $betaProject->id, 'body' => 'El último gana'],
        ['client_id' => null, 'project_id' => 999999, 'body' => 'General también, el último gana'],
    ]));

    $entries = $submission->entries()->orderBy('position')->get();

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('client_id')->all())->toBe([$acme->id, null, $beta->id])
        ->and($entries->pluck('body')->all())->toBe(['El último gana', 'General también, el último gana', 'Proyecto de otro cliente'])
        ->and($entries->pluck('project_id')->all())->toBe([null, null, null])
        ->and($entries->pluck('position')->all())->toBe([0, 1, 2]);

    $submission = $this->writer->saveDraft($this->me, $this->cycle, draftOf([
        ['client_id' => $acme->id, 'project_id' => $acmeProject->id, 'body' => 'Lanzamiento', 'source' => 'dictation'],
    ]));
    $entry = $submission->entries()->sole();

    expect($entry->project_id)->toBe($acmeProject->id)
        ->and($entry->source)->toBe(WeeklyEntrySource::Dictation);
});

it('regla 5: el borrador no envía y apunta cuándo se guardó', function () {
    $submission = $this->writer->saveDraft($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Borrador']]));

    expect($submission->submitted_at)->toBeNull()
        ->and($submission->draft_saved_at?->equalTo(now()))->toBeTrue();
});

it('regla 5: autoguardar una weekly ya enviada no cambia la fecha de envío', function () {
    $sent = $this->writer->submit($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Enviada']]));
    $this->travel(2)->hours();

    $saved = $this->writer->saveDraft($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Corregida']]));

    expect($saved->submitted_at?->equalTo($sent->submitted_at))->toBeTrue()
        ->and($saved->resubmitted_at)->toBeNull()
        ->and($saved->entries()->sole()->body)->toBe('Corregida');
});

it('regla 6: el primer envío decide la puntualidad; reenviar la conserva y apunta el reenvío', function () {
    $first = $this->writer->submit($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Uno']]));
    $firstAt = CarbonImmutable::instance($first->submitted_at);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'Europe/Madrid'));
    $again = $this->writer->submit($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Dos']]));

    expect($again->submitted_at?->equalTo($firstAt))->toBeTrue()
        ->and($again->resubmitted_at?->equalTo(now()))->toBeTrue();
});

it('regla 6: se puede enviar fuera de plazo mientras la semana siga activa', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 11:00:00', 'Europe/Madrid'));

    $late = $this->writer->submit($this->me, $this->cycle, draftOf([['client_id' => null, 'body' => 'Tarde']]));

    expect($late->isSubmitted())->toBeTrue();
});

// --- Por las rutas -----------------------------------------------------------------------------

it('el borrador autoguardado responde en JSON con la weekly guardada (F-051)', function () {
    $client = Client::factory()->create();

    $this->actingAs($this->me)
        ->putJson("/mi-espacio/weeklies/{$this->cycle->id}", ['entries' => [
            ['client_id' => $client->id, 'body' => 'Avance', 'source' => 'text'],
            ['client_id' => null, 'body' => ''],
        ]])
        ->assertOk()
        ->assertJsonPath('submission.is_submitted', false)
        ->assertJsonPath('submission.entries.0.client_id', $client->id)
        ->assertJsonPath('submission.entries.0.body', 'Avance')
        ->assertJsonCount(1, 'submission.entries');
});

it('enviar redirige a mi weekly con aviso; reenviar avisa distinto (F-052)', function () {
    $this->actingAs($this->me)
        ->post("/mi-espacio/weeklies/{$this->cycle->id}/enviar", ['entries' => [['client_id' => null, 'body' => 'Hecho']]])
        ->assertRedirect("/mi-espacio?semana={$this->cycle->id}")
        ->assertInertiaFlash('toast.message', __('weeklies.flash.submitted'));

    $this->actingAs($this->me)
        ->post("/mi-espacio/weeklies/{$this->cycle->id}/enviar", ['entries' => [['client_id' => null, 'body' => 'Hecho y más']]])
        ->assertInertiaFlash('toast.message', __('weeklies.flash.resubmitted'));

    expect(WeeklySubmission::query()->sole()->resubmitted_at)->not->toBeNull();
});

it('se puede enviar sin apuntes, como en WeeklySync (D-230); el borrador vacío también se guarda', function () {
    $this->actingAs($this->me)
        ->post("/mi-espacio/weeklies/{$this->cycle->id}/enviar", ['entries' => [['client_id' => null, 'body' => '  ']]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('weeklies.flash.submitted'));

    $submission = WeeklySubmission::query()->sole();
    expect($submission->submitted_at)->not->toBeNull()
        ->and($submission->entries()->count())->toBe(0)
        ->and(app(MyWeeklyStatus::class)->pendingCount($this->me))->toBe(0);

    $this->actingAs($this->me)
        ->putJson("/mi-espacio/weeklies/{$this->cycle->id}", ['entries' => []])
        ->assertOk()
        ->assertJsonCount(0, 'submission.entries');
});

it('valida el formato: cliente inexistente o borrado, texto demasiado largo, origen desconocido', function (array $entry, string $error) {
    $this->actingAs($this->me)
        ->putJson("/mi-espacio/weeklies/{$this->cycle->id}", ['entries' => [$entry]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
})->with([
    'cliente inexistente' => [['client_id' => 999999, 'body' => 'x'], 'entries.0.client_id'],
    'cliente borrado' => [fn () => ['client_id' => tap(Client::factory()->create())->delete()->id, 'body' => 'x'], 'entries.0.client_id'],
    'texto largo' => [['client_id' => null, 'body' => str_repeat('a', 20001)], 'entries.0.body'],
    'origen' => [['client_id' => null, 'body' => 'x', 'source' => 'fax'], 'entries.0.source'],
    'clave extra' => [['client_id' => null, 'body' => 'x', 'user_id' => 1], 'entries.0'],
]);

it('una regla rota es un error de validación con su mensaje', function () {
    Absence::factory()->approved()->between('2026-10-05', '2026-10-09')->create(['user_id' => $this->me->id]);

    $this->actingAs($this->me)
        ->putJson("/mi-espacio/weeklies/{$this->cycle->id}", ['entries' => [['client_id' => null, 'body' => 'x']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['entries' => __('weeklies.errors.exempt')]);
});

it('con la semana cerrada, 403; un colaborador externo, 403', function () {
    $closed = WeeklyCycle::factory()->create();

    $this->actingAs($this->me)->putJson("/mi-espacio/weeklies/{$closed->id}", ['entries' => []])->assertForbidden();
    $this->actingAs($this->me)->postJson("/mi-espacio/weeklies/{$closed->id}/enviar", ['entries' => []])->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())
        ->putJson("/mi-espacio/weeklies/{$this->cycle->id}", ['entries' => []])
        ->assertForbidden();
});

it('cada persona escribe solo la suya: el envío va siempre a nombre de quien entra', function () {
    $other = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);

    $this->actingAs($other)
        ->putJson("/mi-espacio/weeklies/{$this->cycle->id}", ['entries' => [['client_id' => null, 'body' => 'Mío']]])
        ->assertOk();

    expect(WeeklySubmission::query()->sole()->user_id)->toBe($other->id);
});

it('al enviar, el contador de «Mi espacio» baja a 0 en la siguiente página', function () {
    $this->actingAs($this->me)->get('/mi-espacio')->assertInertia(fn (Assert $page) => $page->where('weeklies.pending', 1));

    $this->actingAs($this->me)->post("/mi-espacio/weeklies/{$this->cycle->id}/enviar", ['entries' => [['client_id' => null, 'body' => 'Hecho']]]);

    $this->actingAs($this->me)->get('/mi-espacio')->assertInertia(fn (Assert $page) => $page->where('weeklies.pending', 0));
});
