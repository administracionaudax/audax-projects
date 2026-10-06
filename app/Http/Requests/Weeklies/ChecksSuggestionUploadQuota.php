<?php

namespace App\Http\Requests\Weeklies;

use App\Domain\Weeklies\Help\HelpUploadQuota;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

/**
 * Cuota de los adjuntos de sugerencias y comentarios (D-223): lo que suben, menos lo que quitan de lo
 * suyo, cabe en su cuota y en el disco.
 */
trait ChecksSuggestionUploadQuota
{
    protected function checkUploadQuota(Validator $validator): void
    {
        $files = $this->files();
        $user = $this->user();

        if ($files === [] || ! $user instanceof User || $validator->errors()->has('files') || $validator->errors()->has('files.*')) {
            return;
        }

        $bytes = array_sum(array_map(fn (UploadedFile $file): int => (int) $file->getSize(), $files));
        $removed = (int) Attachment::query()->whereKey($this->removeAttachmentIds())->where('user_id', $user->id)->sum('size');

        if (($problem = app(HelpUploadQuota::class)->suggestionProblem($user, $bytes, $removed)) !== null) {
            $validator->errors()->add('files', $problem);
        }
    }
}
