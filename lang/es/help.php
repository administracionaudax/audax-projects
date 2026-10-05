<?php

/*
| Centro de ayuda y sugerencias (Fase 10, entrega 10.7): avisos de las acciones, errores, las
| novedades automáticas y las notificaciones de las sugerencias.
*/

return [
    'attributes' => [
        'title' => 'título',
        'body' => 'detalle',
        'board' => 'tablero',
        'category' => 'categoría',
        'comment' => 'comentario',
    ],

    'reorder_stale' => 'La lista ha cambiado mientras tanto: vuelve a cargar la página y ordénala de nuevo.',

    'settings' => [
        'saved' => 'Ajustes de la ayuda guardados.',
        'manual_pdf' => 'El manual tiene que ser un PDF.',
        'manual_too_big' => 'El manual no puede pasar de :max MB.',
    ],

    'releases' => [
        'default_summary' => 'Cambios en curso esta semana. La nota de versión se cerrará automáticamente el viernes.',
        'in_progress_subtitle' => 'Cambios en progreso durante esta semana.',
        'title' => 'Notas de lanzamiento :version',
        'created' => 'Versión creada.',
        'updated' => 'Versión actualizada.',
        'hidden' => 'Versión ocultada.',
        'duplicate' => 'Ya existe una versión con esa serie, mes y semana.',
    ],

    'updates' => [
        'created' => 'Actualización puntual creada.',
        'updated' => 'Actualización puntual actualizada.',
        'deleted' => 'Actualización puntual eliminada.',
    ],

    'tutorials' => [
        'created' => 'Tutorial creado.',
        'updated' => 'Tutorial actualizado.',
        'deleted' => 'Tutorial eliminado.',
        'reordered' => 'Orden de los tutoriales guardado.',
        'too_big' => 'El vídeo supera el límite de 200 MB permitido para tutoriales.',
        'chunk_invalid' => 'Un trozo del vídeo no es válido: vuelve a intentarlo.',
        'chunk_out_of_order' => 'La subida se ha desordenado (ya hay :received bytes): vuelve a intentarlo.',
        'incomplete' => 'El vídeo no ha terminado de subirse: vuelve a intentarlo.',
        'not_video' => 'El archivo no es un vídeo que se pueda reproducir en el navegador (MP4, WebM, MOV u OGG).',
        'upload_missing' => 'La subida del vídeo ha caducado o no es tuya: vuelve a elegir el vídeo.',
        'video_required' => 'Selecciona un vídeo antes de guardar.',
    ],

    'faq' => [
        'created' => 'Pregunta frecuente creada.',
        'updated' => 'Pregunta frecuente actualizada.',
        'deleted' => 'Pregunta frecuente eliminada.',
        'reordered' => 'Orden de las preguntas guardado.',
        'answer_required' => 'La respuesta es obligatoria.',
        'section_created' => 'Sección creada.',
        'section_updated' => 'Sección actualizada.',
        'section_deleted' => 'Sección eliminada.',
        'sections_reordered' => 'Orden de las secciones guardado.',
        'section_not_empty' => 'Mueve o elimina antes las preguntas de esta sección.',
    ],

    'suggestions' => [
        'created' => 'Sugerencia publicada.',
        'updated' => 'Sugerencia actualizada.',
        'deleted' => 'Sugerencia eliminada.',
        'body_required' => 'Cuenta en el detalle en qué consiste la sugerencia.',
        'comment_required' => 'Escribe algo o adjunta un archivo.',
        'comment_created' => 'Comentario publicado.',
        'comment_updated' => 'Comentario actualizado.',
        'comment_deleted' => 'Comentario eliminado.',
        'parent_missing' => 'El comentario al que respondes ya no existe.',
        'status_saved' => 'Estado guardado.',
        'status_unchanged' => 'El estado ya era ese: añade una nota para dejar constancia.',
        'board_inactive' => 'El tablero elegido no existe o está oculto.',
        'category_inactive' => 'La categoría elegida no existe o está oculta.',
        'category_board' => 'La categoría no es de ese tablero.',
        'board_created' => 'Tablero creado.',
        'board_updated' => 'Tablero actualizado.',
        'board_deleted' => 'Tablero eliminado.',
        'board_not_empty' => 'El tablero tiene sugerencias: ocúltalo en lugar de eliminarlo.',
        'category_created' => 'Categoría creada.',
        'category_updated' => 'Categoría actualizada.',
        'category_deleted' => 'Categoría eliminada.',
        'bugs_locked' => 'La categoría «Bugs» no se puede eliminar: la usa «Reportar un bug». Puedes ocultarla.',
        'reordered' => 'Orden guardado.',
    ],

    'notifications' => [
        'status_changed' => 'Tu sugerencia «:post» pasa a «:status»',
        'replied_post' => ':actor ha comentado tu sugerencia «:post»',
        'replied_comment' => ':actor ha respondido a tu comentario en «:post»',
        'mentioned_post' => ':actor te ha mencionado en la sugerencia «:post»',
        'mentioned_comment' => ':actor te ha mencionado en un comentario de «:post»',
    ],
];
