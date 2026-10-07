<?php

namespace App\Domain\People\Reports;

use App\Domain\Reports\Delivery\ExportFormat;

/**
 * Un fichero del registro ya escrito en un temporal (R2, D-351), con su huella del fichero y la del
 * contenido. Quien lo pide lo borra al enviarlo.
 */
final readonly class RegisterFile
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mime,
        public ?ExportFormat $format,
        public string $sha256,
        public string $contentHash,
        public string $title,
    ) {}
}
