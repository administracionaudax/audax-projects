<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mensajes de autenticación
    |--------------------------------------------------------------------------
    */

    'failed' => 'El correo electrónico o la contraseña no son correctos.',
    'password' => 'La contraseña no es correcta.',
    'throttle' => 'Demasiados intentos. Vuelve a intentarlo en :seconds segundos.',

    // Entrar con Google (D-165): por qué no se deja entrar (App\Domain\Auth\Google\GoogleLoginRejection).
    'google' => [
        'errors' => [
            'invalid_state' => 'El acceso con Google ha caducado o no es válido. Vuelve a intentarlo.',
            'denied' => 'Has cancelado el acceso con Google.',
            'exchange_failed' => 'Google no ha aceptado el acceso. Vuelve a intentarlo.',
            'invalid_token' => 'No hemos podido comprobar tu cuenta de Google. Vuelve a intentarlo.',
            'unverified' => 'Tu cuenta de Google no tiene el correo verificado.',
            'wrong_domain' => 'Solo se puede entrar con una cuenta de Google de Audax Studio (@:domain).',
            'not_found' => 'No hay ninguna cuenta en Audax Proyectos con :email. Pide a la administración que te dé de alta.',
            'inactive' => 'Tu cuenta está desactivada. Si crees que es un error, habla con la administración.',
            'client' => 'El acceso con Google es solo para la plantilla. Entra con tu correo y tu contraseña.',
            'collaborator' => 'El acceso con Google es solo para la plantilla. Entra con tu correo y tu contraseña.',
        ],
    ],

];
