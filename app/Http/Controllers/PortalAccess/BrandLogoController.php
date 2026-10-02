<?php

namespace App\Http\Controllers\PortalAccess;

use App\Domain\Identity\CompanyIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logo de la empresa (D-067): público, porque lo cargan los emails, y sin sesión ni cookies (la ruta
 * va fuera del grupo web). Es siempre el PNG que ha vuelto a codificar CompanyIdentity, nunca el
 * fichero subido. Con la versión actual en la URL se guarda en caché un año; una versión antigua
 * (un email de antes de cambiarlo) recibe el logo actual con una caché corta.
 */
class BrandLogoController extends Controller
{
    public function __invoke(string $version, CompanyIdentity $identity): StreamedResponse
    {
        $logo = $identity->logo();

        abort_if($logo === null, 404);

        return Storage::disk(CompanyIdentity::DISK)->response($logo['path'], 'logo.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => hash_equals($logo['version'], $version) ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}
