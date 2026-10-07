<?php

/*
|--------------------------------------------------------------------------
| Documentos de RR. HH. con lectura registrada (Fase 11, R2; D-354)
|--------------------------------------------------------------------------
| BORRADORES pendientes de revisión por la asesoría laboral. RR. HH. publica el texto definitivo
| desde /personas/documentos; cada versión nueva pide otra lectura a la plantilla. Lo que va entre
| corchetes lo tiene que completar la empresa.
*/

return [
    'register_protocol' => [
        'title' => 'Registro de jornada: cómo se lleva en Audax Studio',
        'body' => <<<'MD'
> **Borrador pendiente de revisión por la asesoría laboral.** Este texto no es definitivo.

## Para qué es este documento

El artículo 34.9 del Estatuto de los Trabajadores obliga a registrar cada día la hora de inicio y de fin de la jornada de cada persona. Este documento explica cómo lo hacemos en Audax Studio desde el [fecha de implantación], cuando dejamos de usar Woffu y pasamos a la app Audax Proyectos.

**Cómo se ha decidido:** [no hay representación legal de los trabajadores en la empresa, así que lo decide la dirección y lo comunica a toda la plantilla con este documento / se ha consultado a la representación legal el dd/mm/aaaa].

## A quién se aplica

- A toda la plantilla con contrato laboral, a jornada completa o parcial, presencial o a distancia.
- No a las personas colaboradoras externas (no son plantilla) ni a quien la empresa haya declarado no sujeto con un motivo escrito (por ejemplo, un socio que no es asalariado).

## Cómo se ficha

- Cada persona ficha **ella misma y en el momento**, con el botón de la cabecera de la app (en el ordenador o en el móvil): la entrada, el inicio y la vuelta de la comida y la salida.
- Al entrar y al volver de la comida se indica si se trabaja **presencial o a distancia**.
- La hora la pone **el servidor**, nunca el dispositivo. Sin conexión no se puede fichar.
- La pausa de la comida **no es tiempo de trabajo** y se ficha. Las pausas breves no se fichan.
- Si se te olvida fichar, la app te lo recuerda; **nunca ficha por ti** ni cierra una jornada sola.
- No se usa geolocalización ni datos biométricos.

## Qué pasa si hay un error

- Nada se borra ni se sobrescribe. Si un fichaje está mal o falta, se **propone una corrección** desde «Mi jornada», con el motivo.
- Si la propones tú, la acepta tu responsable o RR. HH.; si la propone tu responsable o RR. HH., la aceptas tú. **Nadie acepta lo que ha propuesto.**
- Si no hay acuerdo, o nadie responde en 7 días, la corrección queda **en discrepancia**: cuenta lo fichado y constan las dos versiones.

## Cierre del mes

- El día 1 la app prepara el **resumen del mes anterior** de cada persona: días, horas trabajadas, jornada teórica, diferencia y horas extra con su destino.
- Lo **confirmas** o dices que **no estás de acuerdo**, con el motivo. Un mes confirmado ya no se corrige salvo que tu responsable o RR. HH. lo desconfirme con un motivo, que queda registrado.
- El resumen queda en PDF, con su huella, en «Mi registro».

## Horas extra

- La app registra **todo** el tiempo trabajado por encima de la jornada; nada se limita ni se redondea.
- Tu responsable o RR. HH. decide qué parte es **hora extra** y qué parte es **flexibilidad** (tiempo que se compensa dentro de la jornada pactada) y, si es hora extra, si se **compensa con descanso** (80 minutos por hora) o se **paga**. A tiempo parcial son horas complementarias.
- Cada semana recibes el **resumen de tus horas extra**. El máximo legal es de 80 horas extra al año.

## Quién ve tu registro

- **Tú**, en «Mi jornada» y «Mi registro», y puedes descargarlo cuando quieras en PDF, Excel o CSV.
- **Tu responsable** (el de tu departamento) y **RR. HH.**
- La **Inspección de Trabajo**, si lo pide, y la **representación legal**, si la hubiera, con los datos que marca la ley.
- Nadie más. El registro **no se usa para medir la productividad** ni para otra finalidad.

## Cuánto tiempo se guarda

- **Cuatro años**, contados desde el final de cada mes, como exige la ley. Después se suprime, salvo que haya una reclamación o una inspección abierta.
- El registro anterior, llevado en Woffu, se conserva archivado hasta completar esos cuatro años.

## Cómo se garantiza que nadie lo cambia

- Cada fichaje guarda la huella del anterior (una cadena). Cambiar o quitar uno rompe la cadena y se detecta: la app la comprueba cada noche y guarda un resumen diario fuera de la base de datos.

## Dudas

Para cualquier duda sobre el registro, escribe a [persona de RR. HH. y correo].
MD,
    ],

    'disconnection_policy' => [
        'title' => 'Política de desconexión digital',
        'body' => <<<'MD'
> **Borrador pendiente de revisión por la asesoría laboral.** Este texto no es definitivo.

## Para qué es esta política

El artículo 88 de la Ley Orgánica 3/2018 de protección de datos y garantía de los derechos digitales y el artículo 18 de la Ley 10/2021 de trabajo a distancia reconocen el **derecho a la desconexión digital**: a no estar conectado al trabajo fuera de la jornada. Esta política explica cómo lo aplicamos en Audax Studio.

**Cómo se ha decidido:** [no hay representación legal de los trabajadores en la empresa / se ha dado audiencia a la representación legal el dd/mm/aaaa].

## A quién se aplica

A toda la plantilla, también a quien trabaja a distancia y a quien tiene un puesto de responsabilidad.

## Lo que significa en el día a día

- **Fuera de tu jornada, en tus descansos, vacaciones, permisos y bajas no tienes que responder** correos, mensajes del chat, llamadas ni avisos de la app. No responder no tendrá ninguna consecuencia.
- Quien escribe fuera de horario **no espera respuesta** hasta la siguiente jornada de la otra persona. Si puedes, programa el envío.
- Las **reuniones** se convocan dentro de la jornada y, siempre que se pueda, no a primera hora ni a última.
- Los **avisos de la app** (correo y del navegador) se pueden desactivar o limitar en «Ajustes → Notificaciones». Los pocos que son obligatorios (como el resumen del mes) son informativos: ninguno exige que respondas fuera de tu jornada.

## Excepciones

Solo en casos de **fuerza mayor** o de una **urgencia real** que no pueda esperar (por ejemplo, una caída de la web de un cliente en un servicio contratado de guardia) se puede contactar fuera de la jornada. Si eso te obliga a trabajar, ese tiempo **es trabajo**: fíchalo o propón la corrección, y cuenta para las horas extra.

## Formación y sensibilización

La empresa explicará esta política a cada persona al incorporarse y recordará el uso razonable de las herramientas digitales para evitar la fatiga informática.

## Dudas y quejas

Si crees que no se está respetando tu derecho a la desconexión, escribe a [persona de RR. HH. y correo]. Tu consulta será confidencial.
MD,
    ],
];
