<?php

namespace App\Domain\Weeklies\Assistant;

use RuntimeException;

/**
 * La persona ya tiene una pregunta al asistente sin responder (D-222): una a la vez.
 */
final class AssistantBusy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('weeklies.assistant.busy'));
    }
}
