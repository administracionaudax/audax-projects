<?php

/*
|--------------------------------------------------------------------------
| Informes (Fase 2): textos del servidor. Cada área en su grupo (r1, r2, r3).
|--------------------------------------------------------------------------
*/

return [
    // R3: informe detallado, exportación de horas y resumen semanal por email (D-047).
    'r3' => [
        'measures' => [
            'imputadas' => 'imputadas',
            'facturables' => 'facturables',
            'dentro' => 'dentro de bolsa',
            'exceso' => 'en exceso',
        ],

        'detail' => [
            'corner' => ':rows / :columns (horas)',
            'total' => 'Total',
            'week' => 'Sem. :date',
            'truncated' => 'Tabla recortada: se muestran las :rows filas y las :columns columnas con más horas. Los totales incluyen todas.',
            'filename' => 'Horas :measure por :rows y :columns del :from al :to',
        ],

        'hours' => [
            'filename' => 'Horas del :from al :to',
            'project_filename' => 'Horas :code',
            'truncated' => 'Exportación recortada a las primeras :count entradas: acota el periodo o los filtros para exportar el resto.',
            'columns' => [
                'date' => 'Fecha',
                'person' => 'Persona',
                'client' => 'Cliente',
                'project' => 'Proyecto',
                'bank' => 'Bolsa',
                'task' => 'Tarea',
                'type' => 'Tipo de tarea',
                'hours' => 'Horas',
                'in_bank' => 'Dentro de bolsa (horas)',
                'overage' => 'Exceso (horas)',
                'billable' => 'Facturable',
                'status' => 'Estado',
                'description' => 'Descripción',
                'rate' => 'Tarifa (€/h)',
                'rate_snapshot' => 'Tarifa congelada (€/h)',
                'cost_snapshot' => 'Coste congelado (€/h)',
                'income' => 'Ingreso estimado (€)',
                'cost' => 'Coste (€)',
            ],
        ],

        'settings' => [
            'low_below_high' => 'La ocupación baja tiene que ser menor que la alta.',
            'range' => 'Indica un porcentaje entero entre :min y :max.',
            'attributes' => [
                'low' => 'ocupación baja',
                'high' => 'ocupación alta',
            ],
        ],

        'digest' => [
            'title' => 'Resumen semanal del :from al :to',
            'subject' => 'Resumen semanal de productividad: del :from al :to',
            'greeting' => 'Hola, :name:',
            'intro_agency' => 'Esto es lo que conviene revisar de la agencia en la semana del :from al :to.',
            'intro_team' => 'Esto es lo que conviene revisar de tu equipo en la semana del :from al :to.',
            'unlogged' => 'Días sin imputar',
            'unlogged_item' => ':name: :days',
            'high' => 'Ocupación por encima del :threshold %',
            'low' => 'Ocupación por debajo del :threshold %',
            'occupancy_item' => ':name: :occupancy (:logged de :capacity)',
            'banks' => 'Bolsas en riesgo',
            'bank_item' => ':name: :consumed consumido, quedan :remaining',
            'bank_item_overage' => ':name: :consumed consumido, con :overage de exceso',
            'overdue' => 'Tareas vencidas (:count)',
            'overdue_item' => '«:title» (:project), de :assignee, vencía el :date',
            'overdue_item_unassigned' => '«:title» (:project), sin responsable, vencía el :date',
            'more' => 'y :count más',
            'action' => 'Ver el informe de la semana',
            'settings_hint' => 'Los umbrales de ocupación y este resumen se configuran en Administración › Ajustes.',
            'salutation' => "Un saludo,\n:company",
            'count_unlogged' => '{1} :count persona con días sin imputar|[2,*] :count personas con días sin imputar',
            'count_occupancy' => '{1} :count persona fuera de los umbrales de ocupación|[2,*] :count personas fuera de los umbrales de ocupación',
            'count_banks' => '{1} :count bolsa en riesgo|[2,*] :count bolsas en riesgo',
            'count_overdue' => '{1} :count tarea vencida|[2,*] :count tareas vencidas',
        ],
    ],
];
