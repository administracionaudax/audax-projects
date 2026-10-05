<?php

namespace App\Http\Requests\Weeklies;

use App\Domain\Tasks\AttachmentStorage;
use App\Enums\DictationContext;
use App\Http\Controllers\Chat\Media\StoreMediaMessageRequest;
use App\Models\Dictation;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Subir un dictado de la weekly (F-049, D-152): el audio grabado en el navegador, con las mismas
 * reglas que los audios del chat (tipo real, tamaño y duración máxima del ajuste max_audio_seconds,
 * y un tamaño acorde con la duración). Con contexto weekly_entry, la semana tiene que estar activa
 * y ser una que la persona puede escribir. El dictado de las notas de una tarea llega en 10.6.
 */
final class StoreDictationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Dictation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'context' => ['required', Rule::in([DictationContext::WeeklyEntry->value])],
            'weekly_cycle_id' => ['required', 'integer'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'audio' => [
                'required',
                'file',
                'max:'.AttachmentStorage::maxKilobytes(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && ! AttachmentStorage::audioMatches($value)) {
                        $fail(__('weeklies.dictation.errors.audio_type'));
                    }
                },
            ],
            'duration_ms' => ['required', 'integer', 'min:0', 'max:'.StoreMediaMessageRequest::maxDurationMs()],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->any()) {
                    return;
                }

                $cycle = WeeklyCycle::query()->find($this->integer('weekly_cycle_id'));

                if ($cycle === null || Gate::denies('create', [WeeklySubmission::class, $cycle])) {
                    $validator->errors()->add('weekly_cycle_id', __('weeklies.errors.cycle_closed'));

                    return;
                }

                $audio = $this->audioFile();

                if ($audio !== null && (int) $audio->getSize() > StoreMediaMessageRequest::maxBytesFor($this->integer('duration_ms'))) {
                    $validator->errors()->add('audio', __('weeklies.dictation.errors.duration_mismatch'));
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
            'audio.required' => __('weeklies.dictation.errors.audio_type'),
            'audio.file' => __('weeklies.dictation.errors.audio_type'),
            'audio.max' => __('weeklies.dictation.errors.audio_too_big', ['max' => AttachmentStorage::maxMegabytes()]),
            'duration_ms.required' => __('weeklies.dictation.errors.duration'),
            'duration_ms.integer' => __('weeklies.dictation.errors.duration'),
            'duration_ms.max' => __('weeklies.dictation.errors.too_long', ['max' => StoreMediaMessageRequest::clock(StoreMediaMessageRequest::maxSeconds())]),
        ];
    }

    public function audioFile(): ?UploadedFile
    {
        $audio = $this->file('audio');

        return $audio instanceof UploadedFile ? $audio : null;
    }
}
