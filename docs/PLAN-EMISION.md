# Plan de la emisión propia de facturas: ventas, gastos, libros y VeriFactu

Fecha: 09/10/2026. Rama de referencia: `fase-10`. Es un documento de análisis y diseño: no hay código de la app.

> **Estado (09/10/2026):** **E1 · Emitir facturas: hecha** (rama `emision-e1`, D-417 a D-429; lo construido, en `docs/PLAN-FASE-12.md` §12). E2 a E9, pendientes.

Lo pide D-249 (el propietario decide emitir desde Audax Proyectos y dar de baja Holded) con su actualización del mismo día:
- la emisión propia se construye ya, sin el envío a la AEAT, dando por hecho que se publicará el aplazamiento de VeriFactu a 2028;
- desde la primera factura se cumple el Reglamento de facturación (RD 1619/2012) y se guarda un registro encadenado con huella, inalterable y preparado para VeriFactu;
- VeriFactu completo (envío, QR y declaración responsable) llega en una entrega posterior, antes de la fecha que fije el BOE;
- gastos con foto del ticket o de la factura, leídos por la IA (Gemini) y revisados por una persona.

Documentos de partida: `docs/PLAN-FASE-12.md` (requisitos legales §2, entregas F2 a F8 y preguntas P), `docs/HOLDED-INVENTARIO.md` (funciones H-001 a H-146), `docs/ANALISIS-UX-FACTURACION.md` (navegación y «Más adelante» L1 a L6) y las decisiones D-244, D-245, D-247, D-248, D-249 y D-380 a D-410.

> Aviso. Esto es una investigación técnica, no asesoramiento fiscal. La gestoría tiene que revisar el apartado 2 y las preguntas del apartado 10 antes de la primera factura real.

---

## 1. Resumen y calendario

### 1.1 En seis líneas

1. Primero, emitir: borrador → emitir (número correlativo, PDF archivado, registro con huella) → descargar, duplicar, anular o rectificar. Es la entrega E1, útil en dos semanas.
2. Después, cobrar y enviar (E2), recurrentes y facturar desde el trabajo (E3), libros para la gestoría (E4) y gastos con foto (E5).
3. Holded sigue emitiendo hasta el corte. El corte llega cuando la emisión propia está completa y la gestoría la ha validado con un mes en paralelo.
4. El registro encadenado usa desde el primer día la misma huella SHA-256 y los mismos campos que el registro de alta de VeriFactu (comprobado con los tres ejemplos oficiales de la AEAT). VeriFactu (E7) solo añade el XML, el envío, el QR y la declaración responsable.
5. Los gastos no entran en VeriFactu (solo afecta a las facturas expedidas), pero sí en el libro de facturas recibidas y en la exportación para la gestoría.
6. Riesgo, en una línea: si el BOE no publica el aplazamiento, VeriFactu obliga el 1/1/2027; plan B: no se hace el corte (Holded sigue) o se adelanta E7.

### 1.2 Las dos opciones de corte

| | Opción A · corte el 1/1/2027 | Opción B · corte el 1/4/2027 |
|---|---|---|
| Mes en paralelo | Diciembre de 2026 | Marzo de 2027 |
| Series | Holded cierra F26 y CN26. Audax abre F27 y CN27 en el 0001: no hay que continuar un contador ajeno | Audax continúa F27 y CN27 desde el último número que emitió Holded (traspaso el 31/3, ver 7.1) |
| Trimestre | Empieza con el año y con el 1T: el 4T de 2026 y el resumen anual van enteros en Holded | Empieza con el 2T: el 1T va entero en Holded |
| VeriFactu | Choca con la fecha del 1/1/2027 si el BOE no publica el aplazamiento: se decide el 21/12 (ver abajo) | Da tiempo a tener E7 terminada y probada en el entorno de pruebas de la AEAT antes del corte |
| Holded | Se puede dar de baja en marzo de 2027, después del 347 | Se puede dar de baja en junio de 2027 |
| Riesgo | Calendario apretado en diciembre (fiestas, cierre del año) | Tres meses más de Holded y de doble trabajo |

Recomendación: opción A, con una decisión de seguir o parar el lunes 21/12/2026. El cambio de año es el mejor momento para cambiar de programa: la serie empieza de cero, el trimestre y el ejercicio empiezan limpios y, si Audax tuviera VeriFactu activado en Holded, la opción por VERI\*FACTU dura hasta el 31/12 del año en que se empezó (RD 1007/2023, art. 16.5), así que el 1/1 no deja nada a medias. La opción B queda como plan B automático.

Regla del 21/12/2026 (se repite en 7.3):
- Si el BOE ha publicado el aplazamiento y la gestoría ha validado el mes en paralelo: corte el 1/1/2027.
- Si no hay aplazamiento en el BOE: no hay corte. Holded sigue emitiendo, se adelanta E7 (VeriFactu) y el corte pasa al 1/4/2027 con VeriFactu ya activo.
- Si la gestoría no ha validado: corte el 1/4/2027, aunque haya aplazamiento.

### 1.3 Calendario (opción A)

Empieza el martes 13/10/2026 (el 12 es festivo). Las jornadas son de desarrollo; el calendario deja margen para las revisiones del propietario y la gestoría.

| Semana | Entrega | Qué queda hecho | Módulo |
|---|---|---|---|
| 13/10 – 23/10 | E1 · Emitir facturas ✅ (D-417 a D-429) | Catálogo, series, impuestos, editor, emitir con número y PDF, registro con huella, duplicar, descargar, anular y rectificar | `invoicing` en modo de prueba (solo admins), serie de pruebas |
| 26/10 – 6/11 | E2 · Cobrar, enviar y portal | Cobros (también parciales), por cobrar con antigüedad, envío por email con plantilla, enlace firmado, portal del cliente, recordatorios | Igual |
| 9/11 – 20/11 | E3 · Recurrentes, programadas y facturar desde el trabajo | Recurrentes (y las 16 de Holded importadas en pausa), programadas, «Crear borrador» desde Por facturar con el bloqueo de horas | Igual |
| 23/11 – 27/11 | E4 · Libros y gestoría | Libro de emitidas en el formato de la AEAT, exportaciones, rol Gestoría, cierre de periodo | Igual |
| 30/11 – 11/12 | E5 · Gastos con foto | Gastos, tickets, proveedores, IA, aprobación, libro de recibidas | `expenses` en modo de prueba |
| 1/12 – 18/12 | Mes en paralelo | Cada factura de Holded se rehace en Audax en la serie de pruebas y se compara. La gestoría revisa PDF y libros | — |
| 21/12 | Decisión | Regla del apartado 1.2 | — |
| 28/12 – 4/1 | E6 · Corte | Runbook del apartado 7 | `invoicing` encendido |
| 11/1 – 31/3/2027 | E7 · VeriFactu | XML, envío, QR, declaración responsable, pruebas en el entorno de la AEAT | Interruptor `verifactu` apagado hasta la fecha del BOE |
| Marzo de 2027 | E8 · Baja de Holded | Exportación completa y cancelación | — |
| 2027 | E9 · Factura electrónica B2B | Recepción (UBL) antes de octubre de 2027; emisión antes de octubre de 2028 | — |

---

## 2. Marco legal: lista de comprobación

Fuentes consultadas el 09/10/2026. La columna «Cuándo» dice desde cuándo obliga. «Dónde» es la entrega y el sitio del sistema que lo cumple; «Test» es la prueba que lo demuestra (apartado 8). Las URL están en el anexo A.

### 2.1 Estado de la norma a 09/10/2026

- La fecha vigente de VeriFactu para quien declara el Impuesto sobre Sociedades es el 1/1/2027 (RD 1007/2023, disposición final cuarta, en la redacción del RDL 15/2025; texto consolidado del BOE actualizado el 03/12/2025).
- El aplazamiento a octubre de 2028 es una nota de prensa de Hacienda del 05/10/2026. Los sumarios del BOE del 05, 06, 07, 08 y 09/10/2026 no traen ninguna norma que modifique el RD 1007/2023.
- La Orden HAC/1028/2026 (BOE del 05/10/2026, en vigor el 06/10) regula la solución pública de factura electrónica B2B. No toca VeriFactu.
- Las preguntas frecuentes de la AEAT (actualizadas a 21/07/2026, página del 07/10/2026) y el documento para desarrolladores (v1.3, 04/12/2025) siguen hablando del 1/1/2027 y del 1/7/2027.

### 2.2 Factura completa (RD 1619/2012): obliga ya

| Id | Requisito | Fuente | Dónde | Test |
|---|---|---|---|---|
| L-01 | Número y, en su caso, serie; numeración correlativa dentro de cada serie | RD 1619/2012, art. 6.1.a | E1: contador por serie y año, asignado al emitir en una transacción | T-NUM |
| L-02 | Series separadas obligatorias para las rectificativas (y para las expedidas por el destinatario o por terceros) | Art. 6.1.a | E1: serie CN vinculada a F (D-244) | T-NUM |
| L-03 | Series distintas solo «cuando existan razones que lo justifiquen» | Art. 6.1.a | E1: series F, CN y la de pruebas; ninguna más sin decisión | — |
| L-04 | Fecha de expedición | Art. 6.1.b | E1 | T-PDF |
| L-05 | Nombre o razón social, NIF y domicilio del emisor y del destinatario (NIF-IVA del otro Estado en intracomunitarias) | Art. 6.1.c, d y e | E1: copia del emisor y del cliente en la factura al emitir; validación al emitir | T-SNAP |
| L-06 | Descripción de las operaciones, base, precio unitario sin impuesto y descuentos | Art. 6.1.f | E1: líneas con unidades, precio y descuento | T-PDF |
| L-07 | Tipo impositivo y cuota, por separado; bases desglosadas por tipo | Art. 6.1.g, h y 6.2 | E1: cuadro de impuestos por tipo | T-TOT |
| L-08 | Fecha de la operación si es distinta de la de expedición | Art. 6.1.i | E1: campo opcional «Fecha de la operación» (H-049) | T-PDF |
| L-09 | Referencia a la exención (artículo) | Art. 6.1.j | E1: mención del impuesto «Exento» | T-PDF |
| L-10 | Mención «Inversión del sujeto pasivo» cuando la deba el destinatario (servicios a empresarios de la UE) | Art. 6.1.m | E1: mención automática con el régimen `intra_eu` | T-PDF |
| L-11 | Expedir en el momento de la operación y, a un empresario, antes del día 16 del mes siguiente al devengo | Art. 11 | E3: aviso en Por facturar | — |
| L-12 | Remitir al destinatario en el mismo plazo | Art. 18 | E2: envío por email | — |
| L-13 | Rectificativa cuando falte un requisito, la cuota esté mal o se den las causas del art. 80 de la Ley del IVA; hasta cuatro años desde el devengo; identifica la factura rectificada y el importe de la rectificación | Art. 15 | E1: Anular y Rectificar (D-244), con motivo obligatorio | T-RECT |
| L-14 | Factura electrónica solo con el consentimiento del destinatario, salvo cuando sea obligatoria | Art. 9.2 (redacción del RD 238/2026) | E2: el PDF por email es la práctica aceptada hoy; E9 para la obligatoria | — |
| L-15 | La cuota de IVA en euros si se factura en otra moneda | Art. 12 | Fuera del alcance (solo euros); multimoneda, más adelante | — |
| L-16 | Conservación de las copias de las expedidas y de las recibidas, con acceso sin demora de la AEAT | Arts. 19 a 23 | E1 y E5: disco privado, nunca en una purga, en la copia nocturna | T-RET |
| L-17 | Domicilio y datos de inscripción en el Registro Mercantil en las facturas de una sociedad, con su forma jurídica | Código de Comercio, art. 24.1 | E1: pie del PDF con los datos de `billing_issuer` | T-PDF |
| L-18 | Conservar libros, documentación y justificantes seis años | Código de Comercio, art. 30 | E1 y E5 | T-RET |
| L-19 | Integridad e inalterabilidad de lo registrado (art. 29.2.j LGT, ya en vigor) | Ley 58/2003, art. 29.2.j | E1: facturas emitidas inmutables y registro encadenado con *triggers* | T-INM |

### 2.3 Libros registro del IVA: obligan ya

| Id | Requisito | Fuente | Dónde | Test |
|---|---|---|---|---|
| R-01 | Libro de facturas expedidas: una por una, número y serie, fecha de expedición y de operación, nombre y NIF del destinatario, base, tipo y cuota | Reglamento del IVA (RD 1624/1992), art. 63.3 | E4: libro de emitidas | T-LIB |
| R-02 | Numerar correlativamente las facturas recibidas y justificantes (número de recepción); series separadas si hay razones objetivas | Art. 64.1 | E5: número de recepción por año al aprobar un gasto | T-GAS |
| R-03 | Libro de facturas recibidas: número de recepción, fecha de expedición y de operación, nombre y NIF del proveedor, base, tipo y cuota | Art. 64.4 | E5: libro de recibidas | T-LIB |
| R-04 | Formato electrónico de los libros para quien no lleva el SII: diseños normalizados de la AEAT para personas jurídicas (LSIJ.xlsx, actualizado el 01/01/2026), en XLSX o CSV | Sede de la AEAT, diseños de registro normalizados | E4 | T-LIB |
| R-05 | Solo es deducible el IVA con factura original (completa) o justificante válido | Ley 37/1992 del IVA, art. 97.Uno | E5: «IVA deducible» solo con factura completa o simplificada con el NIF de Audax y la cuota desglosada (RD 1619/2012, art. 7.2) | T-GAS |
| R-06 | Servicios recibidos de empresarios no establecidos (Google, Meta, Adobe…): el sujeto pasivo es Audax (autorrepercusión) | Ley del IVA, art. 84.Uno.2.º | E5: tipo de gasto «Inversión del sujeto pasivo» con cuota repercutida y soportada | T-GAS |

### 2.4 VeriFactu (RD 1007/2023 y Orden HAC/1177/2024): obliga el 1/1/2027 salvo que el BOE lo aplace

| Id | Requisito | Fuente | Antes de E7 | Con E7 | Test |
|---|---|---|---|---|---|
| V-01 | Generar un registro de alta automático, a la vez o justo antes de expedir cada factura | RD 1007/2023, art. 9 | Sí: se inserta en la misma transacción que la emisión | Igual | T-CAD |
| V-02 | Contenido del registro de alta (emisor, número, fechas, tipo, descripción, importes, desglose, encadenamiento, sistema, fecha y hora) | Art. 10; Orden, anexo; XSD `SuministroInformacion.xsd` v1.0 | Se guardan todos los campos en `payload` | Se genera el XML | T-XSD |
| V-03 | Registro de anulación solo para una factura expedida por error que no debió existir | Art. 11; FAQ de desarrolladores §17 | Sí, solo admin y con aviso (D-244) | Se envía | T-CAD |
| V-04 | Huella SHA-256 sobre los campos de la especificación, en su orden, `campo=valor&…`, UTF-8, hexadecimal en mayúsculas, 64 caracteres | Orden, art. 13; especificación de la huella v0.1.2 (27/08/2024) | Sí, idéntica | Igual | T-HASH |
| V-05 | Encadenamiento con el registro anterior del mismo sistema, en orden cronológico de generación; el primero lleva `PrimerRegistro = S` | Especificación de la huella, §5; servicios web v1.0.3, anexo II | Sí | Igual | T-CAD |
| V-06 | Integridad, trazabilidad e inalterabilidad: una corrección es un registro nuevo, nunca un cambio | RD 1007/2023, art. 8.2 | Sí, con *triggers* | Igual | T-INM |
| V-07 | Firma electrónica (XAdES) de los registros | Orden, art. 14 | No (modalidad VERI\*FACTU) | No: el art. 16.3 del RD y el art. 3 de la Orden la eximen en VERI\*FACTU | — |
| V-08 | Registro de eventos | Orden, art. 9 | No | No: el art. 3 de la Orden exime del art. 9 a VERI\*FACTU (la auditoría de la app sigue) | — |
| V-09 | Remisión inmediata, control de flujo (espera inicial de 60 s, actualizada en cada respuesta; envío al cumplirse la espera o al llegar a 1.000 registros) | Orden, art. 16.2; servicios web v1.0.3, §6.4.4.1 | — | Cola `verifactu` | T-FLOW |
| V-10 | Incidencia técnica: remitir en cuanto se pueda, en orden, avisar en el mensaje, reintentar al menos una vez por hora y mostrar cuántos registros faltan | Orden, art. 16.4 | — | Sí | T-FLOW |
| V-11 | Certificado electrónico cualificado admitido por la AEAT para el servicio web (persona jurídica, representante o sello) | Servicios web v1.0.3, §4.1 y §4.3; Orden, art. 5 | — | Instalado por el propietario (5.8) | T-AEAT |
| V-12 | QR de 30 × 30 a 40 × 40 mm, ISO/IEC 18004:2015, corrección M, al principio de la factura (arriba y centrado si es vertical), con «QR tributario:» encima y «VERI\*FACTU» o «Factura verificable en la sede electrónica de la AEAT» debajo, con letra igual o mayor que el resto; margen blanco de 2 mm (se recomiendan 6) | Orden, arts. 20 y 21; especificación del QR v0.5.0 (10/12/2025), §3 | Hueco reservado en la plantilla, sin QR | Sí | T-QR |
| V-13 | URL del QR: `…/wlpl/TIKE-CONT/ValidarQR?nif=&numserie=&fecha=DD-MM-AAAA&importe=` con *URL encoding* UTF-8; pruebas en `prewww2.aeat.es`, producción en `www2.agenciatributaria.gob.es` | Especificación del QR, §4 a §6 | — | Sí | T-QR |
| V-14 | Declaración responsable del productor, visible en el propio sistema (por ejemplo, en Ayuda) y legible fuera; si el programa lo desarrolla la propia empresa, la firma ella | RD 1007/2023, art. 13; Orden, art. 15; FAQ de la AEAT (actualizadas a 21/07/2026) | — | Sí (5.10) | T-DR |
| V-15 | Opción por VERI\*FACTU: empieza con el primer envío sistemático y dura, como mínimo, hasta el 31/12 de ese año | RD 1007/2023, art. 16.5 | — | Activar a principio de año | — |
| V-16 | Conservación de los registros: en VERI\*FACTU no se regula (los conserva la AEAT), pero la factura completa se conserva y es lógico conservar también los registros | FAQ de desarrolladores §13 | Sí, para siempre | Igual | T-RET |
| V-17 | Facturas «de prueba» en producción: son reales; se anulan con un registro de anulación y conviene una serie especial (por ejemplo, PRU) | FAQ de desarrolladores, pp. 21-22 | Serie de pruebas en una instalación aparte (5.2) | Igual | — |
| V-18 | Números de serie únicos para siempre: nunca reutilizar el número de una factura de prueba | FAQ de desarrolladores, p. 16 | Los contadores no retroceden | Igual | T-NUM |
| V-19 | La fecha de expedición no puede ser anterior al 28/10/2024 | Validaciones y errores v1.2.2 | — | Validación | — |
| V-20 | Tolerancia de la AEAT: cuota = base × tipo / 100 ± 10 €; CuotaTotal e ImporteTotal = suma del desglose ± 10 € (aviso, no rechazo) | Validaciones y errores v1.2.2, §15.7, §16 y §17 | Redondeo por tipo al céntimo, muy por debajo | Igual | T-TOT |
| V-21 | Sanciones: 50.000 € por ejercicio al usuario de un sistema no conforme; 150.000 € al productor | Ley 58/2003, art. 201 bis; FAQ de la AEAT | Audax es las dos cosas | — | — |
| V-22 | VeriFactu solo afecta a las facturas expedidas, no a las recibidas | RD 1007/2023, arts. 1 y 9; FAQ «ámbitos de aplicación» (el reglamento obliga a registrar solo las expedidas) | Los gastos no generan registros | Igual | — |

### 2.5 Gastos, digitalización y datos personales

| Id | Requisito | Fuente | Dónde |
|---|---|---|---|
| G-01 | Una foto de un ticket o factura en papel no sustituye al original: para destruir el papel hace falta digitalización certificada con software homologado por la AEAT | Orden EHA/962/2007; sede de la AEAT, procedimiento FZ01 | E5: se conserva la foto como copia de trabajo; el papel se guarda (pregunta G-7). Un PDF recibido por email sí es el original y se guarda tal cual, con su SHA-256 |
| G-02 | Retenciones de IRPF en facturas de profesionales y alquileres (modelos 111 y 115) | Normativa del IRPF; las lleva la gestoría | E5: campos de retención en el gasto y en el libro |
| G-03 | Google, como encargado del tratamiento al leer los tickets con Gemini: solo con el servicio de pago, que trata los datos con el anexo de tratamiento de datos (DPA) y no los usa para entrenar; en el EEE solo se pueden usar los servicios de pago | Términos de la API de Gemini (actualizados el 28/04/2026) | E5: mismo proyecto de pago que el dictado (D-243); texto RGPD |

### 2.6 Factura electrónica B2B (Ley 18/2022, RD 238/2026 y Orden HAC/1028/2026)

| Id | Requisito | Fuente | Dónde |
|---|---|---|---|
| B-01 | Los plazos cuentan desde la entrada en vigor de la orden (06/10/2026): a los 12 meses para volumen de operaciones de más de 8 M€ (octubre de 2027) y a los 24 meses para el resto (octubre de 2028) | RD 238/2026, disposición final cuarta (resumida en su preámbulo); Orden HAC/1028/2026, disposición final única | E9 |
| B-02 | Formato: modelo semántico EN 16931 en sintaxis UBL en la solución pública; también CII, Facturae o EDIFACT entre plataformas privadas | Orden HAC/1028/2026, art. 3.1; RD 238/2026 | E9: generar UBL desde `sales_documents` |
| B-03 | Copia fiel a la solución pública «simultáneamente a su emisión» si se emite por plataforma privada | Orden, arts. 4 y 5 | E9 |
| B-04 | El destinatario comunica rechazo, fecha de vencimiento y pago efectivo completo; sin rechazo se presume aceptada | Orden, art. 7; RD 238/2026, arts. 10 y 12 | E9: cobros y gastos ya guardan esas fechas |
| B-05 | Recepción: un empresario obligado a recibir que no publique otra plataforma recibe por la solución pública, sin hacer nada | RD 238/2026, art. 6 | E9: importar UBL recibidos a Gastos. Desde cuándo debe Audax recibir de un proveedor grande (octubre de 2027) está por confirmar con la gestoría (pregunta G-8) |

Qué se prepara ya: el modelo de datos guarda todo lo que pide EN 16931 (identificadores, unidades, desglose por tipo, fechas de vencimiento y de pago, referencias a la factura rectificada), así que E9 es una exportación y un canal, no un cambio de tablas.

### 2.7 Cuatro aclaraciones que cambian el diseño

1. «Anular» en Audax no es el registro de anulación de VeriFactu. Anular una factura emitida y entregada es una rectificativa por el total en negativo (D-244, RD 1619/2012, art. 15). El registro de anulación (V-03) es solo para la factura que no debió expedirse, por ejemplo una de prueba o un error detectado antes de entregarla (FAQ de desarrolladores §17, casos 2.d).
2. Subsanar no es rectificar. Si el error está en un dato del registro que no se ve en la factura (una clave fiscal), se genera un registro de alta de subsanación; si el error está en la factura, se rectifica (FAQ de desarrolladores §17). En la app, la subsanación solo existe en E7.
3. Cambiar de programa a mitad de ejercicio: ni la AEAT ni el RD 1619/2012 dicen que haya que abrir una serie nueva; solo exigen que la numeración sea correlativa dentro de cada serie, sin huecos ni repeticiones, y que un número no se use dos veces. La práctica más prudente es cambiar a principio de año, cuando la serie empieza de nuevo (opción A). Si se cambia a mitad de año (opción B), se continúa la misma serie desde el último número de Holded, con Holded parado desde la víspera (7.1).
4. Un sistema se identifica por NIF + código del sistema + número de instalación; reinstalar o cambiar la forma de facturar es otro sistema, con su propia cadena (FAQ de desarrolladores §4). Por eso la serie de pruebas va en una instalación aparte y, al activar E7, se puede abrir una instalación nueva con `PrimerRegistro = S` sin tocar lo anterior.

---

## 3. Funcionalidades y paridad con Holded

Leyenda de la columna «Entrega»: E1 a E9 (apartado 9). «Más adelante» no tiene fecha.

### 3.1 Ventas

| Holded | Qué hace en Holded | En Audax | Entrega |
|---|---|---|---|
| H-019, H-020 | Editor común y nueva factura con Vista previa, Guardar como borrador y Aprobar | Editor en tres zonas (cliente y fechas; líneas; panel de opciones) con «Vista previa», «Guardar borrador» y «Emitir». En Audax el verbo es Emitir (Holded dice Aprobar) | E1 |
| H-021 | Opciones: serie, idioma, plantilla de email | Serie (F por defecto, o la de pruebas), idioma del cliente (es, en), plantilla de email | E1 (serie, idioma); E2 (plantilla) |
| H-022 | Categorización: proyecto, etiquetas, nota interna | Proyecto y bolsa por línea, como hoy en los enlaces de Holded (D-388); nota interna; nº de pedido del cliente (H-123) | E1 |
| H-037, H-038 | Borrador sin número, fuera de informes | Igual. Un borrador cuenta como «previsto» (D-395) | E1 |
| H-039 | Aprobar asigna el número; aviso si la fecha es anterior a la última emitida | Emitir asigna el número; no deja emitir con una fecha anterior a la última emitida de la serie | E1 |
| H-040, H-041 | Bloqueo de la aprobada; editar solo campos no fiscales | Siempre bloqueada (*trigger*). Se pueden cambiar nota interna, proyecto y bolsa, etiquetas y nº de pedido; nada que salga en el PDF | E1 |
| H-042 | Eliminar borradores; anular | Eliminar solo borradores (sin papelera: no tienen número). Anular, como D-244 | E1 |
| H-043 a H-046 | Rectificativa independiente o desde la original; por diferencias o por sustitución | Desde la original (Anular o Rectificar) o eligiéndola (también una factura de Holded). Por diferencias en E1; por sustitución si la gestoría lo pide (G-2) | E1 |
| H-049 | Fecha de operación | Campo opcional | E1 |
| H-050 a H-052 | Series con formato, último número, serie de devolución y reinicio anual | Series F[YY]#### y CN[YY]#### con reinicio anual automático; el último número solo se fija en el corte, auditado | E1 |
| H-055 | Serie excluida de VeriFactu | Series `external` para lo importado de Holded (no generan registros) | E1 |
| H-030 | Proformas | No se usan hoy. Más adelante, si se piden; si se hacen, se conservan aunque no se conviertan (FAQ de desarrolladores, p. 15) | Más adelante |
| H-024 a H-028 | Presupuestos con aceptación e hitos | Fuera de este plan (F3 de PLAN-FASE-12; Audax no los usa en Holded) | Más adelante (L4) |
| H-057 a H-061 | Recurrentes: intervalo, inicio, fin, crear como borrador o aprobar, enviar solas, palabras dinámicas, omitir una | Igual, con borrador por defecto (D-395) y «Omitir este periodo» | E3 |
| — | Facturas programadas | Un borrador con fecha de emisión futura: se emite solo ese día a las 08:00 y, si se elige, se envía | E3 |
| H-033 | Convertir: a rectificativa o a recurrente | «Crear rectificativa», «Hacer recurrente» | E1, E3 |
| H-036 | Acciones del listado: editar, duplicar, anular, descargar; en bloque emitir y PDF | Menú ⋯ por fila y selección múltiple (R3): emitir borradores, descargar PDF en ZIP, enviar, exportar | E1 (fila), E2 (bloque) |
| H-034 | Árbol de documentos | Cadena en la ficha: factura → rectificativas → cobros (L5) | E2 |
| H-062 a H-066 | Impuestos | IVA 21, 10 y 4 %, 0 % a empresarios de la UE (no sujeta por localización, con mención de inversión del sujeto pasivo), 0 % fuera de la UE, exento con su artículo | E1 |
| H-067 | Suplidos | Línea fuera de la base | Más adelante |
| H-070 a H-074 | Tipos de línea, descuentos, unidades, modo «Tiempo», texto final | Líneas de concepto, título y texto; descuento por línea y general; unidades h, ud y mes; columnas Horas y €/h en el PDF cuando todas las líneas son horas | E1 |
| H-080 | Formas de pago con texto e IBAN | Igual | E1 |
| H-093 a H-096 | Plantillas PDF | Una plantilla de marca con Gotenberg (D-140), en español e inglés | E1 |
| H-120 | Multimoneda | Solo euros | Más adelante |
| H-121 | Multiidioma | Español e inglés (PDF y email) | E1 (PDF), E2 (email) |
| H-122 | Adjuntos | Adjuntos internos o visibles en el portal (`attachments`) | E2 |
| H-129, H-132 | Vincular al proyecto; facturar horas | «Crear borrador» desde Por facturar y desde el proyecto, con el bloqueo de horas | E3 |
| H-134 a H-137 | VeriFactu en Holded | E7 | E7 |
| H-139 | Factura-e | E9 | E9 |

### 3.2 Matriz estado → acciones

Contrato de diseño de L1: la cabecera de la ficha, el menú ⋯ de cada fila y el servidor (políticas) leen la misma tabla (`App\Domain\Billing\Issuing\DocumentActions`, con su caso compartido PHP/TS en `tests/fixtures/billing/document-actions.json`). «Gestionar» es el permiso nuevo `manage-billing`; «Ver» es `view-billing`.

| Acción | Borrador | Programada | Emitida (pendiente, parcial o vencida) | Emitida y cobrada | Anulada | Rectificativa | De Holded (antes del corte) | De Holded (después del corte) |
|---|---|---|---|---|---|---|---|---|
| Ver y vista previa | Ver | Ver | Ver | Ver | Ver | Ver | Ver | Ver |
| Editar todo | Gestionar | Gestionar (vuelve a borrador) | — | — | — | — | — | — |
| Editar lo no fiscal (nota, proyecto, etiquetas, nº de pedido) | Gestionar | Gestionar | Gestionar | Gestionar | Gestionar | Gestionar | Gestionar (enlaces, D-388) | Gestionar |
| Eliminar | Gestionar | Gestionar | — | — | — | — | — | — |
| Emitir | Gestionar | Gestionar (adelantar) | — | — | — | — | — | — |
| Programar | Gestionar | Gestionar (cambiar fecha) | — | — | — | — | — | — |
| Duplicar como borrador | Gestionar | Gestionar | Gestionar | Gestionar | Gestionar | — | Gestionar | Gestionar |
| Hacer recurrente | Gestionar | — | Gestionar | Gestionar | — | — | Gestionar | Gestionar |
| Descargar PDF | Vista previa con marca «Borrador» | Igual | Ver (el archivado) | Ver | Ver (con «Anulada») | Ver | Ver (el de Holded) | Ver |
| Enviar por email | — | Al emitirse, si se eligió | Gestionar | Gestionar | — | Gestionar | — | — |
| Enlace para el cliente y portal | — | — | Gestionar | Gestionar | — | Gestionar | — | Ver (portal) |
| Registrar cobro | — | — | Gestionar | — (salvo devolución, en negativo) | — | Gestionar (devolución) | — (lo hace Holded) | Gestionar |
| Recordatorio de cobro | — | — | Gestionar (vencida o por vencer) | — | — | — | — | Gestionar |
| Anular (rectificativa por el total) | — | — | Gestionar | Gestionar (avisa: habrá que devolver) | — | — | — | Gestionar |
| Rectificar (diferencias) | — | — | Gestionar | Gestionar | — | — | — | Gestionar |
| Anular el registro (expedida por error, V-03) | — | — | Admin, solo si no se ha enviado ni cobrado, con aviso | — | — | Admin, ídem | — | — |
| Ver el registro (huella, cadena) | — | — | Ver | Ver | Ver | Ver | — | — |
| Estado en la AEAT y subsanar (E7) | — | — | Admin | Admin | Admin | Admin | — | — |
| Abrir en Holded | — | — | — | — | — | — | Ver | Ver |

Reglas que acompañan a la matriz:
- Anular (D-244): emite la rectificativa CN por el total en negativo con motivo obligatorio, deja la original «Anulada», desbloquea las horas que cubría y ofrece «Duplicar como borrador».
- Rectificar: emite una CN por la diferencia; la original sigue emitida y las horas siguen bloqueadas.
- Una factura cobrada se puede anular o rectificar, pero el aviso dice que la devolución se registra como cobro negativo en la rectificativa.
- Las acciones que no están en la matriz no se ofrecen: un botón deshabilitado siempre dice por qué.

### 3.3 Cobros

- Registrar cobro (H-076): importe (por defecto, lo pendiente), fecha, forma (transferencia, domiciliación, tarjeta, efectivo) y referencia del banco. Cobros parciales sin límite. Un cobro no se borra: se anula con motivo, y queda en la línea de tiempo.
- Estado calculado, como hoy (D-386, D-407): pendiente, cobrada en parte (con %), cobrada, vencida (con días), anulada.
- «Por cobrar» pasa a pantalla propia (L3): tramos de antigüedad por cliente (en plazo, 1–30, 31–60, 61–90, más de 90) en una tabla con celdas que filtran, como Xero, y las vencidas de cada cliente con sus acciones (enviar recordatorio, registrar cobro, ver PDF).
- Recordatorios (H-092): reglas por escalones (por ejemplo, 3 días antes, 1 y 15 días después del vencimiento), con importe mínimo, plantilla e idioma; un envío por factura y regla; se paran si entra un cobro o si alguien marca «No recordar» en la factura o en el cliente. Cada envío sale en la línea de tiempo.
- Facturas de Holded después de la baja: sus cobros se registran en Audax contra la factura de Holded (tabla `payments` con `holded_invoice_id`), así lo pendiente de 2026 se sigue cobrando aquí.
- Conciliación bancaria y remesas SEPA: más adelante (L6, P6).

### 3.4 Envío por email

- Sale por la cola `mail` con el relé SMTP de Google Workspace, que funciona desde el 03/10/2026 (SERVIDOR-CAMBIOS). Falta elegir el remitente de las facturas (pregunta P-4).
- Plantillas por tipo (factura, rectificativa, recordatorio, recurrente) e idioma, con asunto, cuerpo y variables (`[cliente]`, `[numero]`, `[total]`, `[vencimiento]`, `[enlace]`, `[mes]`), predeterminada por tipo, «Enviar prueba» a uno mismo (H-090, H-091).
- Destinatarios: los emails de facturación del cliente (`client_billing_profiles.billing_emails`) más los que se añadan; copia oculta opcional a la gestoría.
- Adjunto: el PDF archivado (el mismo fichero, con su SHA-256 en el registro del envío) y los adjuntos marcados como visibles.
- Enlace firmado de 60 días a la factura (sin usuario), como el de 48 h de Holded (H-098).
- Seguimiento de apertura: se anota cuando el cliente abre el enlace o descarga el PDF desde el email (primera vez y número de veces), sin píxel espía (ni fiable ni respetuoso). Se ve como «Vista por el cliente el 14/11 a las 10:32».
- Cada envío queda en `sales_document_emails` (destinatarios, plantilla, asunto, huella del PDF, id del mensaje, estado) y en la línea de tiempo. Los rebotes llegan al buzón del remitente: el relé no da aviso automático.

### 3.5 Portal del cliente

- Nueva pestaña «Facturas» en `/portal` con las facturas emitidas y las rectificativas del cliente (nunca borradores ni registros anulados por error), su estado de cobro y la descarga del PDF; el IBAN y la forma de pago (H-097, H-100).
- Se enciende por cliente con los ajustes del portal (D-097), apagada por defecto.
- El aislamiento del cliente tiene su test, como el resto del portal (bc2d295, ab62608).
- Comentarios del cliente en la factura (H-102): más adelante.

### 3.6 Gastos

Flujo propuesto (pregunta P-6 para confirmarlo):

| Quién | Qué puede | Permiso |
|---|---|---|
| Cualquier persona de la plantilla interna activa | Subir sus tickets y gastos, ver los suyos y su estado, corregir los devueltos | `submit-expenses` (no los colaboradores externos ni los clientes) |
| Quien lleva las finanzas (admins y `view-billing`, sin los excluidos de D-245) | Ver todos, subir facturas de proveedores, aprobar o devolver, proveedores, categorías, pagos, reembolsos y libro de recibidas | `manage-expenses` |
| Gestoría | Ver y exportar | Rol Gestoría (E4) |

Ciclo de un gasto: `processing` (la IA lo lee) → `draft` (la persona revisa) → `submitted` (enviado) → `approved` (con número de recepción) o `returned` (con motivo, vuelve a la persona). Un gasto subido por quien tiene `manage-expenses` se aprueba al guardarlo.

Subir:
- Móvil: dos botones, «Hacer foto» (`<input type="file" accept="image/*" capture="environment">`, abre la cámara) y «Elegir archivo» (galería, PDF o captura). La imagen se reduce en el navegador a 2.400 px de lado y JPEG del 85 % antes de subirla.
- Ordenador: arrastrar uno o varios PDF o imágenes a la zona de Gastos; cada fichero crea un gasto en `processing`.
- Límite de 14 MB por fichero (el mismo que el dictado, D-243). Formatos: JPEG, PNG, WebP, HEIC (convertido en el servidor) y PDF.

Lectura con IA (Gemini, ya en la app):
- Se generaliza `LlmAudio` a `LlmInlineFile` (tipo MIME y bytes) en `LlmRequest`, para audio, imagen y PDF; `GeminiClient::payload` ya manda `inlineData`.
- Job `ExtractExpense` en la cola `ai-high`, con el esquema de respuesta JSON: proveedor (nombre y NIF), número, fecha de expedición y de operación, tipo de documento (factura completa, simplificada, ticket), NIF del destinatario (para saber si sale el de Audax), desglose por tipo (base, tipo, cuota), retención, total, moneda, concepto y categoría propuesta, cada campo con su confianza.
- Comprobaciones en el servidor, sin IA: base + cuota − retención = total (± 0,02 €), cuota ≈ base × tipo, dígito de control del NIF o CIF, fecha no futura, proveedor conocido por NIF (si existe, se usa su categoría y su retención por defecto).
- La persona ve la imagen y el formulario relleno, con los campos dudosos en ámbar; nada se guarda sin su revisión. Se guarda lo que propuso la IA (`expense_extractions`) para ver qué se cambió.
- `ai_usage` lo anota como «Lectura de gasto» (nuevo `AiFeature::ExpenseExtraction`) y hay un tope diario por persona (`AiDailyLimits`, bucket `expenses`). Sin clave de Gemini o con la IA caída, el formulario sale vacío y se rellena a mano.
- OCR propio o lectura de un buzón de email (gastos@): más adelante.

Privacidad: el texto RGPD (D-075, pendiente del asesor) añade que las imágenes de tickets y facturas se envían a Google como encargado del tratamiento, con el servicio de pago de la API de Gemini (no se usan para entrenar y se tratan con su DPA), y que no se guardan en Google (`inlineData`).

Conservación del adjunto:
- Disco privado `expenses/{año}/{uuid}.{ext}`, con su SHA-256, nunca en una purga, en la copia nocturna (D-029, D-076) y conservado seis años como mínimo (L-16, L-18).
- Miniatura en Horizon con el patrón de `GenerateAttachmentThumbnail` (un intento, dentro del límite de memoria); la primera página de un PDF, con Imagick si el servidor tiene Ghostscript; si no, el icono.
- El papel se guarda (G-01) salvo que la gestoría use digitalización certificada.

Duplicados:
- Bloqueo: el mismo fichero (mismo SHA-256) no entra dos veces.
- Aviso al revisar y al aprobar: mismo NIF de proveedor (o mismo nombre normalizado si no hay NIF) y mismo número, o mismo proveedor, misma fecha y mismo total. Se puede seguir con «No es un duplicado», que queda anotado.

Tipos de documento:
- Factura completa: IVA deducible si es a nombre de Audax; entra en el libro de recibidas.
- Factura simplificada: deducible solo si lleva el NIF de Audax y la cuota desglosada (RD 1619/2012, art. 7.2); si no, IVA no deducible.
- Ticket sin NIF del destinatario o recibo: gasto sin IVA deducible; si entra o no en el libro de recibidas lo decide la gestoría (G-3).
- Inversión del sujeto pasivo (servicios de empresas de fuera de España: Google Ads, Meta, Adobe, Figma…): base sin IVA y el IVA autorrepercutido calculado (cuota repercutida = soportada), marcado para el 303 y el 349 (R-06).
- Con retención (profesionales, alquiler): tipo y cuota de retención (G-02).

Proveedores, categorías y pagos:
- Proveedores (`suppliers`): nombre, razón social, NIF, país, email, IBAN, categoría y retención por defecto. Se crean al aprobar el primer gasto de un NIF nuevo (con confirmación).
- Categorías con su cuenta del Plan General Contable (629 Otros servicios, 627 Publicidad, 623 Servicios profesionales, 607 Trabajos de otras empresas, 621 Arrendamientos, 628 Suministros, 626 Bancos, 625 Seguros…), a revisar con la gestoría (G-3).
- Imputación opcional a un proyecto (coste externo) y marca «repercutible al cliente», que más adelante podrá añadirse como línea de una factura (Inversión, Herramienta, D-396).
- Pagos: pendiente o pagado (fecha y forma: transferencia, tarjeta de empresa, domiciliación, efectivo o pagado por una persona). Lo que pagó una persona de su bolsillo queda «Por reembolsar» hasta que se marca «Reembolsado» (en lote, con un CSV para la transferencia o la nómina).

### 3.7 Libros y exportación para la gestoría

- Libro de facturas emitidas y libro de facturas recibidas con los diseños normalizados de la AEAT para personas jurídicas no incluidas en el SII (LSIJ.xlsx, 01/01/2026), en XLSX y CSV, por periodo (mes, trimestre, año). El de emitidas incluye las de Holded hasta el corte y las de Audax después, sin repetir.
- ZIP con los PDF del periodo (emitidas y adjuntos de gastos) y un índice.
- Resumen del IVA del periodo (repercutido por tipo, soportado deducible, inversión del sujeto pasivo, intracomunitarias para el 349, retenciones para el 111 y el 115) como ayuda, no como modelo.
- Formato del programa de la gestoría (A3 `SUENLACE.DAT`, Sage, Contasol…): solo cuando conteste la pregunta G-1. Muchos de esos programas importan los libros en el formato de la AEAT; si es su caso, no hace falta nada más.
- Envío programado mensual o trimestral a la gestoría con el patrón de los envíos de informes (D-139, D-140): enlace firmado de 7 días, nunca adjunto.
- Rol Gestoría: usuario externo (como el colaborador de D-134), con 2FA obligatorio, que solo ve Facturas, Gastos y Libros en lectura y descarga exportaciones; todo en la auditoría (H-115).
- Cierre de periodo: «Cerrar el 3T» impide aprobar gastos o registrar cobros con fecha dentro del periodo cerrado sin reabrirlo (admin, con motivo). Las emitidas ya no pueden llevar una fecha anterior a la última, así que el cierre solo protege los gastos y los cobros.

### 3.8 Facturar desde el trabajo

«Por facturar» (I10) gana «Crear borrador» por fila (L2), y la ficha del proyecto y la de la bolsa ganan su botón:
- Horas de un proyecto por horas: el flujo de Holded H-132 (periodo, todas las aprobadas sin facturar, y cómo agrupar: por tarea, por persona, por tipo de tarea, por proyecto o una por entrada). Importes de `EntryValuation` al céntimo; las pendientes de aprobar se ven aparte, en ámbar, y no entran.
- Bolsa vendida: una línea «bolsadehoras» con unidades = horas y precio = €/h, con descuento si el precio es menor (como Audax factura hoy, PLAN-FASE-12 §3.1 y §4.1).
- Exceso de una bolsa: las horas de exceso aprobadas y sin facturar, a su tarifa congelada (`HourBankLedger` y `EntryValuation`).
- Fee mensual: una línea «Fee de [mes]» con `monthly_fee_amount`; lo normal es que lo haga la recurrente del fee.
- Varias filas de un cliente en una sola factura, con un título por proyecto (H-070).
- Bloqueo al emitir: las entradas de las líneas de horas pasan a bloqueadas con la factura como referencia (`TimeLockService`, con un método nuevo que bloquea entradas concretas, no un rango, y guarda `sales_document_id` en `time_entry_locks`). Una entrada solo puede estar en una factura emitida viva (índice único parcial). Anular desbloquea con el `unlock` de siempre; rectificar no.
- Aviso del plazo: lo del mes anterior que siga sin facturar el día 10 sale en «Requiere atención» del Resumen (L-11).

### 3.9 Lo que queda fuera

Presupuestos e hitos (F3), proformas, suplidos, multimoneda, conciliación bancaria, remesas SEPA, OCR propio, buzón de gastos, contabilidad y modelos (los presenta la gestoría), firma XAdES y registro de eventos (solo para un sistema no VERI\*FACTU, que no se construye).

---

## 4. Modelo de datos

Convenciones de la app: importes en `decimal` (nunca float), minutos enteros, instantes en UTC, nombres en inglés, `restrictOnDelete` en todo lo fiscal. Las tablas nuevas van en migraciones solo aditivas, con el módulo apagado.

### 4.1 Catálogo y ajustes (E1)

- `billing_services`: `code` (BDH, DES, F_UX…), `name`, `description`, `unit` (`hour`, `unit`, `month`), `unit_price` decimal(14,4), `tax_rate_id`, `category` (`App\Enums\BillingService`), `holded_service_id`, `archived_at`. Se siembra con el catálogo de Holded (PLAN-FASE-12 §3.1).
- `tax_rates`: `key`, `name`, `rate` decimal(5,2), `operation_type` (`S1` sujeta y no exenta, `N2` no sujeta por localización, `E1`…`E6` exenta con su causa), `legal_mention` (texto del PDF), `is_default`, `archived_at`. Se usa `operation_type` tal cual en el desglose de VeriFactu.
- `payment_methods`: `name`, `document_text`, `iban`, `due_days`, `is_default`.
- `numbering_series`: `code` (F, CN, PRU), `document_type` (`invoice`, `credit_note`), `format` (`F[YY]####`), `refund_series_id`, `kind` (`regular`, `test`, `external`), `sif_installation_id`, `is_default`, `archived_at`.
- `numbering_counters`: `series_id`, `year`, `last_number`; único (`series_id`, `year`). Solo sube (*trigger*: `NEW.last_number > OLD.last_number`).
- `sif_installations`: `sif_code` (dos caracteres, por ejemplo «AP»), `sif_name` («Audax Proyectos»), `installation_number` (único, nunca reutilizado, FAQ §4), `environment` (`production`, `test`), `mode` (`chain_only`, `verifactu`), `started_at`, `ended_at`. Una activa por entorno.
- Ajustes: `billing_issuer` (D-383) gana forma jurídica y datos del Registro Mercantil (L-17); `billing_documents` (pie, condiciones, texto de pago); `billing_permissions` (quién emite, P-5).

### 4.2 Ventas (E1 a E3)

`sales_documents`:
- Identidad: `id`, `uuid` (enlaces firmados), `type` (`invoice`, `credit_note`), `status` (`draft`, `scheduled`, `issued`, `cancelled`), `is_test`.
- Numeración: `series_id`, `year`, `number` (nulo en borrador), `full_number` (único cuando no es nulo).
- Fechas: `issue_date`, `operation_date`, `due_date`, `scheduled_for`.
- Partes: `client_id`, `client_snapshot` (jsonb: razón social, NIF, NIF-IVA, domicilio, régimen, idioma), `issuer_snapshot` (jsonb: emisor y Registro Mercantil), `recipients` (jsonb).
- Importes: `subtotal`, `discount_total`, `tax_total`, `total` (decimal(12,2), con signo), `paid_total` (caché de los cobros).
- Textos: `body` (texto final), `internal_note`, `customer_reference` (nº de pedido), `payment_method_id`, `payment_text`.
- Relaciones: `rectified_document_id` o `rectified_holded_invoice_id`, `rectification_kind` (`cancellation`, `differences`, `substitution`), `rectification_reason`, `rectification_code` (R1 a R4, con la gestoría, G-2), `cancelled_by_id`, `source_document_id` (duplicado de), `recurring_invoice_id`.
- Emisión: `issued_at`, `issued_by`, `invoice_record_id`, `pdf_path`, `pdf_sha256`, `pdf_generated_at`, `sent_at`, `first_viewed_at`, `view_count`, `created_by`, `timestamps`.
- Restricciones: `CHECK` de coherencia (emitida ⇔ número, serie, año, fecha y registro; rectificativa ⇔ factura rectificada y motivo), único (`series_id`, `year`, `number`), `restrictOnDelete` hacia clientes y series.

`sales_document_lines`: `position`, `kind` (`item`, `title`, `text`), `service_id`, `description`, `quantity` decimal(12,4), `unit`, `unit_price` decimal(14,4), `discount_pct` decimal(5,2), `tax_rate_id`, `tax_rate` y `operation_type` (copiados al emitir), `line_base` decimal(12,2), `project_id`, `hour_bank_id`, `origin` (`manual`, `hours`, `hour_bank`, `overage`, `fee`), `minutes`.

`sales_document_taxes` (el desglose, congelado al emitir): `operation_type`, `rate`, `base`, `tax`. Es lo que lee el PDF, el libro y el XML de VeriFactu.

Redondeo: base de la línea = cantidad × precio × (1 − descuento), redondeada al céntimo; base por tipo = suma de líneas; cuota por tipo = base × tipo / 100 redondeada al céntimo (mitad hacia arriba), con `Money` y `Cents` (D-083). Casos compartidos PHP/TS en `tests/fixtures/billing/totals.json`.

Trabajo facturado:
- `sales_document_time_entry`: `line_id`, `time_entry_id`, `released_at`; índice único parcial (`time_entry_id`) `WHERE released_at IS NULL`.
- `time_entry_locks` gana `sales_document_id`.
- `sales_document_links` (proyecto y bolsa por factura, como `holded_invoice_links`, D-388), para que «Vendido frente a real» las cuente igual.

Cobros y envíos:
- `payments`: `sales_document_id` o `holded_invoice_id` (`CHECK`: exactamente uno), `paid_on`, `amount` (con signo), `method`, `bank_reference`, `note`, `created_by`, `voided_at`, `voided_by`, `void_reason`. No se borran.
- `sales_document_emails`: `document_id`, `kind` (`send`, `reminder`), `template_id`, `to`, `cc`, `bcc`, `subject`, `pdf_sha256`, `message_id`, `status`, `error`, `sent_at`, `first_viewed_at`, `view_count`.
- `email_templates`: `kind`, `language`, `name`, `subject`, `body`, `include_pdf`, `include_link`, `is_default`.
- `payment_reminder_rules` y `payment_reminder_deliveries` (único por factura y regla).

Recurrentes:
- `recurring_invoices`: `client_id`, `project_id`, `name`, `periodicity` (`monthly`, `bimonthly`, `quarterly`, `semiannual`, `annual`), `start_date`, `end_date`, `next_run_on`, `day_of_month`, `mode` (`draft`, `issue`), `auto_send`, `series_id`, `due_days`, `payment_method_id`, `skipped_periods` (jsonb), `paused_at`, `holded_recurring_id`, `last_document_id`.
- `recurring_invoice_lines`: como las líneas, con las palabras dinámicas en la descripción.

### 4.3 El registro de facturación (E1, inalterable y encadenado)

`invoice_records`, uno por emisión, anulación por error o (en E7) subsanación:
- `id`, `sif_installation_id`, `seq` (bigint, único por instalación, sin huecos), `kind` (`alta`, `anulacion`), `sales_document_id`.
- Los campos de la huella, tal como irán en el XML: `issuer_tax_id`, `invoice_number`, `issue_date_text` (`DD-MM-AAAA`), `invoice_type` (F1, R1…R4), `tax_total_text` y `total_text` (dos decimales, punto), `previous_hash` (vacío en el primero), `generated_at_text` (`AAAA-MM-DDThh:mm:ss+01:00`, hora de Madrid con su desfase).
- `is_first` (`PrimerRegistro = S`), `previous_record_id`, `hash` char(64).
- `payload` (jsonb): todo lo del art. 10 del RD 1007/2023 (destinatario, descripción, desglose, rectificación, sistema, versión de la app), para generar el XML en E7 sin leer la factura.
- `subsanacion`, `rechazo_previo` (E7), `created_at`.

Por qué texto y no fecha o decimal en los campos de la huella: la huella se calcula sobre el texto exacto del XML; guardándolo así, la base de datos y la app calculan lo mismo y nadie depende de cómo formatea cada lenguaje.

Garantías en PostgreSQL (y su equivalente en SQLite para los tests, como `create_people_r2_tables`):
- `invoice_records` es de solo alta: un *trigger* `BEFORE UPDATE OR DELETE` y otro `BEFORE TRUNCATE` lanzan una excepción. No hay vía de borrado ni de supresión (al contrario que el registro de jornada, no caduca).
- Un *trigger* `BEFORE INSERT` comprueba la cadena: `seq` = último + 1 de su instalación; `previous_hash` = `hash` del último (o vacío y `is_first` si es el primero); y recalcula la huella con `upper(encode(sha256(convert_to(cadena, 'UTF8')), 'hex'))` sobre la misma concatenación de la especificación. Si no coincide, rechaza el alta. Así ni un error de la app ni un `INSERT` a mano pueden romper la cadena sin que la base de datos lo impida.
- `sales_documents`: *trigger* que, si `OLD.status` es `issued` o `cancelled`, rechaza cualquier cambio en los campos fiscales (serie, número, fechas, partes, copias, importes, textos del PDF, `pdf_sha256` una vez puesto). Solo admite el paso `issued` → `cancelled` con `cancelled_by_id`, los campos no fiscales y las cachés (`paid_total`, `sent_at`, vistas). Nunca `DELETE` de una emitida.
- `sales_document_lines` y `sales_document_taxes`: sin `INSERT`, `UPDATE` ni `DELETE` si su factura no está en borrador.
- `numbering_counters`: solo sube.
- Concurrencia: emitir bloquea la fila de la instalación (`SELECT … FOR UPDATE`), así numeración y cadena van en serie; el *trigger* es la segunda línea de defensa.
- Verificación: `app:billing-verify-chain` recorre la cadena cada noche (después de la copia) y avisa a los admins si algo no cuadra; también la puede lanzar la gestoría desde Libros («Comprobar la integridad»).

### 4.4 VeriFactu (E7)

- `verifactu_submissions` (solo alta): `sif_installation_id`, `environment`, `requested_at`, `responded_at`, `http_status`, `soap_fault`, `estado_envio` (`Correcto`, `ParcialmenteCorrecto`, `Incorrecto`), `csv`, `tiempo_espera_envio`, `request_sha256`, `response_path` (el XML de respuesta en disco privado).
- `verifactu_submission_records` (solo alta): `submission_id`, `invoice_record_id`, `estado_registro` (`Correcto`, `AceptadoConErrores`, `Incorrecto`), `codigo_error`, `descripcion_error`, `estado_registro_duplicado`.
- `verifactu_state` (una fila por instalación, mutable): `next_send_at`, `pending_count`, `last_success_at`, `incident_since`.
- `verifactu_declarations`: `version` de la app, `text`, `signed_by` (nombre y cargo), `signed_on`, `place`, `pdf_path`. Una por versión.
- `sales_documents` gana `verifactu_status` como caché del último estado de su registro (no es fiscal, el *trigger* lo deja cambiar).

### 4.5 Gastos (E5)

- `suppliers`: `name`, `legal_name`, `tax_id`, `tax_id_normalized` (índice), `country_code`, `address`, `email`, `iban`, `default_category_id`, `default_withholding_rate`, `notes`, `archived_at`.
- `expense_categories`: `name`, `account_code`, `vat_deductible_default`, `archived_at`.
- `expenses`:
  - `uuid`, `kind` (`invoice`, `simplified`, `receipt`), `status` (`processing`, `draft`, `submitted`, `approved`, `returned`), `submitted_by`, `approved_by`, `approved_at`, `return_reason`;
  - `supplier_id`, `supplier_snapshot` (nombre y NIF tal como venían), `supplier_number`, `issue_date`, `operation_date`, `received_on`;
  - `reception_year` y `reception_number` (al aprobar; único por año: el número de recepción del art. 64.1);
  - `category_id`, `project_id`, `rebillable`, `description`;
  - `subtotal`, `tax_total`, `withholding_rate`, `withholding_amount`, `total`, `vat_deductible`, `reverse_charge`;
  - `payment_status` (`pending`, `paid`), `due_date`, `paid_on`, `paid_with` (`transfer`, `company_card`, `direct_debit`, `cash`, `employee`), `reimbursed_at`;
  - fichero: `file_path`, `original_name`, `mime`, `size`, `sha256` (único), `thumbnail_path`;
  - `duplicate_of_id`, `duplicate_dismissed_by`.
- `expense_taxes`: `rate`, `base`, `tax`, `kind` (`deductible`, `non_deductible`, `reverse_charge`).
- `expense_extractions` (solo alta): `expense_id`, `model`, `status`, `result` (jsonb con confianza por campo), `latency_ms`, `error`.
- `billing_period_closes`: `period_start`, `period_end`, `closed_by`, `closed_at`, `reopened_by`, `reopened_at`, `reopen_reason`.
- Un gasto aprobado no se borra; se corrige con un nuevo estado anotado en la auditoría (no hay registro encadenado: VeriFactu no aplica, V-22).

### 4.6 Holded y los informes

- Las tablas de Holded (D-385) siguen siendo el espejo de solo lectura hasta la baja; después, la última lectura queda congelada.
- Una vista SQL `billing_documents` une `holded_invoices` y `sales_documents` (emitidas, sin pruebas) con las mismas columnas (cliente, fechas, base, IVA, total, cobrado, pendiente, origen). La leen `InvoiceList`, `InvoicingReport`, `SoldVsActual` y los libros, así cada informe cuenta una sola vez cada factura, venga de donde venga, y no se reescriben las consultas de F1 dos veces.
- Lo cobrado de una factura de Holded = lo cobrado en Holded hasta la congelación + los `payments` de Audax contra ella.

---

## 5. Arquitectura del módulo VeriFactu

### 5.1 Componentes (`app/Domain/Billing/Issuing` y `app/Domain/Billing/Verifactu`)

| Pieza | Qué hace | Entrega |
|---|---|---|
| `InvoiceIssuer` | Valida (L-01 a L-10), bloquea la instalación, asigna número, congela copias y desglose, crea el registro, bloquea horas y encola el PDF. Todo o nada | E1 |
| `RecordHasher` | La huella de la especificación (alta, anulación) | E1 |
| `RecordChain` | Siguiente `seq`, registro anterior, primer registro, verificación | E1 |
| `InvoicePdf` | HTML con la hoja de documentos de Audax → Gotenberg → disco privado con SHA-256; reintentos; hueco del QR | E1 |
| `VerifactuXmlBuilder` | `RegFactuSistemaFacturacion` (cabecera + hasta 1.000 `RegistroFactura`) desde `payload` | E7 |
| `VerifactuSchema` | Valida contra las XSD v1.0 guardadas en el repositorio | E7 |
| `VerifactuClient` | SOAP 1.1 por HTTPS con certificado de cliente | E7 |
| `VerifactuDispatcher` | Cola, lotes, control de flujo, incidencias | E7 |
| `VerifactuResponseParser` | Estados, CSV, errores, `TiempoEsperaEnvio` | E7 |
| `VerifactuQr` | URL de cotejo y SVG | E7 |
| `VerifactuQuery` | Consulta de registros (`ConsultaFactuSistemaFacturacion`) | E7 |

### 5.2 La huella y la cadena, desde E1

- Cadena de alta: `IDEmisorFactura=…&NumSerieFactura=…&FechaExpedicionFactura=DD-MM-AAAA&TipoFactura=…&CuotaTotal=…&ImporteTotal=…&Huella=…&FechaHoraHusoGenRegistro=…`.
- Cadena de anulación: `IDEmisorFacturaAnulada=…&NumSerieFacturaAnulada=…&FechaExpedicionFacturaAnulada=…&Huella=…&FechaHoraHusoGenRegistro=…`.
- Reglas: valores sin espacios al principio ni al final; un campo vacío se escribe `Huella=`; UTF-8; SHA-256; hexadecimal en mayúsculas, 64 caracteres (especificación v0.1.2).
- Importes siempre con dos decimales y punto («123.10», «-1210.00»): la AEAT da por buenos uno o dos decimales, pero la cadena tiene que ser idéntica a lo que lleve el XML, así que se fija un formato y se usa en los dos sitios.
- Una sola cadena por instalación con todas las series de esa instalación, en el orden en que se generan los registros (servicios web v1.0.3, anexo II).
- Instalaciones:
  - producción (`chain_only` hasta E7): las series F y CN;
  - pruebas: la serie PRU, con su propia cadena, para probar y formar sin ensuciar la real (V-17).
- Comprobado el 09/10/2026: los tres ejemplos oficiales de la especificación (primer alta, segundo alta y anulación) dan, con `shasum -a 256`, exactamente las huellas publicadas (3C464DAF…F12F60, F7B94CFD…802A2B97 y 177547C0…88F90C68). Son el primer test (T-HASH).

Al activar E7 hay dos caminos, que no cambian nada de lo guardado:
1. Recomendado: abrir una instalación nueva de producción en modo `verifactu` el 1 de enero del año en que se active, con `PrimerRegistro = S`. La cadena anterior queda cerrada y verificable.
2. Si la AEAT confirma que lo admite: seguir la misma cadena y enviar desde el primer registro del día de activación (su anterior no estará en la AEAT). Se pregunta por escrito a `verifactu@correo.aeat.es` en E7.

### 5.3 XML y validación (E7)

- Esquemas de la AEAT, versión 1.0 (`IDVersion` = «1.0»): `SistemaFacturacion.wsdl`, `SuministroLR.xsd`, `SuministroInformacion.xsd` (servido con fecha de 11/01/2026), `RespuestaSuministro.xsd`, `ConsultaLR.xsd` y `RespuestaConsultaLR.xsd`, copiados a `resources/verifactu/1.0/` con una copia local de `xmldsig-core-schema.xsd` (la XSD lo importa por URL y la validación no debe salir a internet).
- `DOMDocument::schemaValidate` antes de cada envío y en los tests; un registro que no valida no se envía y avisa.
- Datos fijos del sistema en cada registro: `SistemaInformatico` con NIF de Audax como productora, `NombreSistemaInformatico` «Audax Proyectos», `IdSistemaInformatico` «AP», `Version` (la etiqueta de la versión desplegada), `NumeroInstalacion`, `TipoUsoPosibleSoloVerifactu` = S, `TipoUsoPosibleMultiOT` = N, `IndicadorMultiplesOT` = N.
- Correspondencias de Audax:
  - factura → `TipoFactura` F1; rectificativa → R1 o R4 según el motivo (G-2), `TipoRectificativa` I (diferencias, también la anulación por el total) o S (sustitución, con `ImporteRectificacion`);
  - desglose por tipo: `ClaveRegimen` 01, `CalificacionOperacion` S1 con `TipoImpositivo` y `CuotaRepercutida`; servicios a empresarios de fuera de España, N2; exentas, `OperacionExenta` E1…E6;
  - `DescripcionOperacion`: el concepto de la primera línea o un resumen (máximo 500 caracteres);
  - destinatario: NIF español o `IDOtro` (NIF-IVA de la UE, código 02; pasaporte o identificador del país, 04 o 06) para clientes extranjeros.

### 5.4 Cola de envío, control de flujo e incidencias (E7)

- Al confirmarse la transacción de emisión (`afterCommit`), se encola `DispatchVerifactu` en la cola nueva `verifactu`: un solo proceso de Horizon y `WithoutOverlapping`, porque el orden importa.
- El job envía si ya pasó `next_send_at`; si no, se reprograma para ese momento. Junta todos los registros pendientes en orden de `seq`, hasta 1.000 por envío (V-09).
- Cada respuesta actualiza `next_send_at` = ahora + `TiempoEsperaEnvio` (60 s al empezar).
- Errores de transporte, 5xx, tiempo agotado o `Fault` con `soapenv:Server`: incidencia. Se reintenta con espera creciente y, como máximo, cada hora (V-10), con `Incidencia` = S en la cabecera de los envíos de lo generado durante la incidencia; `incident_since` y `pending_count` se ven en la cabecera de Facturación (R6) y en el Resumen.
- `Fault` con `soapenv:Client` (XML mal formado o cabecera incorrecta): no se reintenta; aviso a los admins con el `faultstring`.
- Duplicado (el registro ya está en la AEAT, por ejemplo tras una respuesta perdida): se consulta y, si coincide la huella, se da por correcto.

### 5.5 Estados y subsanación (E7)

| Estado del registro | Qué ve la persona | Qué se hace |
|---|---|---|
| Pendiente | «Pendiente de enviar a la AEAT» con el número de pendientes | Nada: la cola lo enviará |
| Correcto | Nada especial (el estado bueno no hace ruido); en la ficha, el CSV | — |
| Aceptado con errores | Aviso ámbar con el código y la descripción | Si el error es de la factura: rectificar. Si es de un dato del registro: «Subsanar» (admin) genera un alta con `Subsanacion` = S |
| Incorrecto (rechazado) | Aviso rojo; la factura existe y es válida, pero el registro no está en la AEAT | Corregir el dato del registro y «Subsanar»: alta con `Subsanacion` = S y `RechazoPrevio` = X (FAQ de desarrolladores §17) |

Cada subsanación es un registro nuevo en la cadena (nunca se toca el anterior) y queda en la línea de tiempo de la factura.

### 5.6 QR y PDF (E7)

- Biblioteca de QR con licencia MIT (por ejemplo, `endroid/qr-code`), con la versión estable y la licencia comprobadas antes de instalarla (CLAUDE.md). Corrección M, SVG incrustado en el HTML del PDF.
- Posición: primera página, arriba y centrado (vertical A4), 35 × 35 mm (CSS en milímetros: Chromium los respeta al imprimir), 6 mm de margen blanco, «QR tributario:» encima y «VERI\*FACTU» debajo, en DM Sans del mismo tamaño o mayor que el texto de la factura (V-12).
- Desde E1 la plantilla deja ese hueco libre, para no rehacerla.
- URL: `https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=<NIF>&numserie=<rawurlencode>&fecha=DD-MM-AAAA&importe=<dos decimales>` (en pruebas, `prewww2.aeat.es`).
- Mientras no haya E7, el PDF no lleva QR ni leyenda: el QR solo lo debe llevar un sistema que cumpla el RD 1007/2023 con su declaración responsable.

### 5.7 Cliente SOAP con certificado (E7)

- Puntos de acceso del WSDL v1.0 (servicio `sfVerifactu`):
  - producción: `https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP`, y `www10` con certificado de sello;
  - pruebas: `https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP`, y `prewww10` con sello.
- Sobre SOAP 1.1, estilo *document/literal*, UTF-8, construido con `DOMDocument` (determinista y fácil de probar con `Http::fake`); se envía con el cliente HTTP de Laravel y las opciones `cert` y `ssl_key` de Guzzle. El servidor tiene `ext-soap`, pero no hace falta.
- Comprobado en el servidor el 09/10/2026: PHP 8.4 de Plesk con `openssl` (OpenSSL 1.1.1n), `curl` 7.64, `dom`, `soap` e `imagick`.
- Tiempo de espera de 30 s (10 de conexión). Configuración: `VERIFACTU_ENV` (`test`, `production`), `VERIFACTU_CERT_KIND` (`entity`, `seal`), rutas y contraseña en `shared/.env`, nunca en Git.

### 5.8 El certificado, sin que nadie lo vea

Lo instala el propietario con `~/.config/audax-certificado-verifactu.sh`, hecho a imagen de `~/.config/audax-clave-holded.sh`:
1. Pide la ruta del `.p12` o `.pfx` en su Mac y la contraseña sin eco (`stty -echo`).
2. Comprueba en local, con `openssl pkcs12`, que el fichero abre, y enseña solo el titular, el NIF y la fecha de caducidad.
3. Manda el fichero y la contraseña por la conexión SSH (stdin) a `audax-projects`, nunca en la línea de órdenes.
4. En el servidor: lo guarda en `shared/verifactu/certificado.p12` (`chmod 600`, usuario `audaxprojects`), hace copia del `.env` y escribe `VERIFACTU_CERT_PATH` y `VERIFACTU_CERT_PASSWORD`.
5. Prueba una consulta en el entorno de pruebas de la AEAT con ese certificado y solo deja el cambio si responde bien; si no, restaura la copia.
6. Borra las variables de la sesión. El certificado no pasa por Git, ni por el chat, ni por el Mac de desarrollo.

La app enseña el titular y la caducidad en Ajustes y avisa a los admins 30 días antes. Lo pueden leer `root` y el usuario de la app, como la clave de Holded; nadie más del equipo.

### 5.9 Cómo probar en el entorno de pruebas de la AEAT (E7)

El entorno de pruebas (`prewww1`, `prewww2`) exige un certificado cualificado real y el NIF real de Audax; lo que se envía allí no tiene efectos fiscales. Batería:

| Caso | Qué se manda | Qué se espera |
|---|---|---|
| A-01 | Primer alta F1 con `PrimerRegistro` = S | Correcto, con CSV |
| A-02 | Segundo alta encadenada | Correcto; la huella coincide con la que calcula la AEAT |
| A-03 | Rectificativa R1 por diferencias (I) | Correcto |
| A-04 | Rectificativa por el total (anulación de D-244) | Correcto |
| A-05 | Rectificativa por sustitución (S), si se usa | Correcto |
| A-06 | Registro de anulación de una factura de prueba | Correcto |
| A-07 | Cliente de la UE (N2, `IDOtro`) | Correcto |
| A-08 | Huella manipulada a propósito | Aceptado con errores |
| A-09 | NIF del destinatario inexistente | Rechazado → subsanación con `RechazoPrevio` = X → Correcto |
| A-10 | El mismo registro dos veces | Duplicado, sin estado nuevo |
| A-11 | 1.200 registros generados de golpe | Dos envíos (1.000 y 200) respetando `TiempoEsperaEnvio` |
| A-12 | Red cortada durante 2 horas | Incidencia, reintento cada hora como mucho, envío en orden al volver |
| A-13 | Consulta por emisor y periodo | Devuelve lo enviado |
| A-14 | QR de una factura de prueba en `prewww2…/ValidarQR` | La AEAT la encuentra |

### 5.10 Declaración responsable (E7)

- La firma Audax Studio como productora (FAQ de la AEAT: si el software lo desarrolla la propia empresa, lo certifica ella), por su representante legal.
- Contenido, en el orden del art. 15 de la Orden y del ejemplo 1 de la AEAT: nombre del sistema («Audax Proyectos»), código («AP»), versión completa, componentes y funciones, uso exclusivo como VERI\*FACTU (S), si admite varios obligados (N), tipo de firma (no aplica: solo VERI\*FACTU), razón social, NIF y dirección de la productora, la frase de cumplimiento del art. 29.2.j LGT, el RD 1007/2023, la Orden HAC/1177/2024 y la sede, y fecha y lugar.
- Dónde: «Ayuda › Declaración responsable» y «Facturación › Ajustes › VeriFactu», visible para cualquiera que use Facturación, y descargable en PDF (legible fuera del sistema). No se envía a la AEAT; se entrega si la pide.
- Una por versión: cada despliegue que toque la emisión sube la versión del sistema y crea su declaración (`verifactu_declarations`); se conservan todas.

---

## 6. Pantallas y flujos

Todo dentro de la navegación de D-405 y del análisis UX (apartado 3.2), con los componentes de la app (`PageHeader`, `PageSection`, tablas con `aria-sort`, `KpiCard`, `StatusBadge`, `SearchableSelect`, `DatePicker`, `ui/sheet`), DM Sans 400 y 500, radio de 3 px, tokens del tema y textos en `lang/ui/billing.json` y `lang/es/billing.php`.

### 6.1 Barra lateral, sección Facturación

| Orden | Entrada | URL | Ruta | Quién | Llega en |
|---|---|---|---|---|---|
| 1 | Resumen | `/facturacion` | `billing.index` | `view-billing` | I1, con E2 |
| 2 | Facturas (con «Nueva factura») | `/facturacion/facturas` | `billing.invoices.index` | `view-billing` | Existe; E1 añade Nueva y las vistas Borradores, Programadas y Recurrentes |
| 3 | Por facturar | `/facturacion/por-facturar` | `billing.unbilled` | Como hoy (D-402); «Crear borrador» con `manage-billing` | E3 |
| 4 | Por cobrar | `/facturacion/por-cobrar` | `billing.receivables` | `view-billing` | E2 |
| 5 | Gastos | `/facturacion/gastos` | `billing.expenses.index` | `manage-expenses` | E5 |
| 6 | Libros | `/facturacion/libros` | `billing.books` | `view-billing` y Gestoría | E4 |
| 7 | Vendido frente a real | Como hoy | Como hoy | Como hoy | — |
| 8 | Por revisar (con contador) | Como hoy | Como hoy | Como hoy; suma gastos por aprobar y registros con error de la AEAT | E5, E7 |
| 9 | Ventas | Como hoy | Como hoy | Como hoy | — |
| 10 | Ajustes | `/facturacion/ajustes` | `billing.settings` | `view-billing`; cambiar, `manage-billing` | E1 a E7 |

«Mis gastos» (`/mis-gastos`, `expenses.mine`) no va en Facturación (la plantilla no la ve): va en el menú de la persona y en el botón «+» del móvil, junto a «Añadir horas».

### 6.2 Flujos principales

Nueva factura (E1):
1. «Nueva factura» abre el editor con el cliente (buscador) y, si viene de un proyecto, el proyecto.
2. Al elegir el cliente se rellenan la ficha fiscal, la forma de pago, el vencimiento, el idioma y el régimen; si falta un dato obligatorio (NIF, domicilio), se avisa en línea con el enlace a la ficha.
3. Líneas con el servicio del catálogo (rellena precio, unidad e impuesto), descripción libre, cantidad, precio y descuento; totales con el cuadro de impuestos a la derecha.
4. «Vista previa» abre el PDF con la marca «Borrador»; «Guardar borrador» lo deja en Borradores.
5. «Emitir» pide confirmación con el número que va a recibir y la fecha («Se emitirá como F270001 con fecha 04/01/2027. Una factura emitida no se puede cambiar»).
6. Tras emitir: la ficha con el número, el PDF (se genera en segundos; mientras, «Preparando el PDF»), y las acciones de la matriz con «Enviar» destacado.

Anular y Rectificar (E1): un diálogo con el motivo obligatorio y, en Rectificar, las líneas de la original para escribir la diferencia; resumen («Se emitirá CN270003 por −1.210,00 €») y confirmación.

Ficha de factura (I4, D-408): misma cabecera (lo pendiente de el total), PDF, proyecto y bolsa, línea de tiempo (emitida, enviada, vista, cobros, recordatorios, rectificativas, registro y, en E7, el estado en la AEAT) y un bloque plegado «Registro de facturación» con el número de orden, la huella y la anterior, para la gestoría o una inspección.

Programar (E3): en el editor, «Emitir el…» con una fecha futura y la casilla «Enviar al emitir».

Recurrentes (E3): vista de Facturas con nombre, cliente, periodicidad, próxima fecha, importe, modo y estado; ficha con el calendario de las próximas 12 y el historial; «Omitir este periodo», «Pausar», «Generar ahora».

Por facturar con «Crear borrador» (E3): la fila del cliente abre un panel lateral con lo pendiente (horas por proyecto, bolsas, excesos, fees), casillas para elegir, cómo agrupar las horas y «Crear borrador». Lleva al editor con las líneas hechas.

Gastos en el móvil (E5):
1. «+» → «Gasto» → «Hacer foto».
2. «Leyendo el ticket…» (5 a 15 s; se puede salir y volver, sigue en Mis gastos).
3. Formulario con la miniatura arriba (se amplía al tocar), los campos rellenos y los dudosos en ámbar; categoría y, si se quiere, proyecto.
4. «Enviar». Estado «Enviado» y un aviso cuando lo aprueben o lo devuelvan.

Gastos en el ordenador (E5): Gastos con las vistas Por aprobar, Todos, Por pagar, Por reembolsar y Proveedores; zona para arrastrar PDF o imágenes; la vista lateral (R1) con la imagen a la izquierda y los datos a la derecha, ✓ Aprobar y «Devolver…», con ↑ y ↓ para pasar al siguiente.

Libros (E4): periodo (mes, trimestre, año), «Emitidas», «Recibidas», «Resumen del IVA», «Comprobar la integridad» y «Descargar» (XLSX, CSV, ZIP de PDF); «Cerrar periodo» para admins; envíos programados a la gestoría.

Ajustes (E1 a E7): pestañas Emisor, Series, Impuestos, Servicios, Formas de pago, Plantillas de email, Recordatorios, Categorías de gasto, Quién ve Facturación (D-245), Holded y, en E7, VeriFactu (entorno, certificado y caducidad, estado de la cola, declaración responsable).

### 6.3 Resumen (I1) con la emisión

El bloque «Requiere atención» suma: borradores de recurrentes por revisar, programadas de hoy, facturas vencidas, horas del mes anterior sin facturar el día 10, gastos por aprobar, reembolsos pendientes y, en E7, registros pendientes o con error en la AEAT. Si no hay nada, el estado vacío grande «Todo al día» (degradado de marca permitido).

### 6.4 Accesibilidad y móvil

Cada pantalla nueva con su E2E sin desplazamiento lateral a 375 px y axe sin fallos graves; el editor de factura en el móvil va en una columna con los totales fijos abajo; los diálogos de Emitir, Anular y Rectificar atrapan el foco y lo devuelven.

---

## 7. Migración y corte

### 7.1 Series

- Opción A: Audax crea F y CN con formato `F[YY]####` y `CN[YY]####` (D-244), contadores de 2027 en 0. Holded no emite ninguna factura con fecha de 2027: el 28/12 se desactivan sus recurrentes y el 31/12 por la tarde se deja de aprobar en Holded.
- Opción B: el 31/3/2027 por la tarde se deja de aprobar en Holded; la última lectura (`app:holded-sync`) trae la última F27 y la última CN27; un admin fija los contadores de 2027 en esos números con `app:billing-set-counter` (auditado, solo si el número es mayor que el actual y coincide con lo leído de Holded). La primera de Audax es la siguiente.
- La serie PRU (pruebas) queda siempre fuera de los libros, del portal y de los informes.
- Las facturas de Holded quedan en el espejo con su número; rectificarlas después del corte se hace en Audax, con CN de Audax y la referencia a la original de Holded (`rectified_holded_invoice_id`).

### 7.2 Recurrentes de Holded

- E3 lee las 16 recurrentes de Holded (`GET /recurring-invoices` de la API v2, solo lectura, con la clave de D-399) y las crea en Audax en pausa, con cliente, proyecto (sugerido como en D-388), líneas, periodicidad y próxima fecha. Un admin las revisa una a una («Revisada»).
- En el corte: el 28/12 se desactivan en Holded y se activan en Audax, con la próxima fecha que tenían; los borradores del 29/12 ya los crea Audax.

### 7.3 Mes en paralelo y decisión

- Del 1 al 18/12/2026, cada factura que se apruebe en Holded se rehace en Audax en la serie PRU (con «Duplicar» desde la de Holded o desde Por facturar) y un informe compara cliente, base, cuota y total con la de Holded. Diferencia de un céntimo = revisar el redondeo antes del corte.
- La gestoría recibe a mitad de mes el libro de emitidas de octubre y noviembre (del espejo de Holded) y el de pruebas de diciembre, la plantilla del PDF y una rectificativa de ejemplo, y los importa en su programa.
- El lunes 21/12/2026 se aplica la regla del apartado 1.2: aplazamiento en el BOE, validación de la gestoría y lista de E1 a E4 hecha. Se registra la decisión en `docs/DECISIONES.md`.

### 7.4 Runbook del corte (E6, opción A)

| Cuándo | Qué | Quién |
|---|---|---|
| 21/12 | Decisión. Encender `invoicing` en producción para quien emite (sigue apagado para el resto) | Propietario |
| 28/12 | Recurrentes: desactivar en Holded y activar en Audax | Admin |
| 31/12 tarde | Última aprobación en Holded; `app:holded-sync --forzar` con PDF | Admin |
| 1/1 | Comprobar contadores F27 y CN27 en 0, la cadena verificada y la instalación de producción activa | Admin |
| 4/1 | Primeras facturas reales; el Resumen avisa de los borradores de las recurrentes | Quien factura |
| 4/1 – 29/1 | Cobros de 2026: siguen en Holded hasta su baja (la lectura nocturna los trae); los de 2027, en Audax | Admin |
| Antes del 30/1 | La gestoría presenta el 4T y el 390 de 2026 con los datos de Holded | Gestoría |

Plan de vuelta atrás: si algo falla en enero antes de la primera factura real, se reactiva Holded (recurrentes y aprobación) sin pérdida. Si ya se emitió alguna en Audax, Holded no puede seguir la misma serie: se sigue en Audax y se corrige el fallo (por eso el corte se decide con E1 a E4 probados).

### 7.5 El histórico

- Ya está en solo lectura (D-385 a D-389): 910 facturas leídas con sus PDF originales, contactos casados y enlaces con proyectos y bolsas.
- Antes de la baja, una lectura completa con todos los PDF que falten (sin el límite por noche de D-389) y una comprobación: número de facturas y suma de bases por año en Holded frente al espejo.

### 7.6 Cierre del trimestre y baja de Holded (E8)

- 4T de 2026 y anuales (390, 347): con Holded, como siempre.
- Exportar antes de cancelar (al cancelar, Holded borra los datos, H-141):
  - libro de emitidas de cada año en XLSX desde Holded y desde Audax (deben coincidir);
  - los PDF de todas las facturas (en el disco privado de Audax, D-389);
  - si la gestoría lo pide, la exportación de A3 o Sage de Holded (H-117, H-118);
  - contactos y recurrentes en Excel, como copia.
- Cancelar Holded en marzo de 2027, después del 347 y con el visto bueno de la gestoría; antes, si el plan lo permite, bajar a uno más barato que conserve la API de lectura.
- Después de la baja: la lectura nocturna se apaga (`HOLDED_API_KEY` fuera del `.env`), el espejo queda congelado y los cobros pendientes de facturas de Holded se registran en Audax (3.3).

---

## 8. Riesgos y pruebas

### 8.1 Los cinco riesgos principales

| Riesgo | Probabilidad e impacto | Mitigación |
|---|---|---|
| R-1 · El BOE no publica el aplazamiento antes del 1/1/2027 y Audax emite desde un programa sin VeriFactu | Media; 50.000 € por ejercicio al usuario y 150.000 € al productor | Regla del 21/12: sin BOE no hay corte; Holded sigue y se adelanta E7; corte el 1/4/2027 |
| R-2 · Numeración rota en el corte (duplicados, huecos o una factura de 2027 aprobada en Holded) | Baja con la opción A; alta si se improvisa | Corte a principio de año con serie nueva, recurrentes desactivadas el 28/12, contadores comprobados el 1/1, *trigger* y test de concurrencia |
| R-3 · Audax es productora de su programa: un error de cálculo, de redondeo o de inalterabilidad es responsabilidad suya | Media | Registro con *triggers* que recalculan la huella, verificación nocturna, batería T-* en la CI, casos de la AEAT, declaración responsable revisada por la gestoría |
| R-4 · La gestoría no puede trabajar con lo que exportamos o pierde datos al dar de baja Holded | Media | Pregunta G-1 antes de E4, formato oficial de la AEAT, mes en paralelo con sus importaciones, baja solo tras el 347 y con todo exportado |
| R-5 · Gastos con IA: un IVA deducible mal leído, una inversión del sujeto pasivo sin marcar o una foto tomada por original | Media | Nada se guarda sin revisión humana, comprobaciones aritméticas y de NIF, aprobación de un admin, «IVA deducible» solo con requisitos, papel conservado (G-01) |

Otros, menores: el SMTP del relé (el remitente tiene que ser un usuario o alias del dominio), Gotenberg caído al emitir (la factura existe, el PDF se reintenta), caducidad del certificado (aviso a 30 días), la presión de diciembre (el mes en paralelo puede acortarse a dos semanas).

### 8.2 Batería de cumplimiento (Pest, en SQLite en el Mac y en PostgreSQL en el servidor y la CI)

| Id | Qué demuestra | Cómo |
|---|---|---|
| T-HASH | La huella es la de la AEAT | Los tres ejemplos oficiales (v0.1.2) con su resultado exacto; más casos propios: importes negativos, «123.10» frente a «123.1», espacios al principio y al final, `numserie` con `/`, `&` y tildes |
| T-CAD | La cadena no tiene huecos ni bifurcaciones | Emitir, anular y rectificar en secuencia y comprobar `seq`, anterior y primer registro; `app:billing-verify-chain` detecta una fila alterada (con los *triggers* quitados dentro del test) |
| T-INM | Lo emitido no se cambia | En PostgreSQL: `UPDATE`, `DELETE` y `TRUNCATE` sobre `invoice_records` fallan; cambiar un campo fiscal de una emitida falla; cambiar su nota interna funciona; una línea de una emitida no se toca |
| T-NUM | Correlatividad | Dos emisiones a la vez (dos conexiones en PostgreSQL) dan números consecutivos; un borrador eliminado no deja hueco; los contadores no bajan; no se emite con fecha anterior a la última; reinicio anual |
| T-TOT | Totales | Casos compartidos PHP/TS (`tests/fixtures/billing/totals.json`): descuentos, varios tipos, rectificativas, cuotas al céntimo; el cuadro del PDF suma lo mismo que el registro |
| T-SNAP | Copias congeladas | Cambiar la ficha fiscal del cliente o el emisor no cambia una factura emitida ni su PDF |
| T-RECT | D-244 | Anular emite CN por el total y desbloquea horas; Rectificar emite la diferencia y no desbloquea; motivo obligatorio; serie CN |
| T-PDF | Contenido mínimo | El HTML del PDF contiene cada dato de L-01 a L-10 y L-17 (español e inglés); el PDF archivado no cambia y su SHA-256 coincide |
| T-RET | Conservación | Ninguna tabla ni ruta de facturación o gastos entra en `app:prune-data`; los ficheros están en el disco privado incluido en la copia |
| T-LIB | Libros | Las columnas del libro coinciden con el diseño LSIJ de la AEAT; un libro de ejemplo se valida en el Pre303 de la AEAT (prueba manual con la gestoría) |
| T-GAS | Gastos | Duplicados, número de recepción correlativo, IVA deducible según el tipo, inversión del sujeto pasivo, retención; extracción con `FakeLlm` y ficheros de ejemplo |
| T-XSD | XML (E7) | Cada tipo de registro valida contra las XSD v1.0 locales |
| T-FLOW | Envío (E7) | Lotes de 1.000, espera de `TiempoEsperaEnvio`, incidencia con reintentos, orden, `Http::fake` con respuestas reales guardadas del entorno de pruebas |
| T-QR | QR (E7) | URL codificada como el ejemplo oficial (`numserie=12345678%26G33`), tamaño, nivel M y posición |
| T-DR | Declaración (E7) | Visible en Ayuda para quien usa Facturación y descargable |
| T-AEAT | Entorno de pruebas (E7) | Casos A-01 a A-14 (5.9), a mano, con las respuestas guardadas para los tests |

También: matriz de permisos (`manage-billing`, `view-billing`, `submit-expenses`, `manage-expenses`, Gestoría, excluidos de D-245), aislamiento del portal, Vitest (formateadores, estado relativo, `DocumentActions` compartido) y Playwright (emitir, anular, rectificar, cobrar, enviar, recurrente, gasto con foto con `setInputFiles` a 375 px, axe).

### 8.3 Pruebas con la gestoría

1. Antes de E1: plantilla del PDF y menciones (G-4).
2. Durante E4: libro de emitidas y de recibidas de un mes, importados en su programa (G-1).
3. Mes en paralelo: comparación de diciembre y una rectificativa de cada tipo (G-2).
4. Antes de E5: plan de cuentas de las categorías y tratamiento de los tickets (G-3).
5. Antes de E7: lectura de la declaración responsable.

---

## 9. Entregas

Cada entrega se despliega con su parte apagada: módulos nuevos `invoicing` (emisión) y `expenses` (gastos), que dependen de `billing` y siguen sus reglas (apagado: 404; en modo de prueba: solo admins, D-239; exclusiones de D-245), más el ajuste `verifactu.mode` (`off`, `test`, `production`) para E7. Las jornadas son de desarrollo, con tests y documentación; no incluyen esperas por respuestas.

| Entrega | Contenido | Equivale a | Jornadas | Depende de |
|---|---|---|---|---|
| E1 · Emitir facturas ✅ (D-417 a D-429) | Catálogo (servicios de Holded), impuestos, formas de pago, series F, CN y PRU, instalaciones, ajustes del emisor ampliados; editor; emitir con número, copias, desglose, registro encadenado con *triggers* y PDF archivado; duplicar, descargar, anular y rectificar (D-244), anulación por error (admin); listado unificado con la vista `billing_documents`; `manage-billing`; matriz `DocumentActions`; `app:billing-verify-chain` | F2 y parte de F5 | 7 a 9 | Respuestas P-2, P-5, G-4 |
| E2 · Cobrar, enviar y portal | Cobros y anulación de cobros, Por cobrar con antigüedad, plantillas de email, envío con PDF y enlace firmado, vistas por el cliente, recordatorios por escalones, portal del cliente, adjuntos, cadena de documentos, Resumen (I1) | F5 | 5 a 6 | P-4 (remitente) |
| E3 · Recurrentes, programadas y facturar desde el trabajo | Recurrentes con palabras dinámicas y «Omitir», programadas, importación de las 16 de Holded en pausa, «Crear borrador» desde Por facturar, proyecto y bolsa, bloqueo de entradas concretas | F4 (sin Holded) y F5 | 6 a 7 | E1 |
| E4 · Libros y gestoría | Libros de emitidas (y de recibidas cuando llegue E5) en el formato LSIJ, ZIP de PDF, resumen del IVA, envío programado, rol Gestoría con 2FA, cierre de periodo | F5 | 3 a 4 | G-1 |
| E5 · Gastos con foto | Proveedores, categorías, gastos y tickets, foto en el móvil y arrastrar en el ordenador, extracción con Gemini (`LlmInlineFile`), revisión, duplicados, aprobación con número de recepción, pagos y reembolsos, libro de recibidas, texto RGPD | Nuevo (D-249) | 7 a 9 | P-6, G-3 |
| E6 · Corte | Runbook del apartado 7, contadores, recurrentes, comprobaciones | F7 (primera parte) | 1 a 2 | E1 a E4 y la decisión del 21/12 |
| E7 · VeriFactu | XML y XSD, cola con control de flujo e incidencias, estados y subsanación, consulta, QR en el PDF, cliente SOAP con certificado, script del certificado, declaración responsable, pruebas A-01 a A-14 | F6 | 9 a 11 | Certificado (P-3) |
| E8 · Baja de Holded | Lectura final, comprobaciones, exportaciones, congelar el espejo, cobros de Holded en Audax | F7 (segunda parte) | 1 a 2 | 347 presentado |
| E9 · Factura electrónica B2B | Recepción de UBL desde la solución pública a Gastos (antes de octubre de 2027, si aplica: G-8); emisión en UBL EN 16931 con copia fiel y estados de pago (antes de octubre de 2028) | F8 | 8 a 12 | Especificaciones técnicas de la solución pública |

Total hasta el corte (E1 a E6): de 29 a 37 jornadas, en las once semanas del calendario (1.3).

Lo que no tiene entrega: presupuestos e hitos (L4), proformas, conciliación bancaria (L6), remesas SEPA, multimoneda, OCR propio y buzón de gastos.

Decisiones registradas en `docs/DECISIONES.md`: las de E1, D-417 a D-429 (arquitectura del registro y su verificación, series e instalación de pruebas, matriz estado → acciones y `manage-billing`, vista `billing_documents`). Quedan por registrar, con su entrega: el flujo de gastos y sus permisos (E5) y el calendario con la regla del 21/12.

---

## 10. Preguntas

### 10.1 Para el propietario

- P-1 · ¿Tenéis activado VeriFactu en Holded (envío de las facturas a la AEAT)? Si es así, no cambia el plan con la opción A, pero condiciona la B (art. 16.5).
- P-2 · ¿Corte el 1/1/2027 con F27 y CN27 empezando en 0001 en Audax (recomendado), o el 1/4/2027?
- P-3 · Certificado para E7 (hace falta en enero de 2027): ¿de representante de persona jurídica de la FNMT (el habitual) o de sello? ¿Quién lo tiene?
- P-4 · ¿Desde qué dirección salen las facturas: administracion@ (ya funciona con el relé) o un alias como facturacion@? ¿A qué dirección responde el cliente?
- P-5 · ¿Quién puede emitir, anular y rectificar: solo los admins o también alguien de finanzas? ¿Los gestores de proyecto preparan borradores?
- P-6 · Gastos: ¿cualquier persona de la plantilla sube sus tickets y aprueba un admin? ¿Se reembolsa lo pagado de su bolsillo (por transferencia o en la nómina)? ¿Hay tarjeta de empresa?

### 10.2 Para la gestoría

- G-1 · ¿Qué programa usáis y qué formato os sirve: los libros en el formato de la AEAT (XLSX o CSV) más los PDF, u otro (A3, Sage, Contasol)? ¿Cada mes o cada trimestre?
- G-2 · Rectificativas: ¿R1 para los errores y la anulación por el total, y R4 para el resto? ¿Necesitáis la rectificación por sustitución o basta por diferencias?
- G-3 · Gastos: ¿qué cuentas para cada categoría? ¿Los tickets sin NIF de Audax van al libro de recibidas? ¿Nos pasáis la serie del número de recepción que usáis hoy?
- G-4 · ¿Validáis la plantilla del PDF y las menciones (intracomunitarias, fuera de la UE, exentas, Registro Mercantil)?
- G-5 · ¿Cerramos el 4T y el año 2026 con Holded y damos de baja Holded después del 347? ¿Necesitáis alguna exportación de Holded antes?
- G-6 · Retenciones (111 y 115): ¿las calculáis vosotros con las facturas o las queréis en la exportación?
- G-7 · Digitalización: ¿guardamos el papel de los tickets o usáis digitalización certificada?
- G-8 · Factura electrónica: ¿desde cuándo tendrá que recibir Audax facturas electrónicas de proveedores grandes (octubre de 2027) y comunicar su pago?

---

## Anexo A. Fuentes

Todas consultadas el 09/10/2026. «(a)» es norma publicada en el BOE; «(b)», documento técnico, nota o FAQ oficial; «(c)», documentación de un proveedor.

Normas:
- (a) RD 1007/2023 (Reglamento de sistemas informáticos de facturación), texto consolidado actualizado el 03/12/2025: https://www.boe.es/buscar/act.php?id=BOE-A-2023-24840
- (a) Orden HAC/1177/2024, de 17 de octubre (BOE del 28/10/2024, en vigor el 29/10/2024): https://www.boe.es/buscar/act.php?id=BOE-A-2024-22138
- (a) RDL 15/2025, de 2 de diciembre (BOE del 03/12/2025): https://www.boe.es/buscar/doc.php?id=BOE-A-2025-24446
- (a) RD 1619/2012 (Reglamento de facturación), consolidado a 31/03/2026: https://www.boe.es/buscar/act.php?id=BOE-A-2012-14696
- (a) RD 1624/1992 (Reglamento del IVA), arts. 63 y 64: https://www.boe.es/buscar/act.php?id=BOE-A-1992-28925
- (a) Ley 37/1992 del IVA, arts. 84 y 97: https://www.boe.es/buscar/act.php?id=BOE-A-1992-28740
- (a) Código de Comercio, arts. 24 y 30: https://www.boe.es/buscar/act.php?id=BOE-A-1885-6627
- (a) Ley 58/2003 General Tributaria, arts. 29.2.j y 201 bis (por la Ley 11/2021): https://www.boe.es/buscar/act.php?id=BOE-A-2021-11473
- (a) RD 238/2026 (factura electrónica B2B), BOE del 31/03/2026: https://www.boe.es/buscar/act.php?id=BOE-A-2026-7295
- (a) Orden HAC/1028/2026, de 2 de octubre (BOE del 05/10/2026, en vigor el 06/10/2026): https://www.boe.es/buscar/doc.php?id=BOE-A-2026-20587
- (a) Orden EHA/962/2007 (digitalización certificada): https://www.boe.es/eli/es/o/2007/04/10/eha962
- (a) Sumarios del BOE del 05 al 09/10/2026, sin norma sobre VeriFactu: https://www.boe.es/boe/dias/2026/10/05/, https://www.boe.es/boe/dias/2026/10/06/, https://www.boe.es/boe/dias/2026/10/07/, https://www.boe.es/boe/dias/2026/10/08/ y https://www.boe.es/boe/dias/2026/10/09/
- (b) Nota de Hacienda del 05/10/2026 sobre el aplazamiento: https://www.hacienda.gob.es/sgt/gabsehacienda/nota-informativa-verifactu.pdf

Documentación técnica de la AEAT (portal de desarrolladores, https://www.agenciatributaria.es/AEAT.desarrolladores/Desarrolladores/_menu_/Documentacion/Sistemas_Informaticos_de_Facturacion_y_Sistemas_VERI_FACTU/Sistemas_Informaticos_de_Facturacion_y_Sistemas_VERI_FACTU.html):
- (b) Especificaciones de la huella, v0.1.2 (27/08/2024): https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/Veri-Factu_especificaciones_huella_hash_registros.pdf
- (b) Especificaciones del QR y del servicio de cotejo, v0.5.0 (10/12/2025): https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/DetalleEspecificacTecnCodigoQRfactura.pdf
- (b) Descripción de los servicios web, v1.0.3 (28/07/2025): https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/Veri-Factu_Descripcion_SWeb.pdf
- (b) WSDL y XSD v1.0 (producción): https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl, `SuministroLR.xsd`, `SuministroInformacion.xsd` (servido con fecha del 11/01/2026), `RespuestaSuministro.xsd`, `ConsultaLR.xsd` y `RespuestaConsultaLR.xsd` en la misma carpeta
- (b) Diseños de registro de facturación v1.0 (Excel): https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/DsRegistroVeriFactu.xlsx
- (b) Validaciones y errores, v1.2.2: https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/Validaciones_Errores_Veri-Factu.pdf
- (b) Aclaraciones a dudas de los desarrolladores, v1.3 (04/12/2025): https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/FAQs-Desarrolladores.pdf
- (b) Ejemplos de declaraciones responsables: https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/EjemplosDeclaracionResponsable.pdf

Sede de la AEAT:
- (b) VeriFactu, página principal: https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu.html
- (b) FAQ, declaración responsable (actualizadas a 21/07/2026; página del 07/10/2026): https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes/certificacion-sistemas-informaticos-declaracion-responsable.html
- (b) FAQ, ámbitos de aplicación: https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes/cuestiones-generales-ambitos-aplicacion.html
- (b) FAQ, colaboración social y remisión por terceros: https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes/colaboracion-social.html
- (b) Diseños de registro normalizados de los libros (LSIJ.xlsx y LSI.xlsx, 01/01/2026): https://www3.agenciatributaria.gob.es/Sede/iva/pre-303/nuevo-servicio-pre303-importacion-libros-electronico/disenos-registro-normalizados-libros-registro.html
- (b) Homologación de software de digitalización certificada (FZ01): https://sede.agenciatributaria.gob.es/Sede/procedimientos/FZ01.shtml

Otros:
- (c) Términos de la API de Gemini (actualizados el 28/04/2026): https://ai.google.dev/gemini-api/terms
- (c) Holded: `docs/HOLDED-INVENTARIO.md` (H-001 a H-146).

## Anexo B. Lo que se reutiliza de la app

| Pieza | Para qué |
|---|---|
| `TimeLockService` (D-034) | Bloqueo al emitir y desbloqueo al anular, con un método nuevo para entradas concretas |
| `EntryValuation`, `RevenueCalculator`, `Money`, `Cents` (D-043, D-082, D-083) | Importes de las líneas de horas, bolsas y excesos, al céntimo |
| `HourBankLedger` | Horas y exceso de cada bolsa |
| `BillingReportController` (Por facturar), `SoldVsActual`, `InvoicingReport`, `InvoiceList` | Pantallas existentes, que pasan a leer la vista `billing_documents` |
| Gotenberg y la hoja de documentos (D-140) | PDF de facturas y libros |
| Colas de Horizon (`mail`, `ai-high`, nueva `verifactu`) y el programador | Envíos, recurrentes, programadas, lecturas de gastos, envío a la AEAT, verificación nocturna |
| Notificaciones (`AppNotification`) | Gastos aprobados o devueltos, recurrentes generadas, errores de la AEAT, caducidad del certificado |
| Portal del cliente (D-097) | Pestaña Facturas |
| `GeminiClient`, `LlmRequest`, `AiUsageRecorder`, `AiDailyLimits` (D-146, D-243) | Lectura de gastos |
| `GenerateAttachmentThumbnail` y `attachments` | Miniaturas y adjuntos |
| *Triggers* de `create_people_r2_tables` y `RegisterGuards` | Patrón de solo alta en PostgreSQL y SQLite |
| `HttpHoldedClient` (D-384) | Lectura de las recurrentes y lectura final |
| Envíos programados de informes (D-139) | Libros a la gestoría |
| Colaborador externo (D-134) | Rol Gestoría |
| `~/.config/audax-clave-holded.sh` | Modelo del script del certificado |
