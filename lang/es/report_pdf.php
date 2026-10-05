<?php

/*
| PDF e impresión de los informes (Fase 9, D-140): portada, cifras, tablas y notas de cada
| informe (app/Domain/Reports/Delivery/Documents y resources/views/reports/pdf). El PDF de bolsa
| usa además los textos de reports.r2.pdf (interno) y portal.banks.pdf (portal).
*/

return [
    'total' => 'Total',
    'others' => 'Otros (:count)',
    'no_hours' => 'Sin horas en este periodo con estos filtros.',
    'no_type' => 'Sin tipo',
    'unassigned' => 'Sin asignar',
    'inactive' => '(inactiva)',
    'not_billable' => 'no facturable',
    'internal_project' => 'Proyecto interno',
    'week_of' => 'Semana del :date',
    'definitions' => 'Cómo se calculan las cifras',
    'team_only' => 'Incluye solo las horas que puedes ver: las de tu equipo y las de los proyectos que gestionas.',

    'errors' => [
        'missing' => 'Este informe ya no existe o ya no lo puedes ver.',
        'pdf_unavailable' => 'Ahora mismo no se puede generar el PDF. Prueba dentro de un momento o descárgalo en Excel.',
    ],

    'kinds' => [
        'direction' => 'Informe de dirección',
        'department' => 'Informe de departamento',
        'person' => 'Informe de persona',
        'client' => 'Informe de cliente',
        'project' => 'Informe de proyecto',
        'billing' => 'Horas para facturar',
        'detail' => 'Informe detallado',
        'hours' => 'Horas',
        'project_hours' => 'Horas del proyecto',
        'weekly' => 'Weekly',
    ],

    // Nombre de los ficheros PDF (se pasan a minúsculas sin acentos): «informe-cliente-monto-2026-09.pdf».
    'files' => [
        'direction' => 'informe direccion',
        'department' => 'informe departamento',
        'person' => 'informe persona',
        'client' => 'informe cliente',
        'project' => 'informe proyecto',
        'billing' => 'horas para facturar',
        'detail' => 'informe detallado',
        'hours' => 'horas',
        'project_hours' => 'horas proyecto',
        'weekly' => 'weekly',
    ],

    'cover' => [
        'period' => 'Periodo',
        'generated' => 'Generado',
        'generated_value' => 'El :date por :name',
        'financials' => 'Datos económicos',
        'financials_yes' => 'Incluidos: documento de uso interno',
        'financials_no' => 'No incluidos',
    ],

    'filters' => [
        'persona' => 'Personas',
        'departamento' => 'Departamentos',
        'cliente' => 'Cliente',
        'proyecto' => 'Proyectos',
        'bolsa' => 'Bolsas',
        'tipo' => 'Tipos de tarea',
        'estado' => 'Estado',
        'facturable' => 'Horas',
        'billable_yes' => 'Solo facturables',
        'billable_no' => 'Solo no facturables',
    ],

    'period' => [
        'week' => 'Semana del :from al :to',
        'quarter' => ':quarter.º trimestre de :year',
        'year' => 'Año :year',
        'range' => 'Del :from al :to',
        'since' => 'Desde el :from',
        'until' => 'Hasta el :to',
        'all' => 'Todas las fechas',
    ],

    'months' => [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ],

    'sections' => [
        'kpis' => 'Cifras clave',
    ],

    'kpi' => [
        'tasks' => '{1} :count tarea completada|[2,*] :count tareas completadas',
        'no_data' => 'Sin datos',
        'margin_pct' => ':pct del ingreso',
    ],

    // Las mismas definiciones que la interfaz (lang/ui/reports.json, reports.metric.*).
    'metrics' => [
        'capacity' => ['label' => 'Capacidad', 'definition' => 'Suma de las horas de jornada de las personas del informe en el periodo, según su horario, sin festivos ni ausencias.'],
        'logged' => ['label' => 'Horas imputadas', 'definition' => 'Suma de todas las horas imputadas en el periodo, en cualquier estado.'],
        'billable' => ['label' => 'Horas facturables', 'definition' => 'Horas imputadas marcadas como facturables.'],
        'occupancy' => ['label' => 'Ocupación', 'definition' => 'Horas imputadas / capacidad.'],
        'pace' => ['label' => 'Ritmo', 'definition' => 'En un periodo en curso, horas imputadas / capacidad transcurrida hasta ayer.'],
        'billability' => ['label' => 'Facturabilidad', 'definition' => 'Horas facturables / horas imputadas.'],
        'billable_productivity' => ['label' => 'Productividad facturable', 'definition' => 'Horas facturables / capacidad.'],
        'estimation' => ['label' => 'Precisión de estimación', 'definition' => 'Horas estimadas / horas reales de las tareas completadas en el periodo, sin contar nada dos veces: una tarea con subtareas estimadas cuenta por sus subtareas; si ninguna está estimada, cuenta la tarea con las horas de sus subtareas.'],
        'in_bank' => ['label' => 'Dentro de bolsa', 'definition' => 'Horas de las entradas con bolsa que caben en su total contratado.'],
        'overage' => ['label' => 'Horas en exceso', 'definition' => 'Horas imputadas por encima del total de sus bolsas, asignadas por fecha.'],
        'income' => ['label' => 'Ingreso estimado', 'definition' => 'Horas facturables × tarifa (bolsa > proyecto > cliente > persona). En bolsas con precio, lo que va dentro es su parte proporcional del precio y el exceso se valora a tarifa. En precio cerrado, el importe se reparte según el avance.'],
        'cost' => ['label' => 'Coste', 'definition' => 'Horas imputadas × coste por hora de cada persona (el congelado al aprobar, si existe).'],
        'margin' => ['label' => 'Rentabilidad', 'definition' => 'Ingreso estimado − coste, y el margen en porcentaje sobre el ingreso.'],
    ],

    'columns' => [
        'person' => 'Persona',
        'project' => 'Proyecto',
        'client' => 'Cliente',
        'task' => 'Tarea',
        'tasks' => 'Tareas',
        'type' => 'Tipo de tarea',
        'bank' => 'Bolsa',
        'status' => 'Estado',
        'validity' => 'Vigencia',
        'date' => 'Fecha',
        'weekday' => 'Día',
        'week' => 'Semana',
        'month' => 'Mes',
        'description' => 'Descripción',
        'hours' => 'Horas',
        'logged' => 'Imputadas',
        'billable' => 'Facturables',
        'share' => '% del total',
        'billability' => 'Facturab.',
        'capacity' => 'Capacidad',
        'day_capacity' => 'Jornada',
        'occupancy' => 'Ocupación',
        'pace' => 'Ritmo',
        'in_bank' => 'Dentro',
        'overage' => 'Exceso',
        'pending' => 'Sin aprobar',
        'contracted' => 'Contratadas',
        'remaining' => 'Saldo',
        'period_hours' => 'En el periodo',
        'consumed_pct' => 'Consumo',
        'estimated' => 'Estimadas',
        'actual' => 'Reales',
        'deviation' => 'Desviación',
        'income' => 'Ingreso',
        'cost' => 'Coste',
        'margin' => 'Rentabilidad',
        'amount' => 'Importe',
        'pricing' => 'Valoración',
        'assignee' => 'Responsable',
        'due_date' => 'Fecha límite',
        'days_overdue' => 'Días de retraso',
        'milestone' => 'Hito',
    ],

    'direction' => [
        'agency' => 'Toda la agencia',
        'limited' => 'Limitado a los departamentos que diriges.',
        'by_department' => 'Reparto por departamento',
        'top_clients' => 'Clientes con más horas',
        'top_projects' => 'Proyectos con más horas',
        'at_risk' => 'Bolsas en riesgo',
        'at_risk_lead' => 'Bolsas abiertas con el :threshold % o más consumido, agotadas o con exceso.',
        'no_banks_at_risk' => 'Ninguna bolsa en riesgo.',
        'overdue' => 'Tareas vencidas',
        'overdue_more' => 'Y :count más: el listado completo, en Excel.',
        'no_overdue' => 'Ninguna tarea vencida.',
    ],

    'department' => [
        'members' => 'Personas del departamento',
        'members_lead' => 'Ocupación y facturabilidad contra la capacidad del periodo; el ritmo, contra la transcurrida hasta ayer.',
        'by_client' => 'Reparto por cliente',
        'no_members' => 'El departamento no tiene personas.',
    ],

    'person' => [
        'by_client' => 'Por cliente',
        'by_project' => 'Por proyecto',
        'by_type' => 'Por tipo de tarea',
        'unlogged' => 'Días sin imputar',
        'unlogged_lead' => 'Días con jornada y sin ninguna hora, hasta ayer.',
        'no_unlogged' => 'Ningún día sin imputar.',
        'days' => 'Detalle diario',
    ],

    'client' => [
        'limited' => 'Incluye solo los proyectos del cliente que gestionas.',
        'projects' => 'Resumen por proyecto',
        'projects_lead' => '«Dentro» solo aplica a los proyectos con horas en bolsas.',
        'banks' => 'Bolsas',
        'banks_lead' => 'Las abiertas y las que tienen horas en el periodo, con su consumo total.',
        'no_banks' => 'Ninguna bolsa abierta ni con horas en el periodo.',
        'timeline_month' => 'Horas por mes',
        'timeline_week' => 'Horas por semana',
    ],

    'project' => [
        'billing' => 'Facturación',
        'budget' => 'Presupuesto de horas',
        'estimates_by_type' => 'Estimado frente a real por tipo',
        'estimates' => 'Estimado frente a real por tarea',
        'estimates_lead' => 'Las tareas con más horas; las subtareas, con «↳».',
        'estimates_more' => 'Y :count tareas más: la tabla completa, en Excel.',
        'no_estimates' => 'Ninguna tarea estimada.',
        'no_tasks' => 'Sin tareas.',
        'by_person' => 'Horas por persona',
        'by_type' => 'Horas por tipo de tarea',
        'weekly' => 'Horas por semana',
        'status' => 'Estado de las tareas',
        'overdue_tasks' => '{1} :count tarea abierta vencida.|[2,*] :count tareas abiertas vencidas.',
        'milestones' => 'Hitos',
        'no_milestones' => 'Sin hitos.',
        'milestone_done' => 'Completado',
        'milestone_overdue' => 'Vencido',
        'milestone_pending' => 'Pendiente',
    ],

    'billing' => [
        'entries' => ':count entradas',
        'summary' => 'Resumen por proyecto y bolsa',
        'summary_lead' => 'Dentro de bolsa y exceso por separado; «Sin aprobar», las horas aún sin aprobar.',
        'entries_title' => 'Detalle de las horas',
        'entries_more' => 'Y :count entradas más: el detalle completo, en Excel o CSV.',
        'pending_definition' => 'Horas imputadas que aún no están aprobadas: pueden cambiar.',
        'pricing' => [
            'bank_price' => 'Precio de la bolsa',
            'hourly' => 'Por horas',
            'person_rates' => 'Tarifa de cada persona',
            'fixed_price' => 'Precio cerrado',
            'internal' => 'Interno',
        ],
    ],

    'detail' => [
        'title' => 'Horas :measure por :rows y :columns',
        'layout' => 'Tabla',
        'layout_value' => ':rows × :columns, horas :measure',
        'table' => 'Tabla',
    ],

    'hours' => [
        'title' => 'Entradas de horas',
        'entries' => 'Entradas',
        'more' => 'Y :count entradas más: el listado completo, en Excel o CSV.',
    ],

    'project_hours' => [
        'own_only' => 'Incluye solo tus horas: no puedes ver las del resto del proyecto.',
    ],

    'hour_bank' => [
        'export_name' => 'Consumo bolsa :code :bank',
        'columns' => [
            'date' => 'Fecha',
            'person' => 'Persona',
            'task' => 'Tarea',
            'in_bank' => 'Dentro de la bolsa (horas)',
            'overage' => 'Exceso (horas)',
            'description' => 'Descripción',
            'in_bank_minutes' => 'Minutos dentro de la bolsa',
            'overage_minutes' => 'Minutos en exceso',
        ],
    ],
];
