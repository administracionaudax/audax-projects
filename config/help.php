<?php

/*
| Centro de ayuda y sugerencias (Fase 10, 10.7): cuotas de las subidas (D-223). El servidor es
| compartido con 38 webs (SPEC §16): ninguna subida puede llenar el disco.
*/

return [
    'uploads' => [
        // Bytes, en MB, de los vídeos de tutoriales a medio subir entre todas las personas. Cada
        // persona tiene como mucho una subida abierta: empezar otra descarta la anterior.
        'tutorial_pending_mb' => (int) env('HELP_TUTORIAL_PENDING_MB', 1024),
        // Lo que puede tener subido cada persona en adjuntos de sugerencias y comentarios, en MB.
        'suggestion_user_mb' => (int) env('HELP_SUGGESTION_USER_MB', 250),
        // Espacio libre mínimo en el disco de los adjuntos, en MB, para aceptar una subida.
        'min_free_mb' => (int) env('HELP_UPLOADS_MIN_FREE_MB', 5120),
    ],
];
