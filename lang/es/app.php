<?php

/*
|--------------------------------------------------------------------------
| Mensajes propios de la aplicación (backend)
|--------------------------------------------------------------------------
| Español de España, tuteo. Los textos de la interfaz React viven en lang/es.json.
*/

return [
    'account_inactive' => 'Tu cuenta está desactivada. Si crees que es un error, habla con la administración.',
    'forbidden' => 'No tienes permiso para acceder a esta sección.',
    // Colaboradores externos (D-134): rutas internas cerradas por defecto.
    'collaborator_forbidden' => 'Esta sección no está disponible para colaboradores externos.',
    'two_factor_required' => 'Para seguir usando la aplicación, activa la verificación en dos pasos.',
    'profile_updated' => 'Perfil actualizado.',
    // Foto de perfil (F-029, D-234).
    'avatar' => [
        'updated' => 'Foto de perfil actualizada.',
        'removed' => 'Foto de perfil quitada.',
        'invalid' => 'No se ha podido leer la imagen. Usa una foto JPG, PNG o WebP.',
        'too_large' => 'La imagen pesa demasiado (como mucho :mb MB).',
    ],
    'page_expired' => 'La página ha caducado. Vuelve a intentarlo.',
    'password_updated' => 'Contraseña actualizada.',
    'appearance_updated' => 'Tema actualizado.',
    'invitation_accepted' => 'Tu contraseña está lista. Ya puedes entrar.',
    'invitation_invalid' => 'Este enlace de invitación no es válido o ha caducado. Pide a la administración que te envíe otro.',

    'sessions' => [
        'closed' => 'Sesión cerrada.',
        'others_closed' => 'Se han cerrado las demás sesiones.',
        'cannot_close_current' => 'No puedes cerrar la sesión que estás usando. Para salir, usa «Cerrar sesión».',
        'unsupported' => 'No se pueden cerrar sesiones con la configuración actual del servidor. Avisa a la administración.',
    ],
];
