<?php

namespace App\Domain\Weeklies\Help;

use App\Domain\Tasks\AttachmentStorage;
use App\Models\Attachment;
use App\Models\HelpTutorial;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Subida por trozos de los vídeos de los tutoriales (F-155, D-207): WeeklySync admitía 200 MB
 * (Supabase Storage), pero el PHP del servidor solo acepta 55 MB por petición (post_max_size 64M,
 * RUNBOOK-DESPLIEGUE paso 3). El navegador manda el vídeo en trozos de 8 MB:
 *   1. `start()` reserva la subida (nombre y tamaño, como mucho 200 MB) y devuelve su id,
 *   2. `append()` añade un trozo en su posición (repetir uno ya recibido no hace nada: así se puede
 *      reintentar tras un corte) y devuelve los bytes recibidos,
 *   3. `attach()` (al guardar el tutorial) comprueba que está completo y que es un vídeo de verdad
 *      (fileinfo), lo mueve a help/tutorials y crea su Attachment; el anterior se borra.
 * Lo que está a medias vive en help/uploads (disco privado) con su ficha JSON. Solo lo continúa
 * quien lo empezó. `prune()` borra lo abandonado (más de un día), cada noche (help:prune-uploads).
 */
final class TutorialVideoUploads
{
    public const int MAX_BYTES = 200 * 1024 * 1024;

    public const int CHUNK_BYTES = 8 * 1024 * 1024;

    public const string DIRECTORY = 'help/uploads';

    public const string VIDEOS = 'help/tutorials';

    /** Horas que se guarda una subida sin terminar. */
    public const int STALE_HOURS = 24;

    /**
     * Tipos reales de vídeo que se aceptan (los que reproduce un navegador) y su extensión.
     *
     * @var array<string, string>
     */
    public const array MIMES = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'video/ogg' => 'ogv',
        'video/x-m4v' => 'm4v',
    ];

    public function __construct(private readonly AttachmentStorage $attachments) {}

    /**
     * @return array{upload: string, chunk_size: int, received: int}
     */
    public function start(User $user, string $name, int $size): array
    {
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['size' => __('help.tutorials.too_big')]);
        }

        $id = (string) Str::uuid();
        $disk = $this->disk();
        $disk->put($this->partPath($id), '');
        $disk->put($this->metaPath($id), (string) json_encode([
            'user_id' => $user->id,
            'name' => AttachmentStorage::cleanName($name),
            'size' => $size,
        ]));

        return ['upload' => $id, 'chunk_size' => self::CHUNK_BYTES, 'received' => 0];
    }

    /**
     * Añade un trozo en `$offset`. Devuelve los bytes recibidos hasta ahora.
     */
    public function append(string $id, User $user, int $offset, UploadedFile $chunk): int
    {
        $meta = $this->meta($id, $user);
        $path = $this->disk()->path($this->partPath($id));
        clearstatcache(true, $path);
        $received = (int) filesize($path);
        $length = (int) $chunk->getSize();

        if ($length < 1 || $length > self::CHUNK_BYTES) {
            throw ValidationException::withMessages(['chunk' => __('help.tutorials.chunk_invalid')]);
        }

        // Un trozo que ya está (se reintenta tras perder la respuesta): nada que hacer.
        if ($offset + $length <= $received) {
            return $received;
        }

        if ($offset !== $received || $offset + $length > $meta['size']) {
            throw ValidationException::withMessages(['offset' => __('help.tutorials.chunk_out_of_order', ['received' => $received])]);
        }

        $target = fopen($path, 'ab');
        $source = fopen((string) $chunk->getRealPath(), 'rb');

        if ($target === false || $source === false) {
            throw new RuntimeException("No se ha podido escribir la subida {$id}.");
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        clearstatcache(true, $path);

        return (int) filesize($path);
    }

    /**
     * Pone el vídeo subido en el tutorial (sustituye al anterior, que se borra del disco).
     */
    public function attach(string $id, User $user, HelpTutorial $tutorial): Attachment
    {
        $meta = $this->meta($id, $user);
        $disk = $this->disk();
        $part = $this->partPath($id);
        $size = $disk->size($part);

        if ($size !== $meta['size']) {
            throw ValidationException::withMessages(['upload' => __('help.tutorials.incomplete')]);
        }

        $mime = (string) $disk->mimeType($part);
        $extension = self::MIMES[$mime] ?? null;

        if ($extension === null) {
            $this->discard($id);

            throw ValidationException::withMessages(['upload' => __('help.tutorials.not_video')]);
        }

        $path = self::VIDEOS.'/'.Str::uuid().'.'.$extension;
        $disk->move($part, $path);
        $disk->delete($this->metaPath($id));

        $attachment = new Attachment([
            'project_id' => null,
            'user_id' => $user->id,
            'disk' => AttachmentStorage::DISK,
            'path' => $path,
            'original_name' => $meta['name'],
            'mime' => $mime,
            'size' => $size,
            'thumbnail_path' => null,
        ]);
        $attachment->attachable()->associate($tutorial);
        $attachment->save();

        $previous = Attachment::query()->whereMorphedTo('attachable', $tutorial)->whereKeyNot($attachment->id)->get();

        foreach ($previous as $old) {
            $this->attachments->delete($old);
        }

        $tutorial->unsetRelation('video');

        return $attachment;
    }

    /** ¿Existe la subida y es de esta persona? */
    public function owns(string $id, User $user): bool
    {
        try {
            $this->meta($id, $user);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function discard(string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }

        $this->disk()->delete([$this->partPath($id), $this->metaPath($id)]);
    }

    /**
     * Borra las subidas abandonadas. Devuelve cuántas.
     */
    public function prune(): int
    {
        $disk = $this->disk();
        $limit = now()->subHours(self::STALE_HOURS)->getTimestamp();
        $count = 0;

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if (str_ends_with($file, '.json') && $disk->lastModified($file) < $limit) {
                $id = basename($file, '.json');
                $this->discard($id);
                $count++;
            }
        }

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if (str_ends_with($file, '.part') && ! $disk->exists(substr($file, 0, -5).'.json') && $disk->lastModified($file) < $limit) {
                $disk->delete($file);
            }
        }

        return $count;
    }

    /**
     * @return array{user_id: int, name: string, size: int}
     */
    private function meta(string $id, User $user): array
    {
        $disk = $this->disk();

        if (! Str::isUuid($id) || ! $disk->exists($this->metaPath($id)) || ! $disk->exists($this->partPath($id))) {
            throw ValidationException::withMessages(['upload' => __('help.tutorials.upload_missing')]);
        }

        /** @var array{user_id?: int, name?: string, size?: int} $meta */
        $meta = (array) json_decode((string) $disk->get($this->metaPath($id)), true);

        if (($meta['user_id'] ?? null) !== $user->id) {
            throw ValidationException::withMessages(['upload' => __('help.tutorials.upload_missing')]);
        }

        return ['user_id' => $user->id, 'name' => (string) ($meta['name'] ?? 'video'), 'size' => (int) ($meta['size'] ?? 0)];
    }

    private function partPath(string $id): string
    {
        return self::DIRECTORY."/{$id}.part";
    }

    private function metaPath(string $id): string
    {
        return self::DIRECTORY."/{$id}.json";
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(AttachmentStorage::DISK);

        return $disk;
    }
}
