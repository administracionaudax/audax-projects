<?php

use App\Domain\Access\CollaboratorOffboarding;
use App\Domain\Audit\AuditCatalog;
use App\Domain\Audit\AuditEntries;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Integrations\Google\GoogleReconnectRequired;
use App\Jobs\RevokeGoogleToken;
use App\Models\GoogleConnection;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Integrations\GoogleFakes;

/*
| Desconexión automática de Google (D-142): al dar de baja a una persona (cualquier
| is_active = false) o al pasarla a colaborador externo, se borra su conexión, queda en la
| auditoría (log integrations, sin tokens) y el token se revoca en Google desde la cola, sin
| bloquear y sin reintentos. Conectar y desconectar a mano también quedan en la auditoría.
| Nunca se llama a Google: Http::fake y preventStrayRequests.
*/

beforeEach(function () {
    GoogleFakes::configure();
    Http::preventStrayRequests();

    $this->admin = userWithRole('admin');
    $this->user = userWithRole('employee', ['name' => 'Elena Sale']);
});

/** Entradas de la auditoría de integraciones. */
function integrationActivities(): Collection
{
    return Activity::query()->where('log_name', 'integrations')->orderBy('id')->get();
}

test('al dar de baja con el asistente se borra la conexión, se audita y la revocación va a la cola', function () {
    Queue::fake();
    GoogleFakes::connect($this->user);

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->user->id}/baja", ['default_assignee_id' => null])
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()?->is_active)->toBeFalse()
        ->and(GoogleConnection::query()->count())->toBe(0);

    Queue::assertPushedOn('default', RevokeGoogleToken::class, fn (RevokeGoogleToken $job) => $job->token === '1//refresco-guardado'
        && $job->userId === $this->user->id
        && $job->tries === 1);

    $activity = integrationActivities()->sole();
    expect($activity->event)->toBe('google_auto_disconnected')
        ->and($activity->subject_id)->toBe($this->user->id)
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties->toArray())->toBe(['google_email' => 'elena@audaxstudio.com', 'reason' => 'deactivated'])
        ->and(json_encode($activity->properties))->not->toContain('refresco');
});

test('cualquier is_active = false desconecta (revocación del portal, importación…)', function () {
    Queue::fake();
    GoogleFakes::connect($this->user);

    $this->user->forceFill(['is_active' => false])->save();

    expect(GoogleConnection::query()->count())->toBe(0);
    Queue::assertPushed(RevokeGoogleToken::class, 1);
});

test('sin conexión, la baja no encola nada ni audita', function () {
    Queue::fake();

    $this->user->forceFill(['is_active' => false])->save();

    Queue::assertNothingPushed();
    expect(integrationActivities())->toHaveCount(0);
});

test('otros cambios de la persona no tocan la conexión', function () {
    Queue::fake();
    GoogleFakes::connect($this->user);

    $this->user->forceFill(['name' => 'Elena Sigue'])->save();
    $inactive = userWithRole('employee', ['is_active' => false]);
    GoogleFakes::connect($inactive, ['google_email' => 'otra@audaxstudio.com']);
    $inactive->forceFill(['is_active' => true])->save();

    expect(GoogleConnection::query()->count())->toBe(2);
    Queue::assertNothingPushed();
});

test('si la baja se deshace (rollback), la conexión sigue y no se revoca nada', function () {
    // Cola síncrona de verdad (respeta afterCommit; Queue::fake no).
    Http::fake([GoogleOAuth::REVOKE_URL => Http::response()]);
    GoogleFakes::connect($this->user);

    try {
        DB::transaction(function () {
            $this->user->forceFill(['is_active' => false])->save();

            throw new RuntimeException('falla la baja');
        });
    } catch (RuntimeException) {
    }

    expect(GoogleConnection::query()->count())->toBe(1)
        ->and(integrationActivities())->toHaveCount(0);
    Http::assertNothingSent();

    // Y si se confirma, se revoca al hacer commit.
    $this->user->refresh();
    DB::transaction(fn () => $this->user->forceFill(['is_active' => false])->save());

    Http::assertSent(fn (HttpRequest $request) => $request->url() === GoogleOAuth::REVOKE_URL);
});

test('al pasar a colaborador desde la administración se desconecta con su motivo', function () {
    Queue::fake();
    GoogleFakes::connect($this->user);

    $this->actingAs($this->admin)
        ->put("/admin/usuarios/{$this->user->id}", ['name' => $this->user->name, 'email' => $this->user->email, 'role' => 'collaborator'])
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()?->isCollaborator())->toBeTrue()
        ->and(GoogleConnection::query()->count())->toBe(0)
        ->and(integrationActivities()->sole()->properties->toArray())->toBe(['google_email' => 'elena@audaxstudio.com', 'reason' => 'collaborator']);

    Queue::assertPushed(RevokeGoogleToken::class, 1);
});

test('CollaboratorOffboarding::becameCollaborator también desconecta (importación de ClickUp)', function () {
    Queue::fake();
    GoogleFakes::connect($this->user);

    app(CollaboratorOffboarding::class)->becameCollaborator($this->user);

    expect(GoogleConnection::query()->count())->toBe(0);
    Queue::assertPushed(RevokeGoogleToken::class, 1);
});

test('el job revoca el token en Google', function () {
    Http::fake([GoogleOAuth::REVOKE_URL => Http::response()]);
    Log::spy();

    (new RevokeGoogleToken('1//refresco-guardado', $this->user->id))->handle(app(GoogleOAuth::class));

    Http::assertSent(fn (HttpRequest $request) => $request->url() === GoogleOAuth::REVOKE_URL && $request['token'] === '1//refresco-guardado');
    Log::shouldNotHaveReceived('warning');
});

test('si Google no confirma la revocación o no responde, se registra sin el token y no se reintenta', function (Closure $fake) {
    $fake();
    Log::spy();

    (new RevokeGoogleToken('1//refresco-guardado', $this->user->id))->handle(app(GoogleOAuth::class));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['user_id' => $this->user->id]
        && ! str_contains($message.json_encode($context), 'refresco'));
})->with([
    'Google responde 400' => [fn () => Http::fake([GoogleOAuth::REVOKE_URL => Http::response(['error' => 'invalid_token'], 400)])],
    'Google caído' => [fn () => Http::fake([GoogleOAuth::REVOKE_URL => Http::response('', 503)])],
    'sin red' => [fn () => Http::fake([GoogleOAuth::REVOKE_URL => Http::failedConnection()])],
]);

test('el job va cifrado en la cola, con un solo intento y después del commit', function () {
    $job = new RevokeGoogleToken('1//refresco-guardado', $this->user->id);

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->tries)->toBe(1)
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->queue)->toBe('default');
});

test('la baja termina aunque Google no responda (la cola es síncrona en los tests)', function () {
    Http::fake([GoogleOAuth::REVOKE_URL => Http::failedConnection()]);
    GoogleFakes::connect($this->user);

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->user->id}/baja", ['default_assignee_id' => null])
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()?->is_active)->toBeFalse()
        ->and(GoogleConnection::query()->count())->toBe(0);
});

test('conectar y desconectar a mano quedan en la auditoría, sin tokens', function () {
    Http::fake([
        GoogleOAuth::TOKEN_URL => Http::response(GoogleFakes::tokenResponse()),
        GoogleOAuth::REVOKE_URL => Http::response(),
    ]);

    $this->actingAs($this->user)
        ->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => 'codigo']))
        ->assertRedirect(route('integrations.edit'));

    $this->actingAs($this->user)->delete(route('integrations.google.destroy'))->assertRedirect();

    $activities = integrationActivities();

    expect($activities->pluck('event')->all())->toBe(['google_connected', 'google_disconnected'])
        ->and($activities->pluck('causer_id')->all())->toBe([$this->user->id, $this->user->id])
        ->and($activities->pluck('subject_id')->all())->toBe([$this->user->id, $this->user->id])
        ->and($activities[0]->properties->toArray())->toBe(['google_email' => 'elena@audaxstudio.com'])
        ->and($activities[1]->properties->toArray())->toBe(['google_email' => 'elena@audaxstudio.com', 'reason' => 'manual', 'revoked' => true])
        ->and(json_encode($activities->pluck('properties')))->not->toContain('ya29')
        ->and(json_encode($activities->pluck('properties')))->not->toContain('1//');
});

test('si Google retira el acceso (invalid_grant), la desconexión queda en la auditoría', function () {
    GoogleFakes::connect($this->user, ['expires_at' => now()->subMinute()]);
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => app(GoogleOAuth::class)->accessToken($this->user))->toThrow(GoogleReconnectRequired::class);

    expect(GoogleConnection::query()->count())->toBe(0)
        ->and(integrationActivities()->sole()->properties->toArray())->toBe(['google_email' => 'elena@audaxstudio.com', 'reason' => 'revoked']);
});

test('la auditoría filtra y nombra las entradas de integraciones', function () {
    Queue::fake();
    GoogleFakes::connect($this->user);
    $this->actingAs($this->admin);
    $this->user->forceFill(['is_active' => false])->save();

    $entry = app(AuditEntries::class)->present(integrationActivities())[0];

    expect(AuditCatalog::entityOf('integrations'))->toBe('integration')
        ->and(AuditCatalog::entityLabel('integration'))->toBe('Integraciones')
        ->and(AuditCatalog::ACTIONS['integration_changed'])->toBe(['google_connected', 'google_disconnected', 'google_auto_disconnected'])
        ->and(AuditCatalog::actionLabel('integration_changed'))->toBe('Cuentas de Google conectadas y desconectadas')
        ->and($entry['entity']['label'])->toBe('Integraciones')
        ->and($entry['subject']['label'] ?? null)->toBe('Elena Sale')
        ->and($entry['event_label'])->toBe('Cuenta de Google desconectada automáticamente')
        ->and(collect($entry['changes'])->mapWithKeys(fn (array $change) => [$change['label'] => $change['to']])->all())->toBe([
            'Cuenta de Google' => 'elena@audaxstudio.com',
            'Motivo' => 'Baja de la persona',
        ]);
});
