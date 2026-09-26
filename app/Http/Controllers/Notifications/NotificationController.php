<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notificaciones en la app (SPEC §13): campana de la cabecera y /notificaciones. En la Fase 1 la
 * campana consulta cada 60 s (D-037); el tiempo real llega con Reverb en la Fase 6.
 * Cada usuario solo ve y marca las suyas (se buscan siempre en $user->notifications()).
 */
class NotificationController extends Controller
{
    public const int PER_PAGE = 30;

    public const int RECENT = 8;

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filter = $request->query('filtro') === 'sin-leer' ? 'unread' : 'all';

        $notifications = ($filter === 'unread' ? $user->unreadNotifications() : $user->notifications())
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('notifications/index', [
            // «items» y no «notifications»: esa clave es la prop compartida con el recuento de la campana.
            'items' => NotificationResource::collection($notifications),
            'filter' => $filter,
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Últimas notificaciones y recuento sin leer (JSON para la campana).
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'notifications' => NotificationResource::collection($user->notifications()->latest()->limit(self::RECENT)->get())->resolve($request),
        ]);
    }

    /**
     * Marca como leída y lleva a su destino (solo rutas de la app).
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $record = $this->find($request, $notification);
        $record->markAsRead();

        /** @var array<string, mixed> $data */
        $data = (array) $record->data;
        $url = NotificationResource::safeUrl($data['url'] ?? null);

        return $url !== null ? redirect($url) : redirect()->route('notifications.index');
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    private function find(Request $request, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification */
        return $this->user($request)->notifications()->whereKey($id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
