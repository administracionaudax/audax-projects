<?php

namespace App\Domain\Users;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La foto de perfil (F-029, 10.9b y D-234). La interfaz recorta y reduce la imagen en el navegador;
 * el servidor no se fía: la vuelve a decodificar con GD, la recorta al cuadrado centrado si hace
 * falta, la deja en SIZE píxeles y la guarda como WebP (o PNG si GD no tiene WebP). Al recodificar
 * se pierden los metadatos (EXIF con la ubicación, el móvil…), que nunca llegan a guardarse.
 *
 * Se guarda en el disco privado (`avatars/{id}-{aleatorio}.webp`): solo se sirve con una URL
 * firmada (User::avatar_url, SPEC §15). Cambiarla o quitarla borra la anterior.
 */
final class AvatarStorage
{
    public const string DISK = 'local';

    public const string DIRECTORY = 'avatars';

    /** Lado de la imagen guardada, en píxeles. */
    public const int SIZE = 256;

    /** Megapíxeles como mucho de la imagen que se sube (para la memoria de GD). */
    public const int MAX_PIXELS = 40_000_000;

    public function store(User $user, UploadedFile $file): string
    {
        $source = $file->getRealPath();
        $info = $source === false ? false : @getimagesize($source);

        if ($source === false || $info === false || ! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('avatar_unreadable');
        }

        [$width, $height, $type] = $info;

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('avatar_unreadable');
        }

        $reader = match ($type) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            default => null,
        };
        $image = $reader !== null && function_exists($reader) ? @$reader($source) : false;

        if (! $image instanceof GdImage) {
            throw new RuntimeException('avatar_unreadable');
        }

        $image = $this->orient($image, $source, $type);
        $side = min(imagesx($image), imagesy($image));
        $x = intdiv(imagesx($image) - $side, 2);
        $y = intdiv(imagesy($image) - $side, 2);

        $avatar = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($avatar, false);
        imagesavealpha($avatar, true);
        imagefill($avatar, 0, 0, (int) imagecolorallocatealpha($avatar, 255, 255, 255, 127));
        imagecopyresampled($avatar, $image, 0, 0, $x, $y, self::SIZE, self::SIZE, $side, $side);
        imagedestroy($image);

        $webp = function_exists('imagewebp');
        ob_start();
        $encoded = $webp ? imagewebp($avatar, null, 85) : imagepng($avatar, null, 6);
        $data = (string) ob_get_clean();
        imagedestroy($avatar);

        if (! $encoded || $data === '') {
            throw new RuntimeException('avatar_unreadable');
        }

        $path = self::DIRECTORY.'/'.$user->id.'-'.Str::lower(Str::random(16)).($webp ? '.webp' : '.png');
        Storage::disk(self::DISK)->put($path, $data);

        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $path;
    }

    public function remove(User $user): void
    {
        if ($user->avatar_path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();
    }

    /** Gira según el EXIF de una foto de móvil (antes de recortar). */
    private function orient(GdImage $image, string $source, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $angle = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
