<?php

namespace App\Domain\Reports\Pdf;

final class GotenbergEngine implements PdfEngine
{
    public function __construct(private readonly Gotenberg $gotenberg) {}

    public function render(string $html): string
    {
        return $this->gotenberg->convertHtml($html);
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function mime(): string
    {
        return 'application/pdf';
    }
}
