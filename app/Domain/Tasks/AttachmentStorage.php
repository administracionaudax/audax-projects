<?php

namespace App\Domain\Tasks;

use App\Models\Attachment;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adjuntos de tareas y comentarios (SPEC §15, D-037):
 * - disco privado `local`, en attachments/{project_id}/{uuid}.{ext}; el nombre original solo en BD,
 * - el tipo real lo decide el servidor (fileinfo), nunca el navegador; la extensión debe casar con él,
 * - miniatura de 400 px (lado mayor) con GD solo de las imágenes rasterizadas previsualizables, con
 *   un límite de 40 megapíxeles y de memoria disponible para no agotar PHP con imágenes enormes,
 * - al borrar se eliminan el fichero y la miniatura (la fila queda en la papelera para la auditoría).
 */
final class AttachmentStorage
{
    public const string DISK = 'local';

    public const int THUMBNAIL_SIZE = 400;

    public const int MAX_THUMBNAIL_PIXELS = 40_000_000;

    /**
     * Archivos por subida.
     */
    public const int MAX_FILES = 10;

    /**
     * Extensiones admitidas y los tipos reales con los que casan. Los formatos de Office y ODF
     * son contenedores ZIP: según la versión de libmagic se detectan como application/zip.
     *
     * @var array<string, list<string>>
     */
    public const array EXTENSIONS = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'svg' => ['image/svg+xml'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain'],
        'md' => ['text/markdown', 'text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    /**
     * Tamaño máximo por archivo en KB (ajuste max_attachment_mb, por defecto 50 MB).
     */
    public static function maxKilobytes(): int
    {
        return self::maxMegabytes() * 1024;
    }

    public static function maxMegabytes(): int
    {
        return max(1, (int) Setting::get('max_attachment_mb', 50));
    }

    /**
     * ¿La extensión del nombre casa con el tipo real detectado?
     */
    public static function extensionMatches(UploadedFile $file): bool
    {
        $extension = self::extensionOf($file);
        $mime = (string) $file->getMimeType();

        return $extension !== null
            && in_array($mime, Attachment::ALLOWED_MIMES, true)
            && in_array($mime, self::EXTENSIONS[$extension] ?? [], true);
    }

    public static function extensionOf(UploadedFile $file): ?string
    {
        $extension = mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        return array_key_exists($extension, self::EXTENSIONS) ? $extension : null;
    }

    public function store(UploadedFile $file, Task|TaskComment $attachable, int $projectId, User $uploader): Attachment
    {
        $extension = self::extensionOf($file) ?? throw new RuntimeException('Extensión no admitida.');
        $mime = (string) $file->getMimeType();
        $uuid = (string) Str::uuid();
        $directory = "attachments/{$projectId}";

        $path = $file->storeAs($directory, "{$uuid}.{$extension}", self::DISK);

        if ($path === false) {
            throw new RuntimeException("No se ha podido guardar el adjunto {$uuid}.");
        }

        $thumbnail = in_array($mime, Attachment::PREVIEWABLE_IMAGES, true)
            ? $this->thumbnail((string) $file->getRealPath(), "{$directory}/thumbs/{$uuid}")
            : null;

        $attachment = new Attachment([
            'project_id' => $projectId,
            'user_id' => $uploader->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => self::cleanName($file->getClientOriginalName()),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'thumbnail_path' => $thumbnail,
        ]);
        $attachment->attachable()->associate($attachable);
        $attachment->save();

        return $attachment;
    }

    public function delete(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete(array_values(array_filter([$attachment->path, $attachment->thumbnail_path])));

        $attachment->delete();
    }

    /**
     * Nombre original seguro para mostrar y para Content-Disposition: sin rutas ni caracteres de
     * control, y como mucho 255 caracteres.
     */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        return $name === '' ? 'archivo' : mb_substr($name, 0, 255);
    }

    /**
     * Miniatura WebP (o PNG si GD no tiene WebP) de 400 px en el lado mayor. Devuelve su ruta, o
     * null si GD no puede con la imagen o sería demasiado grande para la memoria disponible.
     */
    private function thumbnail(string $source, string $pathWithoutExtension): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! is_file($source)) {
            return null;
        }

        $info = @getimagesize($source);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_THUMBNAIL_PIXELS || ! $this->fitsInMemory($width, $height)) {
            return null;
        }

        $contents = @file_get_contents($source);
        $image = $contents === false ? false : @imagecreatefromstring($contents);
        unset($contents);

        if (! $image instanceof GdImage) {
            return null;
        }

        $image = $this->orient($image, $source, $info[2]);
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $ratio = min(1, self::THUMBNAIL_SIZE / max($sourceWidth, $sourceHeight));
        $targetWidth = max(1, (int) round($sourceWidth * $ratio));
        $targetHeight = max(1, (int) round($sourceHeight * $ratio));

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagefill($thumbnail, 0, 0, (int) imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($image);

        $webp = function_exists('imagewebp');

        ob_start();
        $encoded = $webp ? imagewebp($thumbnail, null, 80) : imagepng($thumbnail, null, 6);
        $data = (string) ob_get_clean();
        imagedestroy($thumbnail);

        if (! $encoded || $data === '') {
            return null;
        }

        $path = $pathWithoutExtension.($webp ? '.webp' : '.png');

        return Storage::disk(self::DISK)->put($path, $data) ? $path : null;
    }

    /**
     * Gira la imagen según su EXIF (fotos de móvil), si la extensión exif está disponible.
     */
    private function orient(GdImage $image, string $source, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /**
     * GD descomprime la imagen entera en memoria (unos 5 bytes por píxel con el canal alfa).
     */
    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = $this->memoryLimit();

        if ($limit < 0) {
            return true;
        }

        $needed = $width * $height * 5 + self::THUMBNAIL_SIZE * self::THUMBNAIL_SIZE * 5 + 8 * 1024 * 1024;

        return memory_get_usage(true) + $needed < $limit;
    }

    private function memoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (mb_strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
