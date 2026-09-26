<?php

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\AttachmentStorage;
use App\Models\Attachment;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Reglas de los adjuntos (SPEC §15, D-037): tamaño máximo del ajuste max_attachment_mb, tipo real
 * dentro de Attachment::ALLOWED_MIMES (regla mimetypes, que lee el contenido con fileinfo) y una
 * extensión que case con ese tipo.
 */
trait ValidatesAttachments
{
    /**
     * @return array<string, mixed>
     */
    protected function attachmentRules(bool $required): array
    {
        return [
            'files' => [$required ? 'required' : 'nullable', 'array', 'max:'.AttachmentStorage::MAX_FILES],
            'files.*' => [
                'file',
                'max:'.AttachmentStorage::maxKilobytes(),
                'mimetypes:'.implode(',', Attachment::ALLOWED_MIMES),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    if (AttachmentStorage::extensionOf($value) === null) {
                        $fail(__('tasks.errors.attachment_name'));

                        return;
                    }

                    if (in_array((string) $value->getMimeType(), Attachment::ALLOWED_MIMES, true) && ! AttachmentStorage::extensionMatches($value)) {
                        $fail(__('tasks.errors.attachment_extension', ['name' => AttachmentStorage::cleanName($value->getClientOriginalName())]));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attachmentMessages(): array
    {
        return [
            'files.required' => __('tasks.errors.attachment_required'),
            'files.max' => __('tasks.errors.attachment_too_many', ['max' => AttachmentStorage::MAX_FILES]),
            'files.*.max' => __('tasks.errors.attachment_too_big', ['max' => AttachmentStorage::maxMegabytes()]),
            'files.*.mimetypes' => __('tasks.errors.attachment_type'),
        ];
    }
}
