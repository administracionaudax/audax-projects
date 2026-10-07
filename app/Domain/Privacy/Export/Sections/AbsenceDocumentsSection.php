<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\AbsenceDocument;
use App\Models\User;

/**
 * Mis justificantes de ausencias (Fase 11, R3; D-368 y D-372): qué fichero se subió a cada ausencia,
 * quién y cuándo, con su huella. Los ficheros se descargan desde «Mis ausencias».
 */
final class AbsenceDocumentsSection extends Section
{
    public function key(): string
    {
        return 'justificantes';
    }

    protected function textKey(): string
    {
        return 'absence_documents';
    }

    protected function columnKeys(): array
    {
        return ['absence_id', 'name', 'mime', 'size', 'sha256', 'uploaded_by', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        foreach (AbsenceDocument::query()->with('uploader:id,name')->where('user_id', $user->id)->orderBy('id')->get() as $document) {
            yield [
                'absence_id' => $document->absence_id,
                'name' => $document->original_name,
                'mime' => $document->mime,
                'size' => $document->size,
                'sha256' => $document->sha256,
                'uploaded_by' => $document->uploader?->name,
                'created_at' => self::instant($document->created_at),
            ];
        }
    }
}
