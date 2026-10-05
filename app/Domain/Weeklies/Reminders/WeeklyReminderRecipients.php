<?php

namespace App\Domain\Weeklies\Reminders;

use App\Domain\Weeklies\WeeklyEligibility;
use App\Enums\Role;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * A quién va cada aviso de la weekly (F-106, D-199):
 *
 * - pending(): los recordatorios (por reglas, manuales y «Recordar») y el plazo cambiado, solo a
 *   quien DEBE enviar la semana activa y aún no la ha enviado: internos activos de plantilla
 *   (nunca colaboradores externos ni clientes), dados de alta a tiempo, sin exención (manual o por
 *   una ausencia aprobada que cubre el plazo) ni renuncia pendiente (WeeklyEligibility). Un
 *   borrador sin enviar cuenta como pendiente. WeeklySync no miraba las exenciones (D-145): aquí
 *   un exento nunca recibe un recordatorio.
 * - team(): «weekly cerrada» (F-095), a todo el equipo activo que escribe la weekly, como el
 *   `all_active` del original.
 *
 * Consultas acotadas: la foto (3), los envíos y las personas.
 */
final class WeeklyReminderRecipients
{
    public function __construct(private readonly WeeklyEligibility $eligibility) {}

    /**
     * @param  list<int>|null  $onlyIds  limita a estas personas (envío manual o «Recordar»)
     * @return Collection<int, User> por nombre
     */
    public function pending(WeeklyCycle $cycle, ?array $onlyIds = null): Collection
    {
        if (! $cycle->isActive()) {
            return new Collection;
        }

        $expected = $this->eligibility->rosterFor($cycle)->expected();

        if ($onlyIds !== null) {
            $expected = array_values(array_intersect($expected, array_map(intval(...), $onlyIds)));
        }

        if ($expected === []) {
            return new Collection;
        }

        $submitted = WeeklySubmission::query()
            ->where('weekly_cycle_id', $cycle->id)
            ->whereNotNull('submitted_at')
            ->whereIn('user_id', $expected)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $ids = array_values(array_diff($expected, $submitted));

        if ($ids === []) {
            return new Collection;
        }

        return User::query()->whereKey($ids)->where('is_active', true)->orderBy('name')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, User> por nombre
     */
    public function team(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', User::WEEKLY_ROLES))
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', [Role::Collaborator->value, Role::Client->value]))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
