<?php

namespace App\Http\Controllers\People;

use App\Domain\People\ClockState;
use App\Domain\People\ClockWriter;
use App\Domain\People\PeopleAccess;
use App\Enums\ClockEventKind;
use App\Enums\ClockSource;
use App\Enums\WorkMode;
use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * POST /fichar (D-333): la propia persona ficha la entrada, la pausa de la comida, la vuelta o la
 * salida, con la hora del servidor (ClockWriter). El navegador solo dice QUÉ ficha y el modo; nunca
 * la hora. Al fichar la salida se devuelve, solo a ella, lo trabajado y lo imputado hoy para
 * sugerirle imputar lo que falte (PLAN-FASE-11 §3.2.5; nunca se imputa ni se ficha solo).
 */
class ClockController extends Controller
{
    public function store(Request $request, ClockWriter $writer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(PeopleAccess::clocks($user), 403);

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(ClockEventKind::punchValues())],
            'work_mode' => ['nullable', 'string', Rule::enum(WorkMode::class)],
            'source' => ['nullable', 'string', Rule::in([ClockSource::Web->value, ClockSource::Pwa->value])],
        ]);

        $kind = ClockEventKind::from($data['kind']);
        $event = $writer->punch(
            $user,
            $kind,
            isset($data['work_mode']) ? WorkMode::from($data['work_mode']) : null,
            ClockSource::tryFrom((string) ($data['source'] ?? '')) ?? ClockSource::Web,
            $request->ip(),
            $request->userAgent(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __("people.flash.{$kind->value}", ['time' => $event->occurred_at->setTimezone(LocalTime::timezone())->format('H:i')]),
        ]);

        if ($kind === ClockEventKind::ClockOut) {
            $state = ClockState::of($user);
            Inertia::flash('clock_summary', [
                'date' => LocalTime::todayString(),
                'worked_minutes' => intdiv($state->workedTodaySeconds() + 30, 60),
                'logged_minutes' => (int) TimeEntry::query()->where('user_id', $user->id)->whereDate('date', LocalTime::todayString())->sum('minutes'),
            ]);
        }

        return back();
    }
}
