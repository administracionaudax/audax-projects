<?php

/*
|--------------------------------------------------------------------------
| Copias de seguridad (SPEC §15, D-029 y D-076)
|--------------------------------------------------------------------------
| app:check-storage (cada día a las 09:00) avisa al admin si la última copia es de hace más de
| max_age_hours horas o falló, si falló la última prueba de restauración o si falló la copia
| externa. Lee el fichero de estado que escriben los scripts del servidor
| (deploy/backup/estado-copias.py): un objeto JSON con fechas ISO 8601 en UTC (terminadas en Z) y
| booleanos JSON:
|
|   {
|     "last_backup_at": "2026-09-27T01:41:02Z",   última copia nocturna CORRECTA (solo cambia si sale bien)
|     "last_backup_ok": true,                      si la última copia salió bien
|     "last_backup_failed_at": "…",                última copia fallida
|     "last_restore_check_at": "…",                última prueba de restauración (mensual)
|     "last_restore_check_ok": true,               si la restauró bien
|     "last_offsite_at": "…",                      última copia externa correcta ┐ solo si la copia
|     "last_offsite_ok": true,                     si la última salió bien       │ externa está
|     "last_offsite_failed_at": "…"                última fallida               ┘ activada
|   }
|
| Faltan o sobran claves: se comprueba lo que haya. Sin fichero, no se avisa de las copias. Un
| fichero ilegible o con valores de otro tipo no avisa de las copias, pero queda en el log.
*/

return [
    'status_path' => storage_path('app/backup-status.json'),

    // Horas que puede tener la última copia correcta antes de avisar.
    'max_age_hours' => 36,
];
