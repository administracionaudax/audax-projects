<?php

use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Privacy\RetentionPolicy;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Contrato de privacidad de la Fase 7 (SPEC §15, D-075): texto informativo con versión, su lectura
| y los plazos de retención.
*/

beforeEach(function () {
    $this->notice = app(PrivacyNotice::class);
    $this->employee = User::factory()->employee()->create();
});

test('el texto por defecto es el borrador pendiente de asesor', function () {
    expect($this->notice->isDraft())->toBeTrue()
        ->and($this->notice->text())->toContain('Borrador pendiente de revisión por el asesor')
        ->and($this->notice->version())->toBe(1);
});

test('cada persona interna debe leer la versión vigente; los clientes, no', function () {
    $client = User::factory()->portalOf(Client::factory()->create())->create();

    expect($this->notice->needsAcknowledgement($this->employee))->toBeTrue()
        ->and($this->notice->needsAcknowledgement($client))->toBeFalse();

    $this->notice->acknowledge($this->employee);

    expect($this->notice->needsAcknowledgement($this->employee->refresh()))->toBeFalse()
        ->and($this->employee->privacy_acknowledged_version)->toBe(1)
        ->and($this->employee->privacy_acknowledged_at)->not->toBeNull();
});

test('cambiar el texto sube la versión, vuelve a pedir la lectura y queda en la auditoría', function () {
    $admin = User::factory()->admin()->create();
    $this->notice->acknowledge($this->employee);

    $this->notice->update('## Texto revisado por el asesor', $admin);

    expect($this->notice->version())->toBe(2)
        ->and($this->notice->isDraft())->toBeFalse()
        ->and($this->notice->text())->toBe('## Texto revisado por el asesor')
        ->and($this->notice->needsAcknowledgement($this->employee->refresh()))->toBeTrue();

    $activity = Activity::query()->where('log_name', 'privacy')->sole();

    expect($activity->causer_id)->toBe($admin->id)
        ->and($activity->properties['attributes']['version'])->toBe(2);

    // El mismo texto no crea otra versión.
    $this->notice->update("## Texto revisado por el asesor\n", $admin);

    expect($this->notice->version())->toBe(2)
        ->and(Activity::query()->where('log_name', 'privacy')->count())->toBe(1);
});

test('las páginas internas avisan de la lectura pendiente', function () {
    $this->actingAs($this->employee)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('privacy.needs_acknowledgement', true));

    $this->notice->acknowledge($this->employee);

    $this->actingAs($this->employee->refresh())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('privacy.needs_acknowledgement', false));
});

test('los plazos de retención salen de los ajustes, con mínimos y sin límite donde se permite', function () {
    $policy = app(RetentionPolicy::class);
    $now = CarbonImmutable::parse('2026-10-31 10:00:00');

    expect($policy->months(RetentionPolicy::LOGIN_EVENTS))->toBe(12)
        ->and($policy->months(RetentionPolicy::READ_NOTIFICATIONS))->toBe(6)
        ->and($policy->months(RetentionPolicy::ACTIVITY_LOG))->toBe(60)
        ->and($policy->months(RetentionPolicy::CHAT_MESSAGES))->toBeNull()
        ->and($policy->cutoff(RetentionPolicy::CHAT_MESSAGES, $now))->toBeNull()
        ->and($policy->cutoff(RetentionPolicy::READ_NOTIFICATIONS, $now)?->toDateTimeString())->toBe('2026-04-30 10:00:00')
        ->and($policy->exportDays())->toBe(7);

    Setting::set('retention_activity_log_months', 3);
    Setting::set('retention_login_events_months', null);
    Setting::set('retention_chat_messages_months', 500);
    Setting::set('personal_data_export_days', 0);

    expect($policy->months(RetentionPolicy::ACTIVITY_LOG))->toBe(12)
        ->and($policy->months(RetentionPolicy::LOGIN_EVENTS))->toBe(12)
        ->and($policy->months(RetentionPolicy::CHAT_MESSAGES))->toBe(120)
        ->and($policy->exportDays())->toBe(1);
});
