<?php

/*
| Integraciones (Fase 9, D-142): conexión de la cuenta de Google en Ajustes → Integraciones y
| exportación de informes a Google Sheets. Textos de los avisos y errores del servidor.
*/

return [
    'google' => [
        'connected' => 'Cuenta de Google conectada: :email.',
        'disconnected' => 'Has desconectado tu cuenta de Google.',
        'disconnected_unconfirmed' => 'Has desconectado tu cuenta de Google. Google no ha confirmado la revocación: puedes quitar el acceso desde tu cuenta de Google (Seguridad → Aplicaciones de terceros).',
        'errors' => [
            'not_connected' => 'Conecta tu cuenta de Google en Ajustes → Integraciones.',
            'reconnect' => 'Google ha retirado el acceso. Vuelve a conectar tu cuenta en Ajustes → Integraciones.',
            'unavailable' => 'Google no responde ahora mismo. Inténtalo de nuevo en unos minutos.',
            'invalid_state' => 'La conexión con Google ha caducado o no es válida. Vuelve a intentarlo.',
            'denied' => 'Has cancelado la conexión con Google.',
            'exchange_failed' => 'Google no ha aceptado la conexión. Vuelve a intentarlo.',
            'wrong_domain' => 'Solo puedes conectar una cuenta de Google de Audax Studio (@:domain).',
            'missing_scope' => 'Para exportar a Google Sheets tienes que permitir el acceso a los archivos de Drive que crea la app.',
            'missing_refresh_token' => 'Google no ha dado acceso permanente. Quita el acceso de la app en tu cuenta de Google y vuelve a conectar.',
        ],
    ],
];
