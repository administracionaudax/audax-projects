<?php

use App\Domain\Chat\ConversationDirectory;
use App\Models\Project;
use App\Models\User;
use Minishlink\WebPush\VAPID;

/*
| Matriz de acceso de las rutas de tiempo real y avisos (D-068, D-071): invitado, cliente, persona
| desactivada, participante, no participante, responsable (sin participar) y admin, también en
| una conversación directa. Las que dependen de una conversación siguen la política de verla.
*/

beforeEach(function () {
    $keys = VAPID::createVapidKeys();
    config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey']]);

    $directory = app(ConversationDirectory::class);
    $this->ana = User::factory()->employee()->create();
    $this->luis = User::factory()->employee()->create();
    $project = Project::factory()->create();
    $project->addMember($this->ana);
    $this->chat = $directory->forProject($project);
    $this->dm = $directory->direct($this->ana, $this->luis);

    $this->personal = [
        ['GET', '/tiempo-real/no-leidos', []],
        ['POST', '/tiempo-real/presencia', ['status' => 'online']],
        ['GET', '/avisos-navegador', []],
        ['DELETE', '/avisos-navegador/suscripciones', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x']],
    ];
    $this->conversational = fn ($conversation): array => [
        ['POST', "/tiempo-real/conversaciones/{$conversation->id}/viendo", []],
        ['GET', "/tiempo-real/conversaciones/{$conversation->id}/leidos", []],
        ['GET', "/tiempo-real/conversaciones/{$conversation->id}/abrir", []],
    ];
    $this->statuses = function (?User $user, array $routes): array {
        $statuses = [];
        foreach ($routes as [$method, $uri, $data]) {
            $request = $user === null ? $this : $this->actingAs($user);
            $statuses["{$method} {$uri}"] = $request->json($method, $uri, $data)->getStatusCode();
        }

        return $statuses;
    };
});

it('sin sesión, todo pide iniciarla', function () {
    $routes = [...$this->personal, ...($this->conversational)($this->chat), ['POST', '/avisos-navegador/suscripciones', []]];

    expect(array_values(array_unique(($this->statuses)(null, $routes))))->toBe([401]);
});

it('un cliente o una persona desactivada no llegan a ninguna', function () {
    $routes = [...$this->personal, ...($this->conversational)($this->chat)];
    $inactive = User::factory()->employee()->create(['is_active' => false]);

    expect(array_values(array_unique(($this->statuses)(User::factory()->client()->create(), $routes))))->toBe([403])
        ->and(array_values(array_unique(($this->statuses)($inactive, $routes))))->toBe([401]);
});

it('cualquier interno usa las rutas personales (solo con sus propios datos)', function () {
    $manager = User::factory()->departmentManager()->create();

    foreach ([$this->ana, $manager, User::factory()->admin()->create()] as $user) {
        expect(array_filter(($this->statuses)($user, $this->personal), fn (int $status): bool => $status >= 400))->toBe([]);
    }
});

it('las de una conversación siguen la política de verla (D-071)', function () {
    $ok = fn (array $statuses): bool => collect($statuses)->every(fn (int $status): bool => $status < 400);
    $forbidden = fn (array $statuses): bool => collect($statuses)->every(fn (int $status): bool => $status === 403);
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->departmentManager()->create();
    $outsider = User::factory()->employee()->create();

    expect($ok(($this->statuses)($this->ana, ($this->conversational)($this->chat))))->toBeTrue('participante en el proyecto')
        ->and($ok(($this->statuses)($this->luis, ($this->conversational)($this->dm))))->toBeTrue('participante en la directa')
        ->and($forbidden(($this->statuses)($outsider, ($this->conversational)($this->chat))))->toBeTrue('no participante')
        ->and($forbidden(($this->statuses)($manager, ($this->conversational)($this->chat))))->toBeTrue('responsable que no participa')
        ->and($ok(($this->statuses)($admin, ($this->conversational)($this->chat))))->toBeTrue('admin en un proyecto')
        ->and($forbidden(($this->statuses)($admin, ($this->conversational)($this->dm))))->toBeTrue('admin en una directa');
});
