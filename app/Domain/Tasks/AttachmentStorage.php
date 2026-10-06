<?php

namespace App\Domain\Tasks;

use App\Domain\Tasks\Jobs\GenerateAttachmentThumbnail;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Setting;
use App\Models\SuggestionComment;
use App\Models\SuggestionPost;
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
 * - miniatura de 400 px (lado mayor) solo de las imágenes rasterizadas previsualizables, en un job
 *   de Horizon (GenerateAttachmentThumbnail) y nunca en la petición web: el pool FPM no tiene cgroup
 *   y la libgd del sistema reserva memoria fuera de memory_limit (RUNBOOK-DESPLIEGUE). Aun así,
 *   el job solo decodifica imágenes de hasta 40 megapíxeles y dentro de un presupuesto de memoria,
 * - al borrar se eliminan el fichero y la miniatura (la fila queda en la papelera para la auditoría).
 */
final class AttachmentStorage
{
    public const string DISK = 'local';

    public const int THUMBNAIL_SIZE = 400;

    public const int MAX_THUMBNAIL_PIXELS = 40_000_000;

    /**
     * Memoria máxima que puede necesitar una miniatura, independiente de memory_limit (la libgd del
     * sistema no la cuenta). Con dos workers de Horizon a la vez cabe en el MemoryLimit de su unidad.
     */
    public const int MAX_THUMBNAIL_MEMORY = 192 * 1024 * 1024;

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
     * Vídeos de las sugerencias (D-235): extensión → tipos reales admitidos.
     *
     * @var array<string, list<string>>
     */
    public const array VIDEO_EXTENSIONS = [
        'mp4' => ['video/mp4', 'application/mp4'],
        'm4v' => ['video/mp4', 'application/mp4'],
        'mov' => ['video/quicktime'],
        'webm' => ['video/webm'],
    ];

    /**
     * Audios del chat (Fase 6): extensión → tipos reales admitidos. Solo para mensajes de audio.
     *
     * @var array<string, list<string>>
     */
    public const array AUDIO_EXTENSIONS = [
        'webm' => ['audio/webm', 'video/webm'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'oga' => ['audio/ogg', 'application/ogg'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'mp4' => ['audio/mp4', 'video/mp4'],
        'mp3' => ['audio/mpeg'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
    ];

    /**
     * ¿Es un audio admitido y su extensión casa con el tipo real?
     */
    public static function audioMatches(UploadedFile $file): bool
    {
        $extension = mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        return in_array((string) $file->getMimeType(), self::AUDIO_EXTENSIONS[$extension] ?? [], true);
    }

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
    public static function extensionMatches(UploadedFile $file, bool $videos = false): bool
    {
        $extension = self::extensionOf($file, $videos);
        $mime = (string) $file->getMimeType();
        $map = $videos ? [...self::EXTENSIONS, ...self::VIDEO_EXTENSIONS] : self::EXTENSIONS;

        return $extension !== null
            && in_array($mime, self::allowedMimes($videos), true)
            && in_array($mime, $map[$extension] ?? [], true);
    }

    public static function extensionOf(UploadedFile $file, bool $videos = false): ?string
    {
        $extension = mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        return array_key_exists($extension, self::EXTENSIONS) || ($videos && array_key_exists($extension, self::VIDEO_EXTENSIONS)) ? $extension : null;
    }

    /**
     * Tipos admitidos: los de siempre y, en las sugerencias, también los vídeos (D-235).
     *
     * @return list<string>
     */
    public static function allowedMimes(bool $videos = false): array
    {
        return $videos ? [...Attachment::ALLOWED_MIMES, ...Attachment::SUGGESTION_VIDEO_MIMES] : Attachment::ALLOWED_MIMES;
    }

    /**
     * @param  int|null  $projectId  null en conversaciones sin proyecto (directas y de grupo) y en las
     *                               sugerencias del centro de ayuda (Fase 10, F-161 y F-165)
     */
    public function store(UploadedFile $file, Task|TaskComment|Message|SuggestionPost|SuggestionComment $attachable, ?int $projectId, User $uploader, bool $audio = false): Attachment
    {
        // Los vídeos solo en las sugerencias y sus comentarios (D-235).
        $videos = $attachable instanceof SuggestionPost || $attachable instanceof SuggestionComment;
        $extension = $audio
            ? (self::audioMatches($file) ? mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)) : throw new RuntimeException('Audio no admitido.'))
            : (self::extensionOf($file, $videos) ?? throw new RuntimeException('Extensión no admitida.'));
        $mime = (string) $file->getMimeType();
        $uuid = (string) Str::uuid();
        $directory = match (true) {
            $projectId !== null => "attachments/{$projectId}",
            $attachable instanceof Message => "attachments/chat/{$attachable->conversation_id}",
            $attachable instanceof SuggestionPost => "attachments/suggestions/{$attachable->id}",
            $attachable instanceof SuggestionComment => "attachments/suggestions/{$attachable->suggestion_post_id}/comments",
            default => throw new RuntimeException('Adjunto sin proyecto ni conversación.'),
        };

        $path = $file->storeAs($directory, "{$uuid}.{$extension}", self::DISK);

        if ($path === false) {
            throw new RuntimeException("No se ha podido guardar el adjunto {$uuid}.");
        }

        $attachment = new Attachment([
            'project_id' => $projectId,
            'user_id' => $uploader->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => self::cleanName($file->getClientOriginalName()),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'thumbnail_path' => null,
        ]);
        $attachment->attachable()->associate($attachable);
        $attachment->save();

        // La miniatura, en cola y solo cuando se confirme la transacción (p. ej., la del comentario).
        if ($attachment->isPreviewableImage()) {
            GenerateAttachmentThumbnail::dispatch($attachment)->afterCommit();
        }

        return $attachment;
    }

    /**
     * Genera la miniatura de un adjunto ya guardado (lo llama el job, fuera de la petición web) y
     * devuelve su ruta, o null si no procede o no se puede. Si el adjunto se ha borrado mientras
     * tanto, no deja la miniatura huérfana en el disco.
     */
    public function generateThumbnail(Attachment $attachment): ?string
    {
        if ($attachment->thumbnail_path !== null || ! $attachment->isPreviewableImage()) {
            return $attachment->thumbnail_path;
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return null;
        }

        $base = dirname($attachment->path).'/thumbs/'.pathinfo($attachment->path, PATHINFO_FILENAME);
        $thumbnail = $this->thumbnail($disk->path($attachment->path), $base, $attachment->disk);

        if ($thumbnail === null) {
            return null;
        }

        // Solo si sigue vivo (el ámbito de la papelera excluye los borrados).
        $updated = Attachment::query()->whereKey($attachment->id)->update(['thumbnail_path' => $thumbnail]);

        if ($updated === 0) {
            $disk->delete($thumbnail);

            return null;
        }

        $attachment->forceFill(['thumbnail_path' => $thumbnail])->syncOriginalAttributes('thumbnail_path');

        return $thumbnail;
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
     * En memoria solo hay a la vez la imagen decodificada y la miniatura: el fichero se lee desde
     * el disco (sin copiarlo en una cadena) y el giro EXIF se aplica a la miniatura, no al original.
     */
    private function thumbnail(string $source, string $pathWithoutExtension, string $disk): ?string
    {
        if (! function_exists('imagecreatetruecolor') || ! is_file($source)) {
            return null;
        }

        $info = @getimagesize($source);

        if ($info === false) {
            return null;
        }

        [$width, $height, $type] = $info;

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_THUMBNAIL_PIXELS || ! $this->fitsInMemory($width, $height)) {
            return null;
        }

        $image = $this->decode($source, $type);

        if (! $image instanceof GdImage) {
            return null;
        }

        $ratio = min(1, self::THUMBNAIL_SIZE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagefill($thumbnail, 0, 0, (int) imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($image), imagesy($image));
        imagedestroy($image);
        unset($image);

        $thumbnail = $this->orient($thumbnail, $source, $type);
        $webp = function_exists('imagewebp');

        ob_start();
        $encoded = $webp ? imagewebp($thumbnail, null, 80) : imagepng($thumbnail, null, 6);
        $data = (string) ob_get_clean();
        imagedestroy($thumbnail);

        if (! $encoded || $data === '') {
            return null;
        }

        $path = $pathWithoutExtension.($webp ? '.webp' : '.png');

        return Storage::disk($disk)->put($path, $data) ? $path : null;
    }

    /**
     * Decodifica desde el fichero con el lector de su formato (getimagesize ya ha leído la cabecera).
     */
    private function decode(string $source, int $type): ?GdImage
    {
        $reader = match ($type) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_AVIF => 'imagecreatefromavif',
            default => null,
        };

        if ($reader === null || ! function_exists($reader)) {
            return null;
        }

        $image = @$reader($source);

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * Gira la miniatura según el EXIF del original (fotos de móvil), si la extensión exif está
     * disponible. Se gira la miniatura, no el original: así no hay una segunda copia a tamaño real.
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

        if (! $rotated instanceof GdImage || $rotated === $image) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * Memoria que necesita GD: 4 bytes por píxel en color verdadero más un puntero por fila, la
     * miniatura y un margen para los búferes del decodificador.
     */
    public static function thumbnailMemory(int $width, int $height): int
    {
        return $width * $height * 4 + $height * 8 + self::THUMBNAIL_SIZE * self::THUMBNAIL_SIZE * 4 + 16 * 1024 * 1024;
    }

    /**
     * Cabe si no pasa del presupuesto fijo (la libgd del sistema reserva fuera de memory_limit) ni
     * de lo que queda hasta memory_limit (la GD integrada en PHP sí lo cuenta).
     */
    private function fitsInMemory(int $width, int $height): bool
    {
        $needed = self::thumbnailMemory($width, $height);

        if ($needed > self::MAX_THUMBNAIL_MEMORY) {
            return false;
        }

        $limit = $this->memoryLimit();

        return $limit < 0 || memory_get_usage(true) + $needed < $limit;
    }

    /**
     * Los workers de Horizon heredan el memory_limit de la CLI (128M en el servidor), que no deja
     * sitio para el presupuesto de una miniatura grande. Se sube solo lo necesario; el tope real lo
     * pone el MemoryLimit del cgroup de audax-horizon.service.
     */
    public function ensureThumbnailMemory(): void
    {
        $limit = $this->memoryLimit();
        $wanted = memory_get_usage(true) + self::MAX_THUMBNAIL_MEMORY + 32 * 1024 * 1024;

        if ($limit >= 0 && $limit < $wanted) {
            ini_set('memory_limit', (string) (int) ceil($wanted / 1024 / 1024).'M');
        }
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
