<?php

namespace App\Domain\Weeklies;

use App\Enums\AppModule;
use App\Http\Resources\Weeklies\WeeklyCycleResource;
use App\Models\User;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Tarjeta «Weekly» de Inicio (F-030 a F-032, F-036, F-039 y F-040; D-138): el estado de mi weekly de
 * la semana activa con su botón, mi racha y, si estoy exento, el aviso de que la racha no se rompe.
 * Quien gestiona ve además el progreso del equipo y quién falta, o «Iniciar la semana» si no hay
 * ninguna activa. Null para quien no escribe weeklies o con el módulo apagado (la tarjeta no sale).
 */
final class HomeWeeklyCard
{
    public const int PENDING_LIMIT = 8;

    public function __construct(
        private readonly MyWeeklyStatus $status,
        private readonly WeeklyStreaks $streaks,
        private readonly WeeklyTeamStatus $team,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(User $user, ?CarbonInterface $now = null): ?array
    {
        if (! $user->writesWeeklies() || $user->isCollaborator() || ! AppModules::enabled(AppModule::Weeklies)) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $cycle = WeeklyCycle::query()->active()->first();
        $manages = Gate::forUser($user)->allows('manage-weeklies');
        $team = null;

        if ($cycle !== null && $manages) {
            $snapshot = $this->team->for($cycle, $now);
            $pending = array_values(array_filter($snapshot['members'], fn (array $member): bool => MyWeeklyStatus::isPending($member['status'])));
            $team = [
                'counts' => $snapshot['counts'],
                'pending' => array_slice(array_map(fn (array $member): array => $member['user'], $pending), 0, self::PENDING_LIMIT),
            ];
        }

        return [
            'cycle' => $cycle === null ? null : (new WeeklyCycleResource($cycle))->resolve(),
            'me' => $cycle === null ? null : $this->status->for($user, $cycle, $now),
            'streak' => $this->streaks->summary($user, $now),
            'team' => $team,
            'can' => [
                'manage' => $manages,
                'open' => $cycle === null && Gate::forUser($user)->allows('create', WeeklyCycle::class),
            ],
        ];
    }
}
