<?php

namespace App\Http\Requests\Weeklies;

use App\Http\Requests\Tasks\ValidatesAttachments;
use App\Models\SuggestionComment;
use App\Models\SuggestionPost;
use App\Support\RichText;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Comentar o editar un comentario de una sugerencia (F-165): texto con formato y menciones
 * (RichText) y adjuntos; un comentario puede ir solo con adjuntos. `parent_id` lo hace respuesta.
 */
class SuggestionCommentRequest extends FormRequest
{
    use ValidatesAttachments;

    /** Comentar, quien puede ver la sugerencia; editar, el autor del comentario. */
    public function authorize(): bool
    {
        $comment = $this->route('suggestionComment');
        $post = $this->route('post');

        return match (true) {
            $comment instanceof SuggestionComment => Gate::allows('update', $comment),
            $post instanceof SuggestionPost => Gate::allows('comment', $post),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => __('help.attributes.comment')];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.RichText::MAX_LENGTH],
            'parent_id' => ['nullable', 'integer'],
            'remove_attachment_ids' => ['nullable', 'array', 'max:50'],
            'remove_attachment_ids.*' => ['integer'],
            ...$this->attachmentRules(required: false),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->has('body') && $this->body() === '' && $this->files() === [] && $this->route('suggestionComment') === null) {
                $validator->errors()->add('body', __('help.suggestions.comment_required'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->attachmentMessages();
    }

    public function body(): string
    {
        return (string) RichText::sanitize($this->string('body')->toString());
    }

    public function parentId(): ?int
    {
        return $this->filled('parent_id') ? $this->integer('parent_id') : null;
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
}
