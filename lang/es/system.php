<?php

/*
| Avisos de sistema al admin (SPEC §15, D-076): app:check-storage. Obligatorios: el admin no los
| puede desactivar (NotificationCatalog, grupo «system»).
*/

return [
    'storage' => [
        'disk' => [
            'title' => 'El disco del servidor está al :percent %',
            'body' => 'Supera el aviso del :threshold %. Quedan :free libres: libera espacio o amplía el disco antes de que se llene.',
        ],
        'attachments' => [
            'title' => 'Los adjuntos ocupan :size',
            'body' => 'Superan el aviso de :threshold GB. Revisa los adjuntos de los proyectos cerrados o sube el umbral en Privacidad y datos.',
        ],
    ],

    'backups' => [
        'unknown_date' => 'fecha desconocida',
        'backup_failed' => [
            'title' => 'Ha fallado la copia de seguridad de la noche',
            'body' => 'Falló el :failed_at. La última copia correcta es del :last_ok_at. Revisa el servidor (DEPLOY.md, copias de seguridad).',
        ],
        'backup_stale' => [
            'title' => 'No hay ninguna copia de seguridad reciente',
            'body' => 'La última copia correcta es del :last_ok_at, hace más de :hours horas. Revisa el servidor (DEPLOY.md, copias de seguridad).',
        ],
        'restore_failed' => [
            'title' => 'Ha fallado la prueba de restauración de las copias',
            'body' => 'La prueba del :checked_at no ha podido restaurar la última copia. Revísala cuanto antes: una copia que no se restaura no sirve.',
        ],
        'offsite_failed' => [
            'title' => 'Ha fallado la copia externa',
            'body' => 'Falló el :failed_at. La última copia externa correcta es del :last_ok_at. Revisa el destino de la copia (DEPLOY.md).',
        ],
    ],
];
