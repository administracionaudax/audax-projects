<?php

/*
| Audios, adjuntos, transcripciones y búsqueda del chat (Fase 6, área C3). Se usan con
| __('chat_media.…'). Los textos de la interfaz están en lang/ui/chat-media.json.
*/

return [
    'errors' => [
        'audio_type' => 'El audio tiene un formato que no se admite (webm, ogg, m4a, mp4, mp3 o wav).',
        'audio_too_big' => 'El audio puede pesar como máximo :max MB.',
        'duration_required' => 'Falta la duración del audio. Vuelve a grabarlo.',
        'duration_too_short' => 'El audio es demasiado corto.',
        'duration_too_long' => 'Un audio puede durar como máximo :max.',
        'duration_mismatch' => 'La duración del audio no coincide con el archivo. Vuelve a grabarlo.',
        'parent' => 'El mensaje al que respondes no es válido.',
    ],
    'attributes' => [
        'body' => 'mensaje',
        'files' => 'archivos',
        'audio' => 'audio',
        'duration_ms' => 'duración del audio',
        'max_audio_seconds' => 'duración máxima de los audios',
    ],
    'admin' => [
        'retried' => 'Transcripción relanzada.',
        'failed_again' => 'Se ha relanzado, pero ha vuelto a fallar: :error',
        'retried_many' => '{0} No había transcripciones fallidas que relanzar.|{1} Se ha relanzado 1 transcripción fallida.|[2,*] Se han relanzado :count transcripciones fallidas.',
        'already_done' => 'Esa transcripción ya está hecha.',
        'busy' => 'Esa transcripción se está procesando ahora mismo. Espera a que termine o falle.',
    ],
    'search' => [
        'untitled' => 'Conversación',
        'direct' => 'Conversación directa',
        'file' => 'Archivo: :name',
        'audio' => 'Audio',
    ],
];
