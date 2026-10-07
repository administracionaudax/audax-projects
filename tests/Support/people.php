<?php

use App\Domain\People\ClockWriter;
use App\Enums\ClockEventKind;
use App\Enums\WorkMode;
use App\Models\ClockEvent;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Utilidades de los tests del registro de jornada (Fase 11). Los instantes se dan en hora de Madrid
| y se fichan con la hora «del servidor» moviendo el reloj (travelTo): igual que en producción,
| ClockWriter nunca recibe la hora.
*/

/** Enciende el módulo `people` (apagado por defecto). */
function enablePeople(): void
{
    Setting::set('modules', [...(array) Setting::get('modules', []), 'people' => true]);
}

/** Instante de Madrid «AAAA-MM-DD HH:MM». */
function madrid(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Madrid');
}

/**
 * Ficha a esa hora de Madrid (mueve el reloj y lo deja ahí).
 */
function punchAt(User $user, string $local, ClockEventKind $kind, ?WorkMode $mode = null): ClockEvent
{
    test()->travelTo(madrid($local));

    return app(ClockWriter::class)->punch($user, $kind, $mode);
}

/**
 * Una jornada entera: entrada, comida (si se da) y salida, horas de Madrid del mismo día salvo que
 * la salida lleve su propia fecha.
 *
 * @return list<ClockEvent>
 */
function workday(User $user, string $date, string $in, string $out, ?string $pauseFrom = null, ?string $pauseTo = null, WorkMode $mode = WorkMode::OnSite): array
{
    $events = [punchAt($user, "{$date} {$in}", ClockEventKind::ClockIn, $mode)];

    if ($pauseFrom !== null && $pauseTo !== null) {
        $events[] = punchAt($user, "{$date} {$pauseFrom}", ClockEventKind::PauseStart);
        $events[] = punchAt($user, "{$date} {$pauseTo}", ClockEventKind::PauseEnd, $mode);
    }

    $events[] = punchAt($user, str_contains($out, ' ') ? $out : "{$date} {$out}", ClockEventKind::ClockOut);

    return $events;
}

/**
 * Un departamento con su responsable y, dentro, las personas que se den.
 *
 * @return array{department: Department, manager: User}
 */
function peopleTeam(User ...$members): array
{
    $department = Department::factory()->create();
    $manager = userWithRole('department_manager', ['department_id' => $department->id]);
    $department->managers()->attach($manager->id);

    foreach ($members as $member) {
        $member->forceFill(['department_id' => $department->id])->save();
    }

    User::forgetMemberships();

    return ['department' => $department, 'manager' => $manager];
}
