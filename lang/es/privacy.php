<?php

/*
| Privacidad y RGPD (SPEC §15, D-075). El texto informativo por defecto es un BORRADOR pendiente
| de revisión por el asesor del propietario: el admin lo sustituye desde /admin/privacidad y cada
| cambio pide una nueva lectura a la plantilla.
*/

return [
    'default_notice' => <<<'MD'
> **Borrador pendiente de revisión por el asesor.** Este texto no es definitivo.

## Información sobre el tratamiento de tus datos en Audax Proyectos

**Responsable:** Audax Studio. Para cualquier cuestión sobre tus datos puedes escribir a [correo de contacto de privacidad].

**Para qué se usan tus datos:**
- organizar los proyectos y las tareas de la agencia,
- registrar las horas que dedicas a cada proyecto y facturarlas a los clientes,
- planificar la carga de trabajo del equipo teniendo en cuenta jornadas, festivos y ausencias,
- comunicarnos con el chat interno.

**Base legal:** la ejecución de tu contrato de trabajo y el interés legítimo de la empresa en organizar el trabajo, dentro de las facultades de control del artículo 20.3 del Estatuto de los Trabajadores.

**Qué datos se tratan:**
- tus datos de identificación y de contacto profesionales,
- las horas que imputas, tus tareas y tus comentarios,
- tus ausencias: solo el tipo y las fechas, nunca diagnósticos ni justificantes médicos,
- tus mensajes del chat y tus audios, con su transcripción, que se hace en el propio servidor de la empresa,
- los registros de acceso a la aplicación: fecha, dirección IP y navegador.

**Quién los ve:**
- Cada persona ve sus propios datos.
- Tus responsables y los gestores de tus proyectos ven lo necesario para organizar el trabajo.
- Los clientes solo ven, en su portal, las horas aprobadas de sus propios proyectos, y tu nombre únicamente si así se configura.
- Nada se cede a terceros ni sale del servidor de la empresa, tampoco la transcripción de los audios.

**Cuánto tiempo se guardan:**
- las horas, durante los plazos legales de conservación de la documentación contable y laboral,
- el resto, según los plazos de retención que la empresa tiene configurados y que puedes consultar aquí.

**Tus derechos:**
- Puedes pedir el acceso, la rectificación, la supresión, la limitación, la oposición y la portabilidad de tus datos.
- Desde «Mis datos» puedes descargar una copia de tus datos personales.
- Si no estás de acuerdo con cómo se tratan, puedes reclamar ante la Agencia Española de Protección de Datos (www.aepd.es).
MD,

    'exports' => [
        'status' => [
            'pending' => 'En cola',
            'processing' => 'Preparándose',
            'ready' => 'Lista para descargar',
            'failed' => 'Ha fallado',
            'expired' => 'Caducada',
        ],
    ],
];
