<?php

/*
| Mensajes del área de clientes (SPEC §6). Se usan con __('clients.…'). Español de España, tuteo.
| Los textos de la interfaz React del área están en lang/ui/clients.json.
*/

return [
    'created' => 'Cliente :name creado.',
    'updated' => 'Cambios guardados.',
    'deactivated' => ':name está desactivado: ya no aparece al crear proyectos.',
    'reactivated' => ':name vuelve a estar activo.',

    'errors' => [
        'name_taken' => 'Ya hay un cliente con este nombre.',
        'icon' => 'El icono tiene que ser un solo emoji.',
        'owner' => 'El responsable tiene que ser alguien activo de la plantilla.',
    ],

    'attributes' => [
        'name' => 'nombre',
        'tax_id' => 'NIF/CIF',
        'contact_name' => 'persona de contacto',
        'contact_email' => 'correo de contacto',
        'phone' => 'teléfono',
        'notes' => 'notas',
        'default_hourly_rate' => 'tarifa por hora',
        'icon' => 'icono',
        'owner_user_id' => 'responsable',
    ],
];
