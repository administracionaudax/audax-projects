<?php

namespace App\Domain\Privacy\Retention;

use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Mensajes del chat anteriores al plazo de RetentionPolicy::CHAT_MESSAGES (sin límite por defecto,
 * así que solo actúa si el admin fija uno). Por lotes cortos, cada mensaje se borra DE VERDAD con
 * todo lo que solo existe por él (D-075, ver docs/DECISIONES.md):
 * - sus adjuntos y notas de voz (filas y ficheros, también la miniatura) y la transcripción de
 *   cada audio,
 * - sus reacciones y menciones,
 * - los avisos de la campana que hablan de él (notifications.chat_message_id),
 * - las respuestas más recientes se conservan sin la cita (parent_id a null).
 *
 * Se conservan las conversaciones y sus participantes, las tareas creadas desde un mensaje y, por
 * supuesto, horas, bolsas, tareas y proyectos. La auditoría de la moderación (que guarda el texto
 * del mensaje ocultado) sigue su propio plazo. Los ficheros se borran después de las filas: un
 * fallo del disco deja, como mucho, un fichero huérfano, nunca un adjunto sin fichero.
 */
final class ChatMessagesPruner implements RetentionPruner
{
    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        $batchSize = max(1, $batchSize);
        $deleted = 0;

        do {
            /** @var list<int> $ids */
            $ids = DB::table('messages')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += $this->deleteBatch($ids);
        } while (count($ids) === $batchSize);

        return $deleted;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteBatch(array $ids): int
    {
        $attachments = DB::table('attachments')
            ->where('attachable_type', (new Message)->getMorphClass())
            ->whereIn('attachable_id', $ids)
            ->get(['id', 'disk', 'path', 'thumbnail_path']);

        $deleted = DB::transaction(function () use ($ids, $attachments): int {
            DB::table('audio_transcriptions')->whereIn('message_id', $ids)->delete();
            DB::table('message_reactions')->whereIn('message_id', $ids)->delete();
            DB::table('message_mentions')->whereIn('message_id', $ids)->delete();
            DB::table('attachments')->whereIn('id', $attachments->pluck('id')->all())->delete();
            DB::table('notifications')->whereIn('chat_message_id', $ids)->delete();
            DB::table('messages')->whereIn('parent_id', $ids)->update(['parent_id' => null]);

            return DB::table('messages')->whereIn('id', $ids)->delete();
        });

        foreach ($attachments as $attachment) {
            $disk = Storage::disk((string) $attachment->disk);

            foreach (array_filter([$attachment->path, $attachment->thumbnail_path]) as $path) {
                rescue(fn () => $disk->delete((string) $path), report: false);
            }
        }

        return $deleted;
    }
}
