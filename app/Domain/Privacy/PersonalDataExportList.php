<?php

namespace App\Domain\Privacy;

use App\Models\PersonalDataExport;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Exportaciones de una persona para /ajustes/mis-datos y la ficha del admin (contrato:
 * resources/js/types/privacy.ts, PersonalDataExportRow). La descarga va con URL firmada y
 * relativa hasta que caduca la exportación, y el controlador comprueba además la política.
 */
final class PersonalDataExportList
{
    /** Exportaciones que se muestran (las más recientes). */
    public const int LIMIT = 10;

    /**
     * @return list<array{id: int, status: string, status_label: string, requested_by_subject: bool, requester: string|null, created_at: string|null, finished_at: string|null, expires_at: string|null, downloaded_at: string|null, size_bytes: int|null, download_url: string|null}>
     */
    public function for(User $subject): array
    {
        $exports = PersonalDataExport::query()
            ->where('subject_user_id', $subject->id)
            ->with(['requester' => fn ($query) => $query->select(['id', 'name'])])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return array_values($exports->map(fn (PersonalDataExport $export): array => $this->row($export))->all());
    }

    /**
     * @return array{id: int, status: string, status_label: string, requested_by_subject: bool, requester: string|null, created_at: string|null, finished_at: string|null, expires_at: string|null, downloaded_at: string|null, size_bytes: int|null, download_url: string|null}
     */
    private function row(PersonalDataExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status->value,
            'status_label' => $export->status->label(),
            'requested_by_subject' => $export->requested_by === $export->subject_user_id,
            'requester' => $export->requester?->name,
            'created_at' => $export->created_at?->toIso8601ZuluString(),
            'finished_at' => $export->finished_at?->toIso8601ZuluString(),
            'expires_at' => $export->expires_at?->toIso8601ZuluString(),
            'downloaded_at' => $export->downloaded_at?->toIso8601ZuluString(),
            'size_bytes' => $export->size_bytes,
            'download_url' => $export->isDownloadable() && $export->expires_at !== null
                ? URL::temporarySignedRoute('privacy.exports.download', $export->expires_at, ['export' => $export->id], absolute: false)
                : null,
        ];
    }
}
