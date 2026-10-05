<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
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
