# Facturación en Audax Proyectos: investigación legal, encaje y propuesta

_Investigación del 06/10/2026, en solo lectura (rama `investigacion-facturacion`). No se ha escrito código ni se ha tocado el servidor. El inventario de Holded, con sus ids `H-001…H-146`, está en `docs/HOLDED-INVENTARIO.md`._

> **Aviso.** Esto es una investigación técnica, no asesoramiento fiscal. Antes de emitir la primera factura desde Audax, la gestoría tiene que revisar el §2 y las preguntas P1, P4 y P5.

**Petición del propietario (06/10/2026):** «Hazlo lo más parecido posible, y por parecido me refiero a forma de funcionar y trabajar junto a las funcionalidades. El look and feel, obviamente, el nuestro». Objetivo de negocio: cruzar **horas vendidas frente a horas reales** y que la gestoría pueda entrar.

**Cambia el SPEC.** El SPEC §18 deja fuera la «facturación contable (solo exportamos datos para facturar)». Cuando el propietario apruebe este plan, se registra como decisión nueva en `docs/DECISIONES.md` (la siguiente libre), que cambia el SPEC §18 y amplía D-034, D-043 y D-045.

---

## Resumen

1. **VeriFactu:** el anuncio es **real, pero no es norma**. El 05/10/2026 el Ministerio de Hacienda publicó una nota informativa que «prevé» aplazar VeriFactu a **octubre de 2028**. **En el BOE no hay nada todavía**: la fecha legal para una sociedad sigue siendo el **01/01/2027** (RDL 15/2025). Además, las Cortes se disuelven el 06/10/2026 (elecciones el 29/11), lo que complica que la norma salga antes de fin de año. **Hasta el 31/12/2026 basta con el RD 1619/2012; a partir del 01/01/2027, solo si el aplazamiento se publica en el BOE.**
2. **Si Audax emite sus facturas con su propio programa, Audax es el «productor» del software** (FAQ de la AEAT): firma la declaración responsable y responde de que cumple. La sanción por usar un programa que no cumple es de **50.000 € por ejercicio**.
3. **La factura electrónica B2B ya tiene fecha:** RD 238/2026 (BOE del 31/03/2026) y Orden HAC/1028/2026 (BOE del 05/10/2026, en vigor el 06/10/2026). Para una empresa de 8 M€ o menos es obligatoria **en octubre de 2028** (24 meses), en formato estructurado (UBL, CII, Facturae o EDIFACT con EN 16931) y con el estado de pago comunicado.
4. **Lo que hay que calcar de Holded:** borrador sin número → **Aprobar** (número correlativo y bloqueo) → enviar → cobrar → anular o rectificar; presupuesto → aceptación en el portal → hitos → factura; **facturar horas** desde el proyecto eligiendo cómo agrupar las líneas; recurrentes para los fees; libro de emitidas y acceso de la gestoría.
5. **Lo que Audax ya tiene y Holded no:** horas aprobadas con su tarifa congelada, bolsas con su exceso, el bloqueo «al facturar» y una valoración al céntimo (D-083). La facturación se apoya en eso.
6. **Recomendación (P1):** construir el módulo entero, pero **que Holded siga emitiendo** (Audax le manda las facturas por la API) **hasta que se cumpla una de dos condiciones**: que el aplazamiento salga en el BOE o que Audax tenga VeriFactu terminado. Desde el primer día, la emisión propia se diseña con un registro encadenado e inalterable, para no rehacer nada.

---

## 1. Alcance

**Entra (lo esencial del inventario):**
- datos fiscales de clientes y del emisor, catálogo de servicios, impuestos, formas de pago y series,
- presupuestos (con aceptación en el portal y previsión de facturación por hitos), proformas,
- facturas, rectificativas, anulación y facturas recurrentes,
- generación desde la app: horas aprobadas, bolsas, excesos, fees e hitos de precio cerrado, con el bloqueo de horas,
- vencimientos, cobros (también parciales), pendiente de cobro y recordatorios,
- envío por email con plantillas, PDF de Audax y portal del cliente,
- informes: ventas, libro de facturas emitidas y **vendido frente a real**,
- acceso de la gestoría y exportaciones,
- migración desde Holded,
- VeriFactu y factura electrónica B2B, cuando toquen.

**No entra (sobra para una agencia o lo hace la gestoría):** inventario, albaranes y pedidos, tickets, pasarelas de pago, OSS, contabilidad (asientos y modelos de impuestos), escáner de gastos, pipelines y campos personalizados.

**Después, si hace falta (P6):** conciliación bancaria, remesas SEPA, multimoneda y compras imputadas a proyectos.

---

## 2. Requisitos legales vigentes a 06/10/2026

**Tipo de fuente:** **(a)** norma publicada en el BOE · **(b)** nota o FAQ oficial · **(d)** fuente no oficial.

### 2.1 VeriFactu (Reglamento de sistemas informáticos de facturación)

| Qué | Estado real | Fuente |
|---|---|---|
| Base legal | Art. 29.2.j LGT (integridad, conservación, accesibilidad, legibilidad, trazabilidad e inalterabilidad) y art. 201 bis LGT (sanciones), por la Ley 11/2021 | (a) [BOE-A-2021-11473](https://www.boe.es/buscar/act.php?id=BOE-A-2021-11473) |
| Reglamento | RD 1007/2023, de 5 de diciembre | (a) [BOE-A-2023-24840](https://www.boe.es/buscar/act.php?id=BOE-A-2023-24840) |
| Especificaciones técnicas | Orden HAC/1177/2024 (registros de alta y anulación en XML, huella, QR…) | (a) [BOE-A-2024-22138](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2024-22138) |
| Primer aplazamiento | RD 254/2025: 01/01/2026 sociedades y 01/07/2026 resto | (a) [BOE-A-2025-6600](https://www.boe.es/buscar/doc.php?id=BOE-A-2025-6600) |
| **Fecha vigente hoy** | **RDL 15/2025**, de 2 de diciembre (BOE del 03/12/2025, convalidado el 11/12/2025): **01/01/2027** para quien declara el Impuesto sobre Sociedades y **01/07/2027** para el resto. Mantiene el rango reglamentario: se puede volver a cambiar con un real decreto | (a) [BOE-A-2025-24446](https://www.boe.es/buscar/doc.php?id=BOE-A-2025-24446), [BOE-A-2025-25695](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-25695) |
| **Anuncio del 05/10/2026** | Nota del Gabinete de Prensa de Hacienda: se procederá «al aplazamiento de las obligaciones pendientes» del RD 1007/2023 «hasta octubre de 2028», para alinearlo con la factura electrónica B2B; los requisitos de integridad, trazabilidad e inalterabilidad «se mantendrán en términos sustancialmente equivalentes». **No dice con qué norma ni qué día** | (b) [nota de Hacienda (PDF)](https://www.hacienda.gob.es/sgt/gabsehacienda/nota-informativa-verifactu.pdf) |
| ¿Está en el BOE? | **No.** Los sumarios del 05/10 y del 06/10/2026 no traen ninguna modificación del RD 1007/2023. La página de VeriFactu de la AEAT enlaza la comunicación, pero su nota y sus FAQ siguen diciendo 01/01/2027 y 01/07/2027 | (a) [BOE 05/10](https://www.boe.es/boe/dias/2026/10/05/), [BOE 06/10](https://www.boe.es/boe/dias/2026/10/06/); (b) [AEAT, VeriFactu](https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu.html), [nota de plazos](https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/nota-informativa-ampliacion-plazo-adaptacion-facturacion.html) |
| Contexto | El BOE del 06/10/2026 publica el RD 806/2026, que disuelve las Cortes (elecciones el 29/11/2026). El aplazamiento puede hacerse por real decreto, pero tiene que publicarse **antes del 01/01/2027**; si no, esa fecha sigue siendo exigible | (a) BOE del 06/10/2026; análisis propio |
| Qué exige al programa | Registro de alta automático al expedir cada factura (art. 9) y de anulación; **huella (hash) encadenada** con el registro anterior y firma (arts. 10 y 12); registro de eventos si no se usa la modalidad VERI\*FACTU; **QR** y la leyenda «Factura verificable en la sede electrónica de la AEAT» o «VERI\*FACTU»; **declaración responsable del productor**, visible en el programa (art. 13). En modalidad VERI\*FACTU (envío de cada registro a la AEAT al momento) no hace falta firmar los registros ni el registro de eventos (art. 16.3) | (a) RD 1007/2023; (b) [FAQ de la AEAT](https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes/sistemas-verifactu.html) |
| **Programa propio** | «Si el software hubiera sido desarrollado por la propia empresa, será esta la que deba certificarlo»: Audax sería productor y firmaría la declaración responsable. No hay consulta de la DGT sobre este punto | (b) [FAQ de la AEAT, 22/07/2026](https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes/certificacion-sistemas-informaticos-declaracion-responsable.html?faqId=4e5b77fe52572910VgnVCM100000dc381e0aRCRD) |
| Sanciones (art. 201 bis LGT) | Usuario: **50.000 € por ejercicio** por usar un programa no conforme o alterado. Productor: 150.000 € por ejercicio | (b) [FAQ de la AEAT](https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes.html) |
| SII | Obligatorio por encima de 6.010.121,04 € de volumen de operaciones, en el REDEME o en grupos de IVA. Quien lleva el SII queda fuera de VeriFactu. Audax, previsiblemente, no (P8) | (b) [AEAT, SII](https://sede.agenciatributaria.gob.es/Sede/ayuda/manuales-videos-folletos/manuales-practicos/manual-gran-empresa/son-consecuencias-superar-umbral-6_010_12104/suministro-inmediato-informacion-iva.html) |
| TicketBAI y Navarra | No aplican: Audax tiene el domicilio fiscal en territorio común (Valencia) | (b) [AEAT, el Reglamento](https://sede.agenciatributaria.gob.es/Sede/normativa-criterios-interpretativos/analisis/El__Reglamento_Veri_factu_.html) |

**Conclusión sobre lo que dijo el propietario:** el anuncio existe y es oficial (nota de prensa), pero **no es norma**. «Basta con el RD 1619/2012» es cierto **hasta el 31/12/2026**. Desde el 01/01/2027 solo lo será si antes se publica el aplazamiento en el BOE. Hay que vigilar el BOE hasta fin de año.

### 2.2 Factura electrónica B2B (Ley 18/2022, «Crea y Crece»)

| Qué | Estado real | Fuente |
|---|---|---|
| Reglamento | **RD 238/2026**, de 25 de marzo (BOE del 31/03/2026), en vigor desde el 20/04/2026. Solo para destinatarios empresarios o profesionales establecidos **en España**. Formatos: modelo semántico **EN 16931** con sintaxis UBL, CII, Facturae o EDIFACT. Plataformas privadas interconectadas y **solución pública gratuita de la AEAT**. Estados obligatorios: aceptación o rechazo y **pago efectivo completo** (en 4 días) | (a) [BOE-A-2026-7295](https://www.boe.es/buscar/act.php?id=BOE-A-2026-7295); (b) [AEAT](https://sede.agenciatributaria.gob.es/Sede/iva/novedades-iva/novedades-normativa-2026/real-decreto-238-2026-25-marzo.html) |
| Orden de desarrollo | **Orden HAC/1028/2026**, de 2 de octubre (BOE del 05/10/2026), en vigor el **06/10/2026**: con ella empiezan a contar los plazos. UBL con EN 16931 para la solución pública; acceso con certificado o Cl@ve | (a) [BOE-A-2026-20587](https://www.boe.es/buscar/doc.php?id=BOE-A-2026-20587) |
| Plazos | Más de 8 M€: a los 12 meses (**octubre de 2027**). **8 M€ o menos (previsiblemente Audax, P8): a los 24 meses, octubre de 2028** (por cálculo, el 06/10/2028; la nota de Hacienda dice «octubre de 2028»). El aplazamiento del estado de pago de la DT 3.ª es solo para personas físicas | (a) RD 238/2026, DF 4.ª; (b) nota de Hacienda |
| Como receptor | Según fuentes no oficiales, Audax tendría que poder **recibir** facturas electrónicas de proveedores grandes desde octubre de 2027. **[SIN VERIFICAR en el texto oficial]** | (d) |
| Cambios en el RD 1619/2012 | El RD 238/2026 añade el art. 8 bis y da nueva redacción a los arts. 9 y 10 (factura electrónica y su conservación) | (a) RD 238/2026, DF 1.ª |

### 2.3 Reglamento de facturación (RD 1619/2012): lo que el programa tiene que cumplir ya

Fuente: (a) [BOE-A-2012-14696](https://www.boe.es/buscar/act.php?id=BOE-A-2012-14696), consolidado a 31/03/2026.

| Id | Requisito | Artículo | Cómo lo cumple el módulo |
|---|---|---|---|
| L-01 | Factura de todas las operaciones con empresarios, también las exentas y las no sujetas | 2 | Toda venta sale como factura completa; no hay tickets |
| L-02 | **Contenido:** número y serie; fecha de expedición; razón social y NIF del emisor y del destinatario (NIF-IVA del otro Estado en intracomunitarias o con inversión del sujeto pasivo); domicilios; descripción; base, tipo y cuota; **fecha de la operación** si es distinta | 6.1 | Validaciones al **Aprobar**; datos del cliente y del emisor **copiados** en la factura (no se recalculan si cambia la ficha) |
| L-03 | Bases desglosadas por tipo de IVA, exentas e inversión del sujeto pasivo | 6.2 | Cuadro de impuestos del PDF agrupado por tipo |
| L-04 | **Menciones:** «Inversión del sujeto pasivo» (art. 6.1.m), exención con su artículo (6.1.j) y, si aplica, «Régimen especial del criterio de caja» | 6.1 j, m, p | Texto automático según el régimen de la línea y del cliente |
| L-05 | **Numeración correlativa** dentro de cada serie; **series separadas obligatorias** para las rectificativas y las expedidas por el destinatario o por terceros | 6.1.a | Número asignado al aprobar, en transacción y con candado de serie; serie de rectificativas vinculada; aviso de fecha desordenada (como Holded, H-039) |
| L-06 | **Rectificativas:** cuando falta un requisito o la cuota está mal, hasta 4 años; identifican la factura rectificada y el motivo | 15 | Solo desde la original o eligiéndola; motivo obligatorio; por diferencias o por sustitución |
| L-07 | **Plazo:** con un empresario, antes del día 16 del mes siguiente al devengo | 11 | Aviso en «Pendiente de facturar» con lo del mes anterior |
| L-08 | Cualquier moneda y lengua, pero **la cuota de IVA en euros** | 12 | Si la moneda no es el euro, el PDF muestra la cuota en euros y el cambio |
| L-09 | Un solo original; duplicados solo en supuestos tasados | 14 | El PDF archivado es el original; las copias dicen «Duplicado» si hace falta |
| L-10 | Facturación por el destinatario o por un tercero, con su serie | 5 | No aplica |
| L-11 | **Conservación** con acceso para la AEAT: 4 años (art. 66 LGT, prescripción) y **6 años** de libros y justificantes (art. 30 del Código de Comercio) | 19 a 23 | Facturas, PDF y registro **excluidos de la purga** (D-075 purga la auditoría a los 60 meses) y en la copia nocturna |
| L-12 | Factura simplificada (hasta 400 €) | 4 y 7 | No aplica a Audax |
| L-13 | **Integridad e inalterabilidad** (art. 29.2.j LGT, ya en vigor) | LGT | Facturas aprobadas inmutables; cambios solo por rectificativa o anulación; **registro encadenado con hash** desde el primer día (§6.2) |

### 2.4 Clientes de la UE y de fuera de la UE

- **Servicios a empresarios** (art. 69.Uno LIVA): se localizan donde está el cliente. A un cliente empresario de fuera de España **no se le repercute IVA español**. (a) [LIVA](https://www.boe.es/buscar/act.php?id=BOE-A-1992-28740)
- **Cliente empresario de la UE:** factura sin IVA, con su NIF-IVA y la mención «Inversión del sujeto pasivo»; se declara en el **modelo 349, clave S**; Audax tiene que estar en el **ROI** (modelo 036) y conviene comprobar el NIF-IVA del cliente en **VIES**. (b) [AEAT, prestaciones de servicios](https://sede.agenciatributaria.gob.es/Sede/iva/iva-operaciones-comercio-exterior/prestaciones-servicios.html)
- **Cliente de fuera de la UE:** no sujeto en España, sin 349.
- **IRPF:** una SL que factura servicios de diseño **no aplica retención** en sus facturas (la retención es para profesionales personas físicas). Sí retiene cuando paga a autónomos, pero eso es de compras. (b) [AEAT, retenciones](https://sede.agenciatributaria.gob.es/Sede/ayuda/manuales-videos-folletos/manuales-practicos/folleto-actividades-economicas/7-otras-obligaciones-fiscales-retenciones.html)

### 2.5 Qué exige la ley a un programa propio de facturación

| Cuándo | Qué |
|---|---|
| **Hoy (hasta el 31/12/2026)** | RD 1619/2012 entero (§2.3), IVA (y 349 y ROI si hay clientes de la UE) y art. 29.2.j LGT: no permitir alterar registros sin dejar rastro |
| **01/01/2027** (fecha del BOE vigente) | VeriFactu completo: registro con huella encadenada, registro de eventos o envío VERI\*FACTU, QR y leyenda, declaración responsable de Audax como productor. Pasa a octubre de 2028 **solo si se publica** el aplazamiento |
| **Octubre de 2028** | Factura electrónica estructurada (EN 16931, UBL) a clientes empresa en España y comunicación del pago a la AEAT |

**Diseño prudente** (y lo que dice la propia nota de Hacienda): construir desde ya el registro inalterable con huella encadenada, las series separadas, el registro de eventos, el QR y la exportación UBL. Así, sea cual sea la fecha final, no se rehace nada.

---

## 3. Encaje con Audax Proyectos (lo que ya hay)

| Pieza | Dónde | Qué aporta | Qué le falta |
|---|---|---|---|
| Clientes | `clients` (`name`, `tax_id`, `contact_*`, `default_hourly_rate`, `notes`) | El destinatario y su tarifa | Razón social, NIF-IVA, país, dirección fiscal, email y personas de facturación, forma y días de pago, IBAN, régimen e impuestos por defecto, idioma, moneda, descuento y serie por defecto (H-001 a H-008) |
| Proyectos | `projects.billing_type` (`hour_bank`, `fixed_price`, `time_and_materials`, `internal`), `fixed_price_amount`, `hourly_rate`, `budget_minutes` | Qué se factura y cómo | **El fee mensual no es un tipo.** ClickUp lo importó como `time_and_materials` con «Fee mensual de N h» en la descripción (D-135); la Weekly lo reconoce por el código FE o por esa frase (D-188). Falta el importe al mes y las horas al mes como datos |
| Bolsas | `hour_banks` (`total_minutes`, `price_amount`, `hourly_rate`, `invoice_reference`, `renewed_from_id`); consumo y exceso en `HourBankLedger` | La venta de la bolsa y su exceso | `invoice_reference` es texto (el código F de ClickUp, que parece el número de Holded): no enlaza con una factura |
| Horas | `time_entries` (`is_billable`, `hourly_rate_snapshot`, `hourly_cost_snapshot`, `status` borrador → enviada → aprobada → bloqueada, `overage_minutes`) | Horas reales con su tarifa y coste congelados al aprobar | Saber qué línea de factura cubre cada entrada |
| Bloqueo | `time_entry_locks` (cliente o proyecto, rango, `reference`), `TimeLockService` (solo admin, `lock-time`, D-034) | El «al facturar» ya existe y se audita | Se hace a mano; la referencia es texto |
| Valoración | `RevenueCalculator`, `Valuation`, `EntryValuation`, `RunningCents` (D-043, D-082, D-083) | Céntimos exactos y repartos que cuadran | Nada: propone los importes de las líneas |
| Horas para facturar | `BillingReport` en `/informes/facturacion` (D-045): por proyecto y bolsa, dentro y exceso, facturables, pendientes de aprobar, tarifa e ingreso | Es, casi tal cual, la pantalla «Facturar horas» de Holded (H-132) | El botón «Crear factura» |
| PDF | Gotenberg con la hoja de documentos de Audax y DM Sans (D-140) | El PDF de la factura y del presupuesto | Las plantillas |
| Portal | `/portal` (bolsas, proyectos, Gantt), ajustes por cliente (D-097) | Sitio para «Facturas y presupuestos» | Listado, descarga y aceptar |
| Permisos | `view-financials`, `lock-time`, `ClientPolicy::viewBilling` (admin o `view-financials`) | Base | Permisos de facturación y rol de la gestoría |
| Auditoría y retención | `activitylog`; retención de la auditoría de 60 meses (D-075) | Trazabilidad | Las facturas, su PDF y su registro se conservan **6 años como mínimo** y nunca se purgan |
| Identidad | `CompanyIdentity` (nombre y logo) | Cabecera | Datos fiscales del emisor (razón social, NIF, domicilio, Registro Mercantil, IBAN) |
| Navegación | Sección «Facturación» preparada y oculta (D-260, D-261, `NavSections`) | El hueco | Las entradas |
| Tareas programadas | `audax-scheduler.service` (`routes/console.php`) | Recurrentes, recordatorios y vencidas | Los comandos |
| Email | Laravel Mail; **el SMTP aún no está configurado** (D-030) | Envío | El SMTP real (bloquea el envío de facturas) |

---

### 3.1 Cómo factura Audax hoy en Holded (observado el 08/10/2026, solo lectura)
- **Numeración:**
  - una sola serie, «F» + año con 2 cifras + 4 cifras (F260194 es la 194 de 2026, unas 200 al año),
  - los borradores no tienen número y lo reciben al aprobarlos,
  - en 2026 no hay ninguna factura anulada y no he visto rectificativas.
- **Líneas:** concepto del catálogo de servicios más una descripción libre.
  - **Bolsas:** se facturan como `bolsadehoras` con **unidades = horas y precio = €/h**: 25 × 51 €, 100 × 60 € con un 15 % de descuento de línea, 30 × 42,50 € (trimestral).
  - **Por horas:** «Desarrollo» 8 × 70 € y «Diseño Producto UX/UI» 58 × 60 €.
  - **Fees:** 1 unidad × el importe del mes.
  - IVA del 21 % en todas.
- **Catálogo:**
  - **Auditorías:** MK y comunicación, UX y CRO, y Definición de producto digital.
  - **Fees:** MK y RRSS, y Producto digital.
  - **Trabajo:** Mantenimiento, bolsa de horas, Diseño de producto UX/UI, Desarrollo, Diseño gráfico e identidad, y SEO.
  - **A 0 € (repercutidos):** Herramienta e Inversión (gasto de medios).
  - El precio por defecto es de 60 €.
- **Tags:** indican el tipo de servicio (#fee, #bolsadehoras, #desarrollo…) y el cliente o lead (#pinturasmonto, #hrl_lead…), pero **nunca el proyecto**. El enlace con el proyecto se sugiere por cliente, servicio y fecha, y se confirma a mano (o se hace por el código F de la bolsa).
- **Recurrentes:**
  - hay 16 (unos 32.700 €), casi todas mensuales, más 1 anual y 2 trimestrales,
  - el día 29 generan una **factura en borrador** enlazada a la recurrente,
  - alguien la retoca (número de sprint, número de pedido del cliente) y la aprueba,
  - **un borrador no cuenta como facturado.**
- **Cobro:**
  - el vencimiento varía (el mismo día, +1, +7, +14 o +30 días),
  - los estados son Pagado, Pendiente, Vencido, Pago parcial y Anulado,
  - los cobros se concilian con el banco,
  - se ve si el cliente ha abierto la factura,
  - el envío por email puede ir a varias direcciones.
- **Contabilidad:** todo va a «Ventas de mercaderías», y cada cliente a su subcuenta 430.
- **Presupuestos:** no se usan en Holded (ninguno en 2026).

## 4. Cómo se generan las facturas desde la app

Todas acaban en un **borrador** de factura (sin número). Se revisa, se **Aprueba** y, al aprobar, se bloquean las horas que cubre. Son los flujos de Holded con los datos de Audax.

### 4.1 Bolsa vendida → factura
1. Al crear o **renovar** una bolsa con `price_amount`, la ficha de la bolsa muestra **«Facturar bolsa»** (y la bandeja «Pendiente de facturar» la lista hasta que tenga factura).
2. Crea un borrador como lo hace Audax hoy (§3.1): servicio «bolsadehoras», **unidades = horas de la bolsa y precio = €/h**, con descuento de línea si el precio de la bolsa es menor que horas × tarifa, y la descripción «Bolsa de horas N h {mes o trimestre}». Lleva el proyecto y la bolsa enlazados.
3. Al aprobar, la bolsa queda enlazada a la factura (`hour_banks.sales_document_id`); `invoice_reference` se conserva como histórico.
4. **Exceso:** cuando la bolsa tiene exceso (o al cerrarla o renovarla), **«Facturar exceso»**: líneas con las horas de exceso aprobadas y no facturadas, a su tarifa congelada (`EntryValuation`). Al aprobar, se bloquean esas entradas. Si la política es descontarlo de la renovación, no se factura y queda anotado (SPEC §8).

### 4.2 Fee mensual → factura recurrente
1. **Nuevo tipo de facturación `monthly_fee`** (P2): importe al mes, horas al mes y día de facturación. Los proyectos importados con «Fee mensual de N h» se convierten con una migración que se revisa a mano.
2. Al guardar el fee se crea su **factura recurrente** (H-057): mensual, desde la fecha de inicio, en **borrador** por defecto (H-058), con la descripción «Fee de [mes]» (H-060).
3. El día fijado, `billing:generate-recurring` crea el borrador y avisa a quien gestiona la facturación. **«Omitir este mes»** como en la API de Holded (H-061).
4. El fee no bloquea horas (no se factura por horas), pero el informe «vendido frente a real» compara las horas del mes con las horas del fee, con lo esperado por días laborables que ya calcula la Weekly (D-188). El exceso del fee se avisa; facturarlo es una decisión manual (P2).

### 4.3 Proyecto por horas → factura con las horas aprobadas del mes
Es el **«Factura → Registros horarios»** de Holded (H-132):
1. Desde el proyecto (pestaña **Facturación**), desde el cliente o desde la bandeja: **«Facturar horas»**.
2. *Opciones de facturación*: periodo (por defecto, el mes anterior), **«todas las aprobadas sin facturar»** o «sin facturar desde…», y **cómo mostrarlas**: una línea por tarea, por persona, por tipo de tarea, por proyecto o una por entrada.
3. **Revisar factura**: se ven las horas aprobadas y, aparte y en ámbar, las **pendientes de aprobar** (no entran, como en `BillingReport`). Los importes salen de `EntryValuation` al céntimo.
4. **Guardar** crea el borrador. **Aprobar** emite la factura y llama a `TimeLockService` con la factura como referencia: las entradas quedan bloqueadas y enlazadas a su línea. **Anular** la factura deshace ese bloqueo con el `unlock` de siempre; una **rectificativa** no lo deshace.
5. Una entrada solo puede estar en una factura emitida: es lo que impide facturar dos veces la misma hora.

### 4.4 Precio cerrado → presupuesto e hitos
1. **Presupuesto** (H-024) con sus líneas y, opcionalmente, horas estimadas por línea.
2. El cliente lo **acepta en el portal** (H-028, nombre, fecha e IP) o se marca como aceptado a mano.
3. **«Crear proyecto»** desde el presupuesto aceptado (novedad de Audax): proyecto `fixed_price` con `fixed_price_amount` = total sin IVA y `budget_minutes` = horas estimadas. O enlazarlo a uno que ya existe.
4. **Previsión de facturación** (H-027): hitos con fecha y porcentaje o importe (por ejemplo, 50 % al empezar y 50 % al entregar). Cada hito pasa a «Pendiente de facturar» en su fecha y se convierte con **Crear factura**. El presupuesto queda «facturado parcialmente» hasta el último.
5. No bloquea horas, porque no se factura por horas.

### 4.5 Bandeja «Pendiente de facturar» (nueva, propia de Audax)
Una sola pantalla con todo lo facturable, agrupado por cliente: horas aprobadas sin facturar de proyectos por horas, bolsas vendidas sin factura, excesos, fees del mes sin generar e hitos vencidos. Cada fila tiene su botón «Crear factura» y se pueden juntar varias de un mismo cliente en una sola factura (con un **título** por proyecto, H-070). Avisa de lo que pasa del día 15 del mes siguiente (L-07).

### 4.6 Informe «Vendido frente a real»
Por **unidad de venta** (bolsa, proyecto de precio cerrado, fee y mes, proyecto por horas) y agregable por cliente, tipo de facturación, gestor y mes. Usa los filtros, la caché y la exportación de los informes de la Fase 2.

| Columna | Bolsa | Precio cerrado | Fee mensual | Por horas |
|---|---|---|---|---|
| **Horas vendidas** | `total_minutes` | `budget_minutes` o las horas del presupuesto aceptado | horas al mes × meses | = horas facturadas |
| **Importe vendido** | `price_amount` | `fixed_price_amount` | importe al mes × meses | = facturado |
| **Horas reales** | consumo de `HourBankLedger` (dentro y exceso) | horas imputadas | horas del mes | horas facturables |
| **Facturado** | facturas aprobadas − rectificativas enlazadas | ídem | ídem | ídem |
| **Cobrado / pendiente** | cobros registrados | ídem | ídem | ídem |
| **Pendiente de facturar** | exceso aprobado sin facturar | ingreso devengado (`RevenueCalculator`, D-043) − facturado | meses sin factura | horas aprobadas sin facturar |

Indicadores: **desviación de horas** (reales − vendidas, en horas y %), **precio efectivo por hora** (importe vendido ÷ horas reales) frente a la tarifa de referencia y, con `view-financials`, **coste** (instantáneas de coste) y **margen**. Semáforo con los umbrales de la Weekly (riesgo desde el 85 %, bloqueado por encima del 100 %). Las horas pendientes de aprobar se muestran aparte, como en todos los informes.

**Primera versión sin emitir nada:** con la sincronización de solo lectura de Holded (§5), el informe se puede tener **antes** de que Audax emita facturas: lo facturado sale de Holded, enlazado por cliente (NIF) y por el código F.

---

## 5. Migración desde Holded

**Herramienta:** la **API v2** (`https://api.holded.com/api/v2/`, clave con permisos de solo lectura por ámbito, paginación por cursor, 120 peticiones por minuto en Estándar). La v1 sigue viva como respaldo. Nunca se borra nada en Holded: al cancelar la cuenta, **Holded borra los datos y no se recuperan** (H-141), así que todo se exporta antes.

| Qué | Cómo | Notas |
|---|---|---|
| **Contactos → clientes** | `GET /contacts`; se emparejan con los clientes de Audax (importados de ClickUp) **por NIF** y, si no, por nombre parecido; pantalla de revisión antes de aplicar (como la importación de la Weekly) | Se rellenan los datos fiscales, las preferencias (forma y días de pago, idioma, régimen) y las personas de contacto. Los que no casan se crean inactivos |
| **Servicios, impuestos y formas de pago** | `GET /services`, `/taxes`, formas de pago | Se mapean las *keys* (`s_iva_21` → IVA 21 %) |
| **Series** | `GET /numbering-series/invoice` y `/creditnote` (formato, último número y serie de devolución) | Se recrean con el mismo formato y el último número |
| **Facturas, rectificativas y presupuestos** | `GET /invoices`, rectificativas, presupuestos (con sus líneas) y **`GET /invoices/{id}/pdf`** de cada una | Entran como **«Importadas de Holded»**: solo lectura, con el **PDF original archivado** (es el que vale legalmente), fuera de la numeración y del registro encadenado (como la casilla «No enviar a Verifactu» de Holded, H-055) |
| **Cobros** | `GET /payments` y `payments_total` / `payments_pending` de cada factura | Para el pendiente de cobro y para el informe |
| **Recurrentes** | `GET /recurring-invoices` | Se recrean como recurrentes de Audax en pausa y se activan en el corte |
| **Enlace con proyectos y bolsas** | El **código F** de ClickUp (`hour_banks.invoice_reference` y «Factura: F…» en los proyectos) contra el número de factura de Holded; si no, por cliente y fecha | Sale como sugerencia para revisar |
| **Libros y contabilidad** | Exportación del libro de emitidas y, si la gestoría lo usa, el export A3 o Sage (H-117, H-118), antes de cancelar | Para la gestoría y la conservación de 6 años |

**Numeración al cambiar (P4):**
- **Fecha de corte:** el día 1 de un mes, mejor de un trimestre (cuadra con el 303). Holded deja de emitir el día anterior.
- **Opción recomendada:** **seguir la misma serie** (mismo formato, siguiente número): no hay hueco ni duplicado y la gestoría no nota el cambio. Las importadas quedan con su número y las nuevas siguen detrás.
- **Alternativa:** serie nueva con otro prefijo desde el 1 (más clara si el corte coincide con el 1 de enero).
- **Rectificar una factura de Holded después del corte:** se hace en Audax, con la rectificativa en la serie de rectificativas de Audax y referencia a la original importada.
- Holded no se cancela hasta que la gestoría haya cerrado el trimestre del corte con los datos de Audax.

---

## 6. Propuesta

### 6.1 Estrategia de emisión (P1)
| Opción | Qué es | Riesgo legal | Coste de Holded |
|---|---|---|---|
| **A · Audax prepara, Holded emite** (recomendada para empezar) | Todo el flujo se hace en Audax; al **Aprobar**, Audax crea y aprueba la factura en Holded por la API (`POST /invoices` + `/approve`), guarda el número y el PDF y bloquea las horas. Cuando se cumple la condición de corte, se pasa a B o C | Ninguno: emite un programa con VeriFactu | Se paga hasta el corte (y quizá baste un plan más bajo) |
| **B · Audax emite con el RD 1619/2012** | Emisión propia con registro encadenado, sin envío a la AEAT | **Alto desde el 01/01/2027 si el aplazamiento no sale en el BOE** (50.000 € por ejercicio) | Se cancela antes |
| **C · Audax emite con VeriFactu** | B más el envío VERI\*FACTU (certificado de Audax, XML de la Orden HAC/1177/2024, QR y leyenda) y la declaración responsable | Ninguno, pero Audax responde como productor | Se cancela antes, con más trabajo |

**Condición de corte (A → B o C):** el aplazamiento publicado en el BOE (entonces B basta hasta octubre de 2028) **o** la entrega VeriFactu terminada (C).

### 6.2 Modelo de datos orientativo
Importes en `decimal`, minutos enteros e instantes en UTC, como el resto de la app. Nombres en inglés.

- **`billing_profiles`** (1:1 con `clients`): `legal_name`, `trade_name`, `tax_id` (se mueve desde `clients`), `eu_vat_number`, `country_code`, `address`, `postal_code`, `city`, `province`, `tax_regime` (`general`, `intra_eu`, `export`, `exempt`, `not_subject`), `default_tax_id`, `payment_method_id`, `payment_days`, `payment_day`, `iban`, `sepa_mandate_ref`, `sepa_mandate_date`, `language`, `currency`, `default_discount`, `default_series_id`, `accounting_account`.
- **`billing_contacts`**: personas de facturación del cliente (`name`, `email`, `receives_copies`).
- **`company_billing_settings`** (ajuste): razón social, NIF, domicilio, Registro Mercantil, IBAN, textos legales y pie, idioma por defecto.
- **`taxes`** (`key`, `name`, `kind` iva/retención, `rate`, `legal_mention`), **`payment_methods`** (`name`, `document_text`, `iban`, `due_days`, `is_default`), **`services`** (`code`, `name`, `description`, `unit` hora/unidad/mes, `unit_price`, `tax_id`, `archived_at`).
- **`numbering_series`**: `document_type`, `name`, `format` (`F[YY]%%%%`), `year`, `last_number`, `refund_series_id`, `is_default`, `excluded_from_register` (importadas).
- **`sales_documents`** (un editor para todo, H-019): `type` (`estimate`, `proforma`, `invoice`, `credit_note`), `series_id`, `number` (nulo en borrador), `full_number`, `status` (`draft`, `issued`, `cancelled`; presupuesto: `pending`, `accepted`, `rejected`, `invoiced`, `partially_invoiced`), `payment_status` (`unpaid`, `partial`, `paid`, `overdue` derivado), `client_id`, **`client_snapshot` y `issuer_snapshot`** (JSON), `issue_date`, `operation_date`, `due_date`, `currency`, `exchange_rate`, `language`, `subtotal`, `discount_total`, `tax_total`, `total`, `body`, `internal_note`, `source_document_id` (conversión), `rectified_document_id`, `rectification_type` (`differences`, `substitution`), `rectification_reason`, `recurring_invoice_id`, `accepted_at`, `accepted_by_name`, `accepted_ip`, `issued_at`, `issued_by`, `pdf_path` (el PDF archivado al emitir), `external_source` y `external_id` (Holded), `sent_at`.
- **`sales_document_lines`**: `position`, `kind` (`service`, `title`, `text`, `supplied`), `service_id`, `description`, `quantity`, `unit`, `unit_price`, `discount_pct`, `tax_id`, `tax_rate` (copiado), `line_total`, `project_id`, `hour_bank_id`, `origin` (`manual`, `hours`, `hour_bank`, `overage`, `fee`, `milestone`), `minutes`.
- **`sales_document_line_time_entry`**: qué entradas cubre cada línea (única por entrada en facturas emitidas). `time_entry_locks` gana `sales_document_id`.
- **`sales_document_due_dates`** (varios vencimientos, H-075) y **`payments`** (`sales_document_id`, `date`, `amount` con signo, `method_id`, `note`, `created_by`).
- **`recurring_invoices`**: `client_id`, `project_id`, líneas plantilla, `periodicity`, `start_date`, `end_date`, `next_date`, `mode` (`draft`, `issue`), `auto_send`, `skipped_dates`.
- **`billing_milestones`**: `estimate_id`, `project_id`, `date`, `percentage` o `amount`, `sales_document_id`.
- **`invoice_records`** (registro de facturación, **solo inserción**): `sales_document_id`, `kind` (`issue`, `cancel`), `payload` (los datos del art. 10 del Reglamento), `hash`, `previous_hash`, `created_at`; y **`invoice_events`** (registro de eventos). Sin `UPDATE` ni `DELETE`: un disparador de PostgreSQL lo impide.
- **`projects`**: `billing_type` gana `monthly_fee`, con `monthly_fee_amount`, `monthly_minutes` y `billing_day`. `hour_banks` gana `sales_document_id`.
- **Reutiliza:** `attachments` (adjuntos), `activitylog` (historial), notificaciones, Gotenberg y portal.

### 6.3 Pantallas y flujos (calcados de Holded, con el aspecto de Audax)
| Pantalla | Ruta | Qué hace | Holded |
|---|---|---|---|
| Facturas | `/facturacion/facturas` | Listado con pestañas **Facturas** y **Recurrentes**; filtros por estado, cliente, serie, fecha y vencidas; sumatorio; ⋮ Editar, Duplicar, Anular, Descargar; en bloque **Aprobar**, **Descargar PDF** y exportar | H-036, H-057 |
| Nueva factura | `/facturacion/facturas/nueva` | Editor en tres zonas y panel derecho de **Opciones** (serie, idioma, moneda, plantilla de email); botones **Vista previa**, **Guardar como borrador** y **Aprobar**; el desplegable ofrece **Rectificativa** | H-019 a H-022 |
| Detalle | `/facturacion/facturas/{id}` | PDF a la izquierda y panel derecho: estado, **Pagos** (Añadir pago), **Enviar**, **Compartir**, **Convertir** (rectificativa, recurrente), adjuntos, **Relación entre documentos**, **Historial**; candado junto al número; **Editar** solo campos no fiscales | H-035, H-041, H-034 |
| Presupuestos | `/facturacion/presupuestos` | Listado y editor; **Convertir** (factura, proforma, proyecto); **Previsión de facturación**; estado de aceptación | H-024 a H-028 |
| Pendiente de facturar | `/facturacion/pendiente` | La bandeja del §4.5 | Audax (H-132 ampliado) |
| Cobros | `/facturacion/cobros` | Pendiente de cobro y vencidas, registrar cobros, recordatorios enviados | H-076, H-111, H-092 |
| Informes | `/facturacion/informes` | **Ventas** (por cliente, servicio, proyecto y tipo), **Libro de facturas emitidas**, **Vendido frente a real** | H-106, H-108 |
| Ajustes de facturación | `/facturacion/ajustes` | Datos fiscales del emisor, **Series**, **Impuestos**, **Formas de pago**, **Servicios**, **Plantillas de email**, **Recordatorios** y textos legales | H-050, H-063, H-080, H-090, H-092, H-094 |
| Ficha de cliente | `/clientes/{id}`, pestaña **Facturación** | Datos fiscales y preferencias, personas en copia, documentos y resumen anual; botón **+** para crear un documento | H-001 a H-009 |
| Ficha de proyecto | pestaña **Facturación** | Documentos vinculados, vendido frente a real, **Facturar horas** y **Presupuesto** | H-129, H-130, H-132 |
| Bolsa | ficha de la bolsa | **Facturar bolsa**, **Facturar exceso** y enlace a la factura | Audax |
| Portal | `/portal/facturas` | Facturas emitidas y presupuestos; descargar y **Aceptar presupuesto**; IBAN; enlace firmado de 48 h para quien no tiene usuario | H-097 a H-100 |

### 6.4 Permisos
| Quién | Qué puede |
|---|---|
| **`manage-billing`** (nuevo; admin por defecto) | Crear, aprobar, anular y rectificar; cobros; recurrentes; ajustes de facturación; bloquear horas al facturar |
| **`view-billing`** (nuevo) | Ver facturas, presupuestos, cobros, libro de emitidas e informes de facturación, sin costes ni márgenes |
| **`view-financials`** (existe) | Además, tarifas, costes y margen en «vendido frente a real» |
| **Gestor de proyecto** | Prepara **borradores** de presupuestos y facturas de sus proyectos (como el «Agente de ventas» de Holded, H-144); no aprueba |
| **Gestoría** (rol nuevo, externo, como el colaborador de D-134) | Solo la sección Facturación en lectura y las exportaciones; 2FA obligatorio; sin proyectos, horas, chat ni personas; todo en la auditoría (H-115) |
| **Cliente (portal)** | Sus facturas emitidas y sus presupuestos; aceptar presupuestos |

### 6.5 Entregas, por prioridad
| Entrega | Contenido | Por qué en este orden |
|---|---|---|
| **F0 · Decisiones** | Respuestas a P1-P8, revisión de §2 con la gestoría y decisión que cambia el SPEC §18 | Sin esto no se elige la estrategia |
| **F1 · Lectura de Holded y vendido frente a real** | Ficha fiscal del cliente, datos del emisor, tipo `monthly_fee`, sincronización **de solo lectura** con la API v2 (contactos ↔ clientes, facturas, rectificativas, cobros y PDF), enlace por el código F e informe **vendido frente a real** | Da el objetivo de negocio ya, sin riesgo legal ni cambiar cómo se factura |
| **F2 · Catálogo y ajustes** | Servicios, impuestos, formas de pago, series (espejo de Holded), textos legales, plantilla PDF, rol Gestoría y permisos | Base de todo lo demás |
| **F3 · Presupuestos** | Editor, PDF, envío, portal con aceptación, previsión por hitos, convertir a proyecto o a factura | Es lo que más usa quien vende y no toca la numeración fiscal |
| **F4 · Pendiente de facturar y emisión vía Holded** | Bandeja, «Facturar horas», bolsas, excesos, fees e hitos → borrador; **Aprobar** emite en Holded por la API, guarda número y PDF y bloquea las horas | La plantilla ya trabaja en Audax; Holded solo emite |
| **F5 · Emisión propia** | Numeración, aprobar y bloquear, **registro encadenado**, PDF archivado, anular, rectificativas, cobros, vencimientos, recurrentes, envío con plantillas, recordatorios, portal, libro de emitidas, exportaciones para la gestoría | Se construye detrás de un interruptor; no se enciende hasta la condición de corte |
| **F6 · VeriFactu** (si a la fecha del corte sigue siendo exigible) | Registros de alta y anulación (XML), envío VERI\*FACTU con el certificado de Audax, QR y leyenda, declaración responsable | Solo si el BOE no aplaza |
| **F7 · Corte y baja de Holded** | Migración histórica completa con PDF, series, recurrentes, prueba en paralelo de un mes, cierre del trimestre con la gestoría y cancelación | Al final, con todo comprobado |
| **F8 · Factura electrónica B2B** | UBL EN 16931, envío a la solución pública de la AEAT (o a una plataforma), estados de aceptación y pago; recepción si toca desde octubre de 2027 | Antes de octubre de 2028 |
| **Después (P6)** | Conciliación bancaria (extracto Norma 43 o CSV), remesas SEPA, multimoneda y compras imputadas a proyectos | Útil, no imprescindible |

### 6.6 Riesgos
| Riesgo | Mitigación |
|---|---|
| El aplazamiento no se publica antes del 01/01/2027 (elecciones del 29/11) | Opción A: Holded emite hasta tener VeriFactu (F6) o hasta ver el BOE |
| Audax pasa a ser productor del software y responde de que cumple | Registro encadenado desde F5, tests de la cadena, declaración responsable revisada por la gestoría |
| Números duplicados o huecos en el corte | Corte el día 1, Holded sin emitir desde la víspera, misma serie, comprobación de la secuencia en la importación |
| Pérdida del histórico al cancelar Holded | Importar todos los PDF y el libro de emitidas antes; Holded no se cancela hasta cerrar el trimestre |
| La gestoría trabaja dentro de Holded (asientos, 303) | P5: exportación en el formato de su programa y rol de solo lectura; si lo necesita, mantener un plan bajo de Holded solo para contabilidad |
| Facturar dos veces la misma hora | Una entrada, una línea de factura emitida (restricción única) y bloqueo al aprobar |
| Cambios en la ficha del cliente alteran facturas pasadas | Datos del cliente y del emisor copiados en la factura al emitir |
| Purga de datos | Facturas, PDF y registro fuera de la retención de D-075; 6 años como mínimo y en la copia nocturna |
| SMTP sin configurar (D-030) | Sin SMTP no hay envío ni recordatorios: va en la puesta en marcha |
| Céntimos que no cuadran | Totales por línea con `Money`/`Cents` y una sola redondeada por impuesto; tests con los casos de `tests/fixtures` |

---

## 7. Preguntas para el propietario

**P1 · ¿Quién emite las facturas mientras VeriFactu no esté claro?**
- **A (recomendada):** Audax prepara y Holded emite por la API hasta que salga el aplazamiento en el BOE o Audax tenga VeriFactu; después, Audax emite y se cancela Holded.
- B: Audax emite desde el primer día solo con el RD 1619/2012 (riesgo de 50.000 € por ejercicio si el 01/01/2027 no hay aplazamiento publicado).
- C: esperar a tener VeriFactu completo antes de emitir desde Audax.

**P2 · Fees mensuales: ¿cómo se venden?**
- **A (recomendada):** importe fijo al mes con N horas; el exceso se avisa y facturarlo lo decide el gestor (o se compensa el mes siguiente). Nuevo tipo «Fee mensual».
- B: el exceso se factura siempre a tarifa.
- C: el fee es una bolsa mensual (se gestiona como bolsa que se renueva cada mes).

**P3 · ¿Usáis las facturas recurrentes de Holded y cómo?**
- **A (recomendada):** sí, para los fees; que se creen en **borrador** y alguien las apruebe.
- B: que se aprueben y se envíen solas.
- C: no las usamos; las hacemos a mano.

**P4 · Series de numeración: ¿cuántas tenéis y qué formato?**
- ¿Es `F[YY]%%%%` (F260170 = factura 170 de 2026, el código F que hay en ClickUp)? ¿Hay serie aparte de rectificativas? ¿Se reinicia cada año?
- **Recomendado:** seguir la misma serie en Audax a partir del corte, con la de rectificativas separada.

**P5 · ¿Cómo trabaja la gestoría?**
- A: entra en Holded y lleva allí la contabilidad y los impuestos (asientos, 303, 349).
- B: se lleva exportaciones (libro de emitidas, PDF) a su programa (¿A3, Sage, otro?).
- **Recomendado:** B, con un usuario «Gestoría» de solo lectura en Audax y la exportación en el formato de su programa. Los gastos y compras, en la gestoría. Si hoy es A, hay que hablar con ella antes de cancelar Holded.

**P6 · Cobros: ¿conciliáis el banco o hacéis remesas en Holded?**
- **A (recomendada):** cobros apuntados a mano (o importados de Holded mientras dure), pendiente de cobro y recordatorios; la conciliación, después.
- B: necesitamos conciliación bancaria desde el principio.
- C: además, domiciliamos fees con remesas SEPA.

**P7 · Presupuestos: ¿los hacéis en Holded y el cliente los acepta en el portal? ¿Facturáis los precios cerrados por hitos?**
- **Recomendado:** presupuestos en Audax, aceptación en el portal y «Crear proyecto» desde el presupuesto aceptado, con hitos (por ejemplo, 50 % y 50 %).

**P8 · Tres datos para cerrar la parte legal:** ¿Audax Studio es una SL (Impuesto sobre Sociedades)? ¿Su volumen de operaciones del año pasado pasó de 6 M€ (SII) o de 8 M€ (factura electrónica en octubre de 2027)? ¿Está en el ROI para facturar a clientes de la UE sin IVA y factura en otras monedas?

---

## Fuentes principales
- Ley 11/2021 (LGT, arts. 29.2.j y 201 bis): https://www.boe.es/buscar/act.php?id=BOE-A-2021-11473
- RD 1007/2023 (consolidado): https://www.boe.es/buscar/act.php?id=BOE-A-2023-24840
- Orden HAC/1177/2024: https://www.boe.es/diario_boe/txt.php?id=BOE-A-2024-22138
- RD 254/2025: https://www.boe.es/buscar/doc.php?id=BOE-A-2025-6600
- RDL 15/2025 y su convalidación: https://www.boe.es/buscar/doc.php?id=BOE-A-2025-24446 y https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-25695
- Nota informativa de Hacienda del 05/10/2026: https://www.hacienda.gob.es/sgt/gabsehacienda/nota-informativa-verifactu.pdf
- Sumarios del BOE del 05/10 y del 06/10/2026: https://www.boe.es/boe/dias/2026/10/05/ y https://www.boe.es/boe/dias/2026/10/06/
- AEAT, VeriFactu, nota de plazos y FAQ: https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu.html
- RD 238/2026 (factura electrónica B2B): https://www.boe.es/buscar/act.php?id=BOE-A-2026-7295
- Orden HAC/1028/2026: https://www.boe.es/buscar/doc.php?id=BOE-A-2026-20587
- RD 1619/2012 (consolidado): https://www.boe.es/buscar/act.php?id=BOE-A-2012-14696
- Ley 37/1992 del IVA: https://www.boe.es/buscar/act.php?id=BOE-A-1992-28740
- Prensa (no oficial) sobre el anuncio: https://www.infobae.com/espana/agencias/2026/10/05/hacienda-retrasa-verifactu-hasta-2028-para-alinearlo-con-la-factura-electronica/
- Holded: ver `docs/HOLDED-INVENTARIO.md`.
