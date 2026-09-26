<?php

use App\Http\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/*
| PERF-02: los Resources con datos económicos evalúan la gate view-financials una sola vez por
| petición (y persona), no una vez por fila. Sigue ocultando los importes a quien no puede verlos.
*/

function financialRequest(User $user): Request
{
    $request = Request::create('/horas/aprobaciones');
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('evalúa view-financials una vez por petición aunque serialice muchas filas', function () {
    $entries = TimeEntry::factory()->count(30)->create(['hourly_rate_snapshot' => '60.00', 'hourly_cost_snapshot' => '25.00']);
    $checks = 0;
    Gate::after(function (User $user, string $ability) use (&$checks): void {
        if ($ability === 'view-financials') {
            $checks++;
        }
    });

    $admin = userWithRole('admin');
    $rows = TimeEntryResource::collection($entries)->resolve(financialRequest($admin));

    expect($checks)->toBe(1)
        ->and($rows)->toHaveCount(30)
        ->and($rows[0]['hourly_rate_snapshot'])->toBe('60.00');

    // Otra petición (otra persona) vuelve a evaluarla y no ve los importes.
    $employee = userWithRole('employee');
    $rows = TimeEntryResource::collection($entries)->resolve(financialRequest($employee));

    expect($checks)->toBe(2)
        ->and($rows[0])->not->toHaveKey('hourly_rate_snapshot')
        ->and($rows[0])->not->toHaveKey('hourly_cost_snapshot');
});
