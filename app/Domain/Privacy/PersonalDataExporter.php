<?php

namespace App\Domain\Privacy;

use App\Enums\PersonalDataExportStatus;
use App\Jobs\BuildPersonalDataExport;
use App\Models\PersonalDataExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pide una exportación de los datos personales de una persona (D-075): la propia persona desde
 * /ajustes/mis-datos o el admin desde su ficha. Una sola en curso por persona; queda en la
 * auditoría (log privacy) y se genera en cola (BuildPersonalDataExport).
 */
final class PersonalDataExporter
{
    /**
     * @throws ValidationException si ya hay una en curso
     */
    public function request(User $subject, User $requester): PersonalDataExport
    {
        $export = DB::transaction(function () use ($subject, $requester): PersonalDataExport {
            // Serializa las peticiones de la misma persona (doble clic, dos pestañas, el admin a la vez).
            User::query()->whereKey($subject->id)->lockForUpdate()->first(['id']);

            if (self::inProgress($subject)) {
                throw ValidationException::withMessages(['export' => __('privacy.exports.errors.in_progress')]);
            }

            $export = PersonalDataExport::query()->create([
                'subject_user_id' => $subject->id,
                'requested_by' => $requester->id,
                'status' => PersonalDataExportStatus::Pending,
                'disk' => 'local',
            ]);

            activity('privacy')
                ->performedOn($export)
                ->causedBy($requester)
                ->event('export_requested')
                ->withProperties(['subject_user_id' => $subject->id])
                ->log('personal_data_export.requested');

            return $export;
        });

        BuildPersonalDataExport::dispatch($export->id);

        return $export;
    }

    /** ¿Tiene ya una exportación en cola o preparándose? */
    public static function inProgress(User $subject): bool
    {
        return PersonalDataExport::query()
            ->where('subject_user_id', $subject->id)
            ->whereIn('status', [PersonalDataExportStatus::Pending->value, PersonalDataExportStatus::Processing->value])
            ->exists();
    }

    /**
     * Registra una descarga: la fecha en la exportación y la entrada en la auditoría.
     */
    public function recordDownload(PersonalDataExport $export, User $by): void
    {
        $export->forceFill(['downloaded_at' => now()])->save();

        activity('privacy')
            ->performedOn($export)
            ->causedBy($by)
            ->event('export_downloaded')
            ->withProperties(['subject_user_id' => $export->subject_user_id])
            ->log('personal_data_export.downloaded');
    }
}
