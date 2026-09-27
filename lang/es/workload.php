<?php

/*
| Vista «Carga» (SPEC §9, D-051, D-052). Se usan con __('workload.…').
*/

return [
    'flash' => [
        'updated' => 'Tarea «:task» actualizada. La carga se ha recalculado.',
        'added_member' => 'Tarea «:task» asignada a :name, que pasa a ser miembro de :project para poder imputar. La carga se ha recalculado.',
    ],
    'errors' => [
        'person_out_of_scope' => 'No puedes ver la carga de esta persona.',
        'out_of_scope' => 'Esta tarea no está en tu vista de carga.',
        'cannot_reassign' => 'No puedes cambiar el responsable de esta tarea desde la vista Carga. Ábrela en su proyecto o pídeselo a quien reparte el trabajo.',
        'assignee_out_of_scope' => 'Solo puedes asignarla a personas de tu equipo o, si gestionas el proyecto, a sus miembros.',
    ],
];
