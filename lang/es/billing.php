<?php

/*
| Facturación (Fase 12, F1; D-380 a D-399): textos del backend. Los del frontend, en lang/ui/billing.json.
*/

return [
    'enums' => [
        'tax_regime' => [
            'general' => 'España (IVA)',
            'intra_eu' => 'Intracomunitario (inversión del sujeto pasivo)',
            'export' => 'Fuera de la UE (exportación)',
            'exempt' => 'Exento',
            'not_subject' => 'No sujeto',
        ],
        'payment_method' => [
            'transfer' => 'Transferencia',
            'direct_debit' => 'Domiciliación (SEPA)',
            'card' => 'Tarjeta',
            'cash' => 'Efectivo',
            'other' => 'Otra',
        ],
        'document_kind' => [
            'invoice' => 'Factura',
            'credit_note' => 'Rectificativa',
        ],
        'collection_status' => [
            'paid' => 'Cobrada',
            'partial' => 'Cobrada en parte',
            'unpaid' => 'Pendiente',
            'overdue' => 'Vencida',
            'cancelled' => 'Anulada',
            'draft' => 'Borrador',
        ],
        'link_method' => [
            'f_code' => 'Código F',
            'holded_project' => 'Proyecto de Holded',
            'rectified' => 'De la factura rectificada',
            'manual' => 'A mano',
        ],
        'sale_kind' => [
            'bolsa' => 'Bolsa',
            'precio_cerrado' => 'Precio cerrado',
            'fee' => 'Fee mensual',
            'horas' => 'Por horas',
        ],
    ],

    'holded' => [
        'errors' => [
            'not_configured' => 'Falta la clave de la API de Holded (HOLDED_API_KEY en el .env del servidor): no se puede sincronizar.',
            'unauthorized' => 'Holded ha rechazado la clave de la API (error 401). Revisa HOLDED_API_KEY: puede estar mal copiada o revocada.',
            'plan' => 'El plan de Holded no permite usar la API (error 402). Hace falta un plan con acceso a la API.',
            'forbidden' => 'La clave de Holded no tiene permiso para leer :path (error 403). Dale permiso de lectura en Holded.',
            'not_found' => 'Holded no reconoce :path (error 404).',
            'rate_limited' => 'Holded ha limitado las peticiones (error 429) y no ha bastado con esperar: se reintentará en la próxima sincronización.',
            'server' => 'Holded no responde bien (error :status en :path). Se reintentará en la próxima sincronización.',
            'unexpected' => 'Holded ha respondido con un error :status en :path.',
            'connection' => 'No se ha podido conectar con Holded (:path): tiempo agotado o sin red.',
            'invalid_pdf' => 'Holded no ha devuelto un PDF válido en :path.',
            'busy' => 'Ya hay una sincronización con Holded en marcha. Espera a que termine.',
        ],
    ],

    'invoices' => [
        'linked' => 'Factura enlazada con :project.',
        'unlinked' => 'Enlace quitado.',
        'errors' => [
            'bank_not_in_project' => 'Esa bolsa no es de ese proyecto.',
            'internal_project' => 'Un proyecto interno no se factura.',
            'automatic_link' => 'Este enlace es automático: vuelve en cada sincronización. Corrige el código F o el proyecto en Holded.',
        ],
    ],

    'contacts' => [
        'assigned' => ':contact es ahora el cliente :client.',
        'ignored' => ':contact descartado: no es un cliente de Audax.',
        'reset' => ':contact vuelve a casarse solo.',
    ],

    'settings' => [
        'saved' => 'Datos del emisor guardados.',
        'sync_started' => 'Sincronización con Holded en marcha. El resultado sale abajo en unos minutos.',
    ],

    'profile' => [
        'saved' => 'Datos fiscales guardados.',
    ],

    'report' => [
        'title' => 'Vendido frente a real',
        'kicker' => 'Informe de facturación',
        'export_name' => 'vendido-frente-a-real',
        'empty' => 'No hay ventas en este periodo con estos filtros.',
        'months' => '{1} :count mes|[2,*] :count meses',
        'columns' => [
            'kind' => 'Tipo',
            'client' => 'Cliente',
            'project' => 'Proyecto',
            'unit' => 'Unidad de venta',
            'manager' => 'Responsable',
            'sold_hours' => 'Horas vendidas',
            'real_hours' => 'Horas reales',
            'pending_hours' => 'Pendientes de aprobar',
            'deviation_hours' => 'Desviación (h)',
            'consumption' => 'Consumo (%)',
            'status' => 'Estado',
            'sold_amount' => 'Vendido (€)',
            'invoiced' => 'Facturado sin IVA (€)',
            'invoiced_total' => 'Facturado con IVA (€)',
            'collected' => 'Cobrado (€)',
            'outstanding' => 'Pendiente de cobro (€)',
            'to_invoice' => 'Pendiente de facturar (€)',
            'cost' => 'Coste (€)',
            'margin' => 'Margen (€)',
            'margin_pct' => 'Margen (%)',
            'effective_rate' => 'Precio efectivo (€/h)',
            'sold_minutes' => 'Minutos vendidos',
            'real_minutes' => 'Minutos reales',
            'pending_minutes' => 'Minutos pendientes',
        ],
        'status' => [
            'ok' => 'Dentro',
            'risk' => 'En riesgo',
            'over' => 'Pasado',
            'none' => 'Sin horas vendidas',
        ],
        'kpis' => [
            'sold' => 'Horas vendidas',
            'real' => 'Horas reales',
            'deviation' => 'Desviación',
            'invoiced' => 'Facturado (sin IVA)',
            'outstanding' => 'Pendiente de cobro',
            'margin' => 'Margen',
        ],
        'definitions' => [
            'sold' => 'Bolsas: sus horas; precio cerrado: su presupuesto; fee: horas al mes por los meses del periodo. Las bolsas y los precios cerrados se miden enteros.',
            'real' => 'Horas aprobadas o bloqueadas. Las enviadas y en borrador van aparte.',
            'deviation' => 'Horas reales menos horas vendidas, solo de lo que tiene horas vendidas.',
            'invoiced' => 'Base imponible de las facturas de Holded aprobadas y no anuladas, menos sus rectificativas.',
            'margin' => 'Lo vendido (por horas, el valor de las horas a su tarifa) menos el coste de las horas reales.',
        ],
        'filters' => [
            'kind' => 'Tipo de venta',
            'manager' => 'Responsable',
        ],
        'pdf' => [
            'units' => 'Unidades de venta',
            'units_lead' => 'Primero lo pasado y lo que está en riesgo. Las bolsas y los precios cerrados, enteros; los fees y las horas, en el periodo.',
        ],
    ],
];
