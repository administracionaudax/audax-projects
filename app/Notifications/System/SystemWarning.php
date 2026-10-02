<?php

namespace App\Notifications\System;

use App\Notifications\AppNotification;

/**
 * Base de los avisos de sistema al admin (D-076): un motivo (reason) con sus datos para el texto
 * de lang/es/system.php ({grupo}.{motivo}.title|body). Son eventos obligatorios del catálogo: el
 * admin no los puede desactivar. app:check-storage envía como mucho uno al día por motivo.
 */
abstract class SystemWarning extends AppNotification
{
    /**
     * @param  array<string, string|int>  $details
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $details = [],
    ) {}

    /** Grupo de textos en lang/es/system.php. */
    abstract protected function group(): string;

    public function title(object $notifiable): string
    {
        return $this->text('title');
    }

    public function body(object $notifiable): ?string
    {
        return $this->text('body');
    }

    public function icon(): ?string
    {
        return 'triangle-alert';
    }

    private function text(string $field): string
    {
        $key = "system.{$this->group()}.{$this->reason}.{$field}";
        $text = __($key, $this->details);

        return is_string($text) ? $text : $key;
    }
}
