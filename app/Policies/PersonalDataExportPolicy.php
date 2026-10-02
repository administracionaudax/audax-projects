<?php

namespace App\Policies;

use App\Models\PersonalDataExport;
use App\Models\User;

/**
 * Exportaciones de datos personales (D-075): solo las descarga la propia persona o un admin, y
 * siempre con la URL firmada (middleware signed:relative en la ruta).
 */
class PersonalDataExportPolicy
{
    public function download(User $user, PersonalDataExport $export): bool
    {
        if (! $user->isInternal()) {
            return false;
        }

        return $export->subject_user_id === $user->id || $user->isAdmin();
    }
}
