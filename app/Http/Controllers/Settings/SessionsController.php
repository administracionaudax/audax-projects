<?php

namespace App\Http\Controllers\Settings;

use App\Auth\SessionTerminator;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Sesiones activas del usuario (SPEC §15). Requiere el driver de sesión "database".
 * Props de la página settings/sessions:
 *   sessions: [{id, ip_address, user_agent, browser, platform, is_current, last_active_at (ISO)}]
 *   supported: bool (false si el driver de sesión no es "database")
 *
 * El "id" que ve el navegador es un HMAC del identificador real: el identificador de sesión es
 * el valor de la cookie y nunca debe llegar al HTML.
 */
class SessionsController extends Controller
{
    public function __construct(private readonly SessionTerminator $terminator) {}

    public function index(Request $request): Response
    {
        $currentId = $request->session()->getId();

        $sessions = $this->userSessions($request)->map(function (array $row) use ($currentId): array {
            $agent = UserAgent::summarize($row['user_agent']);

            return [
                'id' => $this->publicId($row['id']),
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'] !== null ? Str::limit($row['user_agent'], 255) : null,
                'browser' => $agent['browser'],
                'platform' => $agent['platform'],
                'is_current' => hash_equals($row['id'], $currentId),
                'last_active_at' => Carbon::createFromTimestamp($row['last_activity'])->toIso8601String(),
            ];
        })->sortByDesc(fn (array $session): string => ($session['is_current'] ? '1' : '0').$session['last_active_at'])
            ->values()
            ->all();

        return Inertia::render('settings/sessions', [
            'sessions' => $sessions,
            'supported' => $this->terminator->supported(),
        ]);
    }

    public function destroy(Request $request, string $session): RedirectResponse
    {
        if (! $this->terminator->supported()) {
            return $this->unsupported();
        }

        $row = $this->userSessions($request)
            ->first(fn (array $row): bool => hash_equals($this->publicId($row['id']), $session));

        abort_if($row === null, 404);

        if (hash_equals($row['id'], $request->session()->getId())) {
            return back()->withErrors(['session' => __('app.sessions.cannot_close_current')]);
        }

        /** @var User $user */
        $user = $request->user();

        $this->terminator->destroy($request, $user, $row['id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.sessions.closed')]);

        return back();
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        if (! $this->terminator->supported()) {
            return $this->unsupported();
        }

        /** @var User $user */
        $user = $request->user();

        $this->terminator->destroyOthers($request, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.sessions.others_closed')]);

        return back();
    }

    /**
     * Sin el driver "database" no se puede cerrar ninguna sesión: se dice, en vez de dar un falso éxito.
     */
    private function unsupported(): RedirectResponse
    {
        $message = __('app.sessions.unsupported');

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back()->withErrors(['session' => $message]);
    }

    /**
     * @return Collection<int, array{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}>
     */
    private function userSessions(Request $request): Collection
    {
        if (! $this->terminator->supported()) {
            return collect();
        }

        return DB::table($this->terminator->table())
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'ip_address' => isset($row->ip_address) ? (string) $row->ip_address : null,
                'user_agent' => isset($row->user_agent) ? (string) $row->user_agent : null,
                'last_activity' => (int) $row->last_activity,
            ]);
    }

    private function publicId(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 40);
    }
}
