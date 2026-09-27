<?php

namespace App\Domain\Notifications;

/**
 * Un evento que avisa (SPEC §13, D-073): su grupo en /ajustes/notificaciones, los canales por los
 * que puede llegar (en el orden en que se envían), los que llegan por defecto, a quién se le ofrece
 * y si es obligatorio (nadie lo puede desactivar: avisos de sistema para el admin).
 */
final readonly class NotificationEvent
{
    /**
     * @param  list<string>  $channels  NotificationCatalog::APP, EMAIL y PUSH que ofrece
     * @param  list<string>  $defaults  subconjunto de $channels activado por defecto
     */
    public function __construct(
        public string $kind,
        public string $group,
        public array $channels,
        public array $defaults,
        public string $audience = NotificationCatalog::AUDIENCE_ALL,
        public bool $mandatory = false,
    ) {}

    public function offers(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    public function enabledByDefault(string $channel): bool
    {
        return in_array($channel, $this->defaults, true);
    }
}
