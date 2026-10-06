<?php

namespace App\Http\Requests\Weeklies;

use App\Http\Requests\Tasks\ValidatesAttachments;
use App\Models\SuggestionPost;
use App\Support\RichText;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Crear o editar una sugerencia (F-161 y F-164): título, tablero y categoría, detalle con formato
 * (RichText saneado en el servidor) y adjuntos (las reglas de los adjuntos de tareas, D-037). Al
 * editar, `remove_attachment_ids` quita los que ya tenía. Autoriza antes de validar: crear, la
 * plantilla; editar, su autor o quien gestiona (SuggestionPostPolicy).
 */
class SuggestionPostRequest extends FormRequest
{
    use ChecksSuggestionUploadQuota, ValidatesAttachments;

    public function authorize(): bool
    {
        $post = $this->route('post');

        return $post instanceof SuggestionPost
            ? Gate::allows('update', $post)
            : Gate::allows('create', SuggestionPost::class);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('help.attributes.title'),
            'body' => __('help.attributes.body'),
            'suggestion_board_id' => __('help.attributes.board'),
            'suggestion_category_id' => __('help.attributes.category'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:'.RichText::MAX_LENGTH],
            'suggestion_board_id' => ['required', 'integer'],
            'suggestion_category_id' => ['nullable', 'integer'],
            'remove_attachment_ids' => ['nullable', 'array', 'max:50'],
            'remove_attachment_ids.*' => ['integer'],
            ...$this->attachmentRules(required: false),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->has('body') && $this->body() === null) {
                $validator->errors()->add('body', __('help.suggestions.body_required'));
            }

            $this->checkUploadQuota($validator);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->attachmentMessages();
    }

    /**
     * @return array{title: string, body: string, suggestion_board_id: int, suggestion_category_id: int|null}
     */
    public function payload(): array
    {
        return [
            'title' => trim($this->string('title')->toString()),
            'body' => (string) $this->body(),
            'suggestion_board_id' => $this->integer('suggestion_board_id'),
            'suggestion_category_id' => $this->filled('suggestion_category_id') ? $this->integer('suggestion_category_id') : null,
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function files(): array
    {
        return array_values(array_filter((array) $this->file('files', []), fn ($file): bool => $file instanceof UploadedFile));
    }

    /**
     * @return list<int>
     */
    public function removeAttachmentIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('remove_attachment_ids', [])));
    }

    private function body(): ?string
    {
        return RichText::sanitize($this->string('body')->toString());
    }
}
