<?php

/*
| Portal de cliente (Fase 5). Se usan con __('portal.…').
*/

return [
    'errors' => [
        'no_access' => 'Tu acceso al portal no está activo. Escríbenos si crees que es un error.',
    ],
    'person' => [
        'team' => 'Equipo',
    ],

    /*
    | Acceso al portal, proyectos en el portal e identidad (Agente P2, D-063, D-064 y D-067).
    */
    'access' => [
        'invited' => 'Invitación enviada a :email. El enlace caduca en 7 días.',
        'invitation_resent' => 'Invitación reenviada a :email. El enlace anterior ya no vale.',
        'revoked' => ':name ya no puede entrar en el portal. Sus sesiones se han cerrado.',
        'reactivated' => ':name vuelve a tener acceso al portal.',
        'settings_saved' => 'Ajustes del portal guardados.',
        'project_saved' => 'Ajustes del portal del proyecto guardados.',
        'errors' => [
            'email_taken' => 'Ya hay una cuenta con este correo.',
            'email_internal' => 'Este correo es de una persona del equipo: el portal es solo para el cliente.',
            'email_same_client' => 'Esta persona ya tiene cuenta en el portal de este cliente: reenvíale la invitación o reactívala.',
            'client_inactive' => 'El cliente está desactivado: reactívalo antes de dar acceso al portal.',
            'user_inactive' => 'Esta persona no tiene acceso al portal: reactívala antes de reenviar la invitación.',
            'already_revoked' => 'Esta persona ya no tenía acceso al portal.',
            'already_active' => 'Esta persona ya tiene acceso al portal.',
            'no_client' => 'Este proyecto no tiene cliente: no se puede abrir al portal.',
        ],
        'activity' => [
            'portal_user_invited' => 'Invitación al portal',
            'portal_invitation_resent' => 'Invitación al portal reenviada',
            'portal_user_revoked' => 'Acceso al portal revocado',
            'portal_user_reactivated' => 'Acceso al portal reactivado',
        ],
        'invitation' => [
            'subject' => 'Tu acceso al portal de clientes de :company',
            'intro' => ':company te ha dado acceso a su portal de clientes, donde puedes consultar tus bolsas de horas y, si te los abren, tus proyectos.',
            'intro_by' => ':inviter, de :company, te ha dado acceso al portal de clientes, donde puedes consultar tus bolsas de horas y, si te los abren, tus proyectos.',
        ],
        'attributes' => [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'portal_person_display' => 'cómo se nombra a las personas',
            'portal_entry_visibility' => 'horas que ve el cliente',
            'portal_notify_thresholds' => 'avisos de bolsa por email',
            'portal_project_visible' => 'abrir el proyecto al portal',
            'portal_show_task_hours' => 'horas por tarea',
            'portal_gantt_visible' => 'abrir el Gantt al portal',
        ],
    ],
    'identity' => [
        'saved' => 'Identidad guardada.',
        'logo_removed' => 'Logo quitado: vuelve el logotipo de Audax.',
        'errors' => [
            'logo_type' => 'El logo tiene que ser una imagen PNG, JPG o WebP (SVG no).',
            'logo_size' => 'El logo no puede pasar de 1 MB.',
            'logo_unreadable' => 'No se ha podido leer la imagen. Prueba a exportarla de nuevo en PNG.',
            'logo_dimensions' => 'La imagen es demasiado grande: como máximo :max px por lado.',
        ],
        'attributes' => [
            'company_name' => 'nombre de la empresa',
            'logo' => 'logo',
        ],
    ],

    'mail' => [
        'greeting' => 'Hola, :name:',
        'salutation' => 'Un saludo, el equipo de :company',
        'threshold' => [
            'near' => [
                'subject' => 'Tu bolsa «:bank» ha llegado al :threshold %',
                'intro' => 'La bolsa de horas «:bank» del proyecto :project ha llegado al :threshold % de las horas contratadas.',
            ],
            'exhausted' => [
                'subject' => 'Tu bolsa «:bank» se ha agotado',
                'intro' => 'La bolsa de horas «:bank» del proyecto :project ha llegado al 100 % de las horas contratadas. Hablemos para renovarla.',
            ],
            'figures' => 'Consumido: :within de :total (quedan :remaining).',
            'action' => 'Ver el detalle en el portal',
        ],
    ],
];
