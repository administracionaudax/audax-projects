<?php

use App\Domain\People\RegisterIntegrity;
use App\Enums\MonthCloseStatus;
use App\Models\EmploymentProfile;
use App\Models\MonthClose;
use App\Models\OvertimeDecision;
use App\Models\PeopleDocument;
use App\Models\RegisterAnchor;
use App\Models\TimeBalanceMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

/*
| Datos de ejemplo del registro de R2 (D-359; solo local, tests y CI): el mes anterior cerrado y
| clasificado, horas extra y saldos, documentos y el ancla, con la cadena íntegra.
*/

it('el seeder de desarrollo deja el mes anterior cerrado, horas extra, saldos, documentos y el ancla', function () {
    Storage::fake('local');
    $this->travelTo(madridAt('2026-10-07 12:00'));
    $this->seed(DatabaseSeeder::class);

    $user = fn (string $email): User => User::query()->where('email', $email)->sole();
    $close = fn (string $email): MonthClose => MonthClose::query()->current()->where('user_id', $user($email)->id)->where('month', '2026-09-01')->sole();

    expect($close('empleado@example.com')->status)->toBe(MonthCloseStatus::Pending)
        ->and($close('daniel.ortega@example.com')->status)->toBe(MonthCloseStatus::Pending)
        ->and($close('lucia.martin@example.com')->status)->toBe(MonthCloseStatus::Disagreed)
        ->and($close('pablo.ruiz@example.com')->status)->toBe(MonthCloseStatus::Confirmed)
        ->and(MonthClose::query()->whereNotNull('generated_by')->count())->toBe(0)
        ->and(MonthClose::query()->where('user_id', $user('sara.colaboradora@example.com')->id)->exists())->toBeFalse();

    expect(OvertimeDecision::query()->where('date', '<', '2026-10-01')->count())->toBeGreaterThan(5)
        ->and(OvertimeDecision::query()->where('user_id', $user('pablo.ruiz@example.com')->id)->where('overtime_minutes', '>', 0)->exists())->toBeTrue()
        ->and(TimeBalanceMovement::query()->where('kind', 'opening_balance')->where('user_id', $user('sergio.gomez@example.com')->id)->value('minutes'))->toBe(300)
        ->and(EmploymentProfile::query()->where('user_id', $user('irene.castro@example.com')->id)->value('part_time'))->toBeTrue()
        ->and(PeopleDocument::query()->count())->toBe(2)
        ->and(RegisterAnchor::query()->count())->toBe(1)
        ->and(app(RegisterIntegrity::class)->verify()['ok'])->toBeTrue();
});
