<?php

namespace App\Domain\Chat\Transcription;

use RuntimeException;

/**
 * El motor no ha podido transcribir el audio (caído, tiempo agotado, audio ilegible…). El job lo
 * reintenta y, si sigue fallando, la revisión periódica avisa al admin.
 */
final class TranscriptionFailed extends RuntimeException {}
