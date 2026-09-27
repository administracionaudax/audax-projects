<?php

/*
| Mensajes del área de informes (backend, Fase 2). Se usan con __('reports.…'). Los textos de la
| interfaz React están en lang/ui/reports*.json. Cada parte de la fase tiene su grupo (r1, r2, r3).
*/

return [
    // R1: índice, dirección, departamento, persona e indicadores de Inicio.
    'r1' => [
        'exports' => [
            'direction' => 'Informe de dirección - :table',
            'department' => 'Informe del departamento :department',
            'person' => 'Informe de :person - detalle diario',
        ],
        'tables' => [
            'clientes' => 'clientes',
            'proyectos' => 'proyectos',
            'departamentos' => 'departamentos',
        ],
        'weekdays' => [
            1 => 'lunes',
            2 => 'martes',
            3 => 'miércoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sábado',
            7 => 'domingo',
        ],
        'columns' => [
            'person' => 'Persona',
            'date' => 'Fecha',
            'weekday' => 'Día',
            'capacity' => 'Capacidad (h)',
            'logged' => 'Horas imputadas',
            'billable' => 'Horas facturables',
            'share' => '% del total',
            'occupancy' => 'Ocupación (%)',
            'billability' => 'Facturabilidad (%)',
            'billable_productivity' => 'Productividad facturable (%)',
            'income' => 'Ingreso estimado (€)',
            'cost' => 'Coste (€)',
            'margin' => 'Rentabilidad (€)',
        ],
    ],
];
