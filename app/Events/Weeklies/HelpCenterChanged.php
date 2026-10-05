<?php

namespace App\Events\Weeklies;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Algo ha cambiado en el centro de ayuda o en las sugerencias (F-170, «tiempo real» de WeeklySync,
 * que escuchaba los cambios de sus tablas con Supabase Realtime). Va por el canal privado `help`
 * (quien usa la ayuda) como `help.changed`, sin contenido: la página vuelve a pedir sus datos, con
 * sus permisos. Sale al terminar la transacción y, si Reverb no está, no rompe la petición
 * (ShouldRescue): la página se pone al día al volver a ella (D-184).
 *
 * @phpstan-type Scope 'help'|'suggestions'
 */
final class HelpCenterChanged implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public bool $afterCommit = true;

    /**
     * @param  'help'|'suggestions'  $scope
     */
    public function __construct(
        public readonly string $scope,
        public readonly ?int $postId = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('help')];
    }

    public function broadcastAs(): string
    {
        return 'help.changed';
    }

    /**
     * @return array{scope: string, post_id: int|null}
     */
    public function broadcastWith(): array
    {
        return ['scope' => $this->scope, 'post_id' => $this->postId];
    }
}
