<?php

namespace App\Domain\Weeklies\Ai;

/** El servicio no responde o falla (429, 5xx, red o tiempo agotado) tras los reintentos. */
final class LlmUnavailable extends LlmException {}
