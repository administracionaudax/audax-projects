<?php

namespace App\Http\Controllers\Chat\Media;

use App\Domain\Chat\MessageWriter;
use App\Domain\Tasks\AttachmentStorage;
use App\Http\Requests\Tasks\ValidatesAttachments;
use App\Models\Conversation;
use App\Models\Setting;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Publicar en el chat con adjuntos y/o un audio (SPEC §12, D-069). Solo quien puede escribir en la
 * conversación (ConversationPolicy::post; MessageWriter lo vuelve a comprobar).
 * - Adjuntos: las reglas de la Fase 1 (ValidatesAttachments: hasta 10, el tamaño del ajuste
 *   max_attachment_mb y el tipo real con una extensión que case con él).
 * - Audio: el tipo real entre los de AttachmentStorage::AUDIO_EXTENSIONS, el mismo tamaño máximo y
 *   la duración que envía el navegador (duration_ms), que no puede pasar del ajuste
 *   max_audio_seconds (con un margen: MediaRecorder se para unas décimas después). Para que nadie
 *   declare un audio corto con un archivo largo, el tamaño tiene que cuadrar con esa duración (el
 *   navegador graba a 64 kbit/s, unos 8 KB/s; se admite hasta 16 KB/s por los contenedores mp4 y
 *   ogg). Además, el transcriptor procesa como mucho la duración máxima (D-116), mide la real y
 *   /admin/transcripciones señala los que pasan del máximo.
 */
final class StoreMediaMessageRequest extends FormRequest
{
    use ValidatesAttachments;

    public const int MIN_DURATION_MS = 300;

    public const int DURATION_TOLERANCE_MS = 2000;

    public const int MAX_BYTES_PER_SECOND = 16_000;

    /**
     * Cabeceras del contenedor, que no dependen de la duración.
     */
    public const int SIZE_SLACK_BYTES = 64 * 1024;

    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && Gate::allows('post', $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.MessageWriter::MAX_BODY],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            ...$this->attachmentRules(required: false),
            'audio' => [
                'nullable',
                'file',
                'max:'.AttachmentStorage::maxKilobytes(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && ! AttachmentStorage::audioMatches($value)) {
                        $fail(__('chat_media.errors.audio_type'));
                    }
                },
            ],
            'duration_ms' => ['nullable', 'required_with:audio', 'integer', 'min:'.self::MIN_DURATION_MS, 'max:'.self::maxDurationMs()],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $audio = $this->audioFile();
                $duration = $this->audioDurationMs();

                if ($audio === null || $duration === null || $validator->errors()->hasAny(['audio', 'duration_ms'])) {
                    return;
                }

                if ((int) $audio->getSize() > self::maxBytesFor($duration)) {
                    $validator->errors()->add('audio', __('chat_media.errors.duration_mismatch'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->attachmentMessages(),
            'body.max' => __('chat.errors.too_long', ['max' => MessageWriter::MAX_BODY]),
            'parent_id.integer' => __('chat_media.errors.parent'),
            'parent_id.min' => __('chat_media.errors.parent'),
            'audio.file' => __('chat_media.errors.audio_type'),
            'audio.max' => __('chat_media.errors.audio_too_big', ['max' => AttachmentStorage::maxMegabytes()]),
            'duration_ms.required_with' => __('chat_media.errors.duration_required'),
            'duration_ms.integer' => __('chat_media.errors.duration_required'),
            'duration_ms.min' => __('chat_media.errors.duration_too_short'),
            'duration_ms.max' => __('chat_media.errors.duration_too_long', ['max' => self::clock(self::maxSeconds())]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> */
        return (array) __('chat_media.attributes');
    }

    public function messageBody(): ?string
    {
        $body = $this->input('body');

        return is_string($body) ? $body : null;
    }

    public function parentMessageId(): ?int
    {
        return $this->filled('parent_id') ? $this->integer('parent_id') : null;
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachedFiles(): array
    {
        return array_values(array_filter((array) $this->file('files', []), fn ($file): bool => $file instanceof UploadedFile));
    }

    public function audioFile(): ?UploadedFile
    {
        $audio = $this->file('audio');

        return $audio instanceof UploadedFile ? $audio : null;
    }

    public function audioDurationMs(): ?int
    {
        return $this->audioFile() !== null && $this->filled('duration_ms') ? $this->integer('duration_ms') : null;
    }

    /**
     * Duración máxima de un audio (ajuste max_audio_seconds, por defecto 5 minutos).
     */
    public static function maxSeconds(): int
    {
        return max(1, (int) Setting::get('max_audio_seconds', 300));
    }

    public static function maxDurationMs(): int
    {
        return self::maxSeconds() * 1000 + self::DURATION_TOLERANCE_MS;
    }

    /**
     * Lo más que puede pesar un audio de esa duración.
     */
    public static function maxBytesFor(int $durationMs): int
    {
        return (int) ceil(($durationMs / 1000 + 2) * self::MAX_BYTES_PER_SECOND) + self::SIZE_SLACK_BYTES;
    }

    /**
     * 300 → «5:00».
     */
    public static function clock(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
