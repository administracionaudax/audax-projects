<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Contrato: resources/js/types/domain.ts (AppNotification). Solo notificaciones del canal database
 * con el formato de App\Notifications\AppNotification.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->data;

        return [
            'id' => $this->id,
            'data' => [
                'kind' => (string) ($data['kind'] ?? ''),
                'title' => (string) ($data['title'] ?? ''),
                'body' => isset($data['body']) ? (string) $data['body'] : null,
                'url' => self::safeUrl($data['url'] ?? null),
                'icon' => isset($data['icon']) ? (string) $data['icon'] : null,
            ],
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Solo rutas relativas de la propia app ("/proyectos/3"): nunca URLs externas (redirección abierta).
     */
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || ! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return null;
        }

        return $url;
    }
}
