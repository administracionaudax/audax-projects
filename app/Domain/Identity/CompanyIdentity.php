<?php

namespace App\Domain\Identity;

use App\Models\Setting;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Identidad de la empresa (SPEC §14, D-067): nombre y logo, que se usan en la cabecera del portal,
 * en los emails y en los PDF. Los colores del tema no se cambian desde la app (D-011, D-012).
 *
 * El logo:
 * - se acepta en PNG, JPG o WebP de hasta 1 MB y NUNCA en SVG. El tipo se comprueba con fileinfo
 *   sobre el contenido real, no con la extensión ni con lo que diga el navegador,
 * - se vuelve a codificar con GD en un PNG que cabe en BOX_WIDTH × BOX_HEIGHT (sin ampliar): así
 *   no queda nada del fichero original (metadatos, trozos ajenos a la imagen) y los PDF (incrustado
 *   en su HTML, D-140) y los emails usan siempre el mismo PNG,
 * - se guarda en el disco privado (`local`) y se sirve con una ruta propia y pública,
 *   /marca/logo/{versión} (BrandLogoController): los emails la cargan sin sesión. La versión es el
 *   principio del SHA-1 del PNG, así que cambiar el logo cambia la URL.
 * El ajuste `company_logo` guarda {path, width, height, version}. `company_name` es el mismo
 * ajuste que ya editaba /admin/ajustes.
 */
final class CompanyIdentity
{
    public const string LOGO_SETTING = 'company_logo';

    public const string DISK = 'local';

    public const string DIRECTORY = 'branding';

    /** Tamaño máximo del fichero subido (KB). */
    public const int MAX_KILOBYTES = 1024;

    /** Lado máximo de la imagen original (px): una imagen mayor gastaría demasiada memoria en GD. */
    public const int MAX_SOURCE_SIDE = 3000;

    /** Caja del PNG que se guarda (px): de sobra para la cabecera, los emails y el PDF en alta densidad. */
    public const int BOX_WIDTH = 960;

    public const int BOX_HEIGHT = 240;

    /** Caja del logo en la cabecera de los emails (px). */
    public const int EMAIL_WIDTH = 240;

    public const int EMAIL_HEIGHT = 56;

    /** Tipos admitidos (fileinfo). Nunca image/svg+xml. */
    public const array MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function name(): string
    {
        $name = Setting::get('company_name');

        return is_string($name) && trim($name) !== '' ? $name : (string) Setting::DEFAULTS['company_name'];
    }

    /**
     * El logo guardado, si existe su fichero.
     *
     * @return array{path: string, width: int, height: int, version: string}|null
     */
    public function logo(): ?array
    {
        $value = Setting::get(self::LOGO_SETTING);

        if (! is_array($value)
            || ! is_string($value['path'] ?? null)
            || ! is_string($value['version'] ?? null)
            || ! is_int($value['width'] ?? null)
            || ! is_int($value['height'] ?? null)) {
            return null;
        }

        if (! Storage::disk(self::DISK)->exists($value['path'])) {
            return null;
        }

        return [
            'path' => $value['path'],
            'width' => $value['width'],
            'height' => $value['height'],
            'version' => $value['version'],
        ];
    }

    public function logoUrl(): ?string
    {
        $logo = $this->logo();

        return $logo === null ? null : route('brand.logo', ['version' => $logo['version']]);
    }

    /**
     * Ruta absoluta del PNG (para el PDF), o null si no hay logo.
     */
    public function logoPath(): ?string
    {
        $logo = $this->logo();

        return $logo === null ? null : Storage::disk(self::DISK)->path($logo['path']);
    }

    /**
     * Nombre y logo para la cabecera del portal (props compartidas del portal).
     *
     * @return array{name: string, logo: array{url: string, width: int, height: int}|null}
     */
    public function forPortal(): array
    {
        $logo = $this->logo();

        return [
            'name' => $this->name(),
            'logo' => $logo === null ? null : [
                'url' => route('brand.logo', ['version' => $logo['version']]),
                'width' => $logo['width'],
                'height' => $logo['height'],
            ],
        ];
    }

    /**
     * Logo para la cabecera de los emails, con el tamaño en el que se pinta (Outlook necesita
     * width y height explícitos), o null si no hay logo (la cabecera sigue con el texto).
     *
     * @return array{name: string, url: string, width: int, height: int}|null
     */
    public function forEmail(): ?array
    {
        $logo = $this->logo();

        if ($logo === null) {
            return null;
        }

        [$width, $height] = self::fit($logo['width'], $logo['height'], self::EMAIL_WIDTH, self::EMAIL_HEIGHT);

        return [
            'name' => $this->name(),
            'url' => route('brand.logo', ['version' => $logo['version']]),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Guarda el nombre y, si llega, el logo nuevo (y borra el anterior).
     *
     * @throws ValidationException
     */
    public function update(string $name, ?UploadedFile $logo): void
    {
        $previous = $this->logo();
        $stored = $logo === null ? null : $this->process($logo);

        DB::transaction(function () use ($name, $stored): void {
            if (Setting::get('company_name') !== $name) {
                Setting::set('company_name', $name);
            }

            if ($stored !== null) {
                Setting::set(self::LOGO_SETTING, $stored);
            }
        });

        // El PNG anterior ya no lo usa nadie (el nombre lleva su hash: si es el mismo, se conserva).
        if ($stored !== null && $previous !== null && $previous['path'] !== $stored['path']) {
            Storage::disk(self::DISK)->delete($previous['path']);
        }
    }

    /**
     * Quita el logo: el portal, los emails y el PDF vuelven al logotipo de Audax.
     */
    public function removeLogo(): void
    {
        $previous = $this->logo();

        // Borrado del modelo (no de la consulta): así salta Setting::deleted y se vacía la caché.
        Setting::query()->where('key', self::LOGO_SETTING)->first()?->delete();

        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous['path']);
        }
    }

    /**
     * Comprueba el tipo real y las dimensiones, lo reduce para que quepa en la caja y lo guarda
     * como PNG con transparencia.
     *
     * @return array{path: string, width: int, height: int, version: string}
     *
     * @throws ValidationException
     */
    private function process(UploadedFile $file): array
    {
        $source = $file->getRealPath();

        if (! is_string($source) || $source === '' || ! is_file($source)) {
            throw ValidationException::withMessages(['logo' => __('portal.identity.errors.logo_unreadable')]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);

        if (! is_string($mime) || ! in_array($mime, self::MIME_TYPES, true)) {
            throw ValidationException::withMessages(['logo' => __('portal.identity.errors.logo_type')]);
        }

        $info = @getimagesize($source);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ValidationException::withMessages(['logo' => __('portal.identity.errors.logo_unreadable')]);
        }

        [$width, $height] = $info;

        if ($width > self::MAX_SOURCE_SIDE || $height > self::MAX_SOURCE_SIDE) {
            throw ValidationException::withMessages(['logo' => __('portal.identity.errors.logo_dimensions', ['max' => number_format(self::MAX_SOURCE_SIDE, 0, ',', '.')])]);
        }

        $image = $this->decode($source, $mime);

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages(['logo' => __('portal.identity.errors.logo_unreadable')]);
        }

        [$targetWidth, $targetHeight] = self::fit(imagesx($image), imagesy($image), self::BOX_WIDTH, self::BOX_HEIGHT);

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($image), imagesy($image));
        imagedestroy($image);

        ob_start();
        $encoded = imagepng($target, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($target);

        if (! $encoded || $png === '') {
            throw ValidationException::withMessages(['logo' => __('portal.identity.errors.logo_unreadable')]);
        }

        $version = substr(sha1($png), 0, 12);
        $path = self::DIRECTORY."/logo-{$version}.png";

        if (! Storage::disk(self::DISK)->put($path, $png)) {
            throw ValidationException::withMessages(['logo' => __('portal.identity.errors.logo_unreadable')]);
        }

        return ['path' => $path, 'width' => $targetWidth, 'height' => $targetHeight, 'version' => $version];
    }

    /**
     * Decodifica con el lector de su tipo. Las imágenes con paleta pasan a color verdadero para
     * conservar la transparencia al reducirlas.
     */
    private function decode(string $source, string $mime): ?GdImage
    {
        $image = match ($mime) {
            'image/png' => @imagecreatefrompng($source),
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };

        if (! $image instanceof GdImage) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);

        return $image;
    }

    /**
     * Tamaño que cabe en la caja sin deformar ni ampliar.
     *
     * @return array{0: int<1, max>, 1: int<1, max>}
     */
    public static function fit(int $width, int $height, int $boxWidth, int $boxHeight): array
    {
        $ratio = min(1, $boxWidth / max($width, 1), $boxHeight / max($height, 1));

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }
}
