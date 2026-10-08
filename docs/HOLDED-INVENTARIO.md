# Holded → Audax Proyectos: inventario funcional de facturación y ventas

_Investigación del 06/10/2026, en solo lectura, sobre fuentes públicas: centro de ayuda (`help.holded.com/es`), web y precios de Holded, y su API (`holded.com/es/desarrolladores`). No se ha entrado en la cuenta de Audax. La propuesta de construcción está en `docs/PLAN-FASE-12.md`._

> **Tres avisos antes de empezar**
> 1. **La API de Holded cambió en junio de 2026.** `developers.holded.com` redirige a `https://www.holded.com/es/desarrolladores`. Hay una **API v2** (`https://api.holded.com/api/v2/…`, `Authorization: Bearer`, paginación por cursor y unos 360 endpoints) y la **v1** (`/api/invoicing/v1/…`) queda obsoleta, pero sigue funcionando. La migración (PLAN-FASE-12 §5) usa la v2.
> 2. **En Holded, un borrador no consume número.** Tampoco genera asiento ni sale en informes, impuestos o portal. En España el modo borrador es obligatorio y, al **Aprobar**, la factura queda bloqueada (candado verde). Es el flujo central que hay que imitar.
> 3. **El código F de ClickUp parece el número de factura de Holded.** Las listas importadas llevan `F260170` (D-135), que encaja con una serie de Holded `F[YY]%%%%` (año 26, factura 170). Está en `hour_banks.invoice_reference` y en la descripción de los proyectos («Factura: F…»): servirá para enlazar el histórico. **Hay que confirmarlo** (pregunta P4 del plan).

**Leyenda de la columna «Audax»:**
- **E** (esencial): sin esto la plantilla no puede dejar Holded.
- **U** (útil): mejora el día a día; puede llegar en una entrega posterior.
- **S** (sobra): no aplica a una agencia de servicios o lo resuelve la gestoría.

**Roles de Holded:** Propietario, Administrador, Finanzas, Ventas, Agente de ventas (solo lo suyo), Asesoría y Cliente (portal).

**Fuentes:** `H<ID>` es `https://help.holded.com/es/articles/<ID>` (tabla completa al final); `API v2` y `API v1` son la referencia de `holded.com/es/desarrolladores`; `PRE` es `holded.com/es/precios`. Lo marcado **[SIN VERIFICAR]** no se pudo confirmar en la fuente.

---

## A. Contactos

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-001 | Crear contacto | Contactos → **Nuevo contacto** (o la tecla N). Cuatro pestañas: *Campos básicos*, *Información cuenta bancaria*, *Preferencias* e *Información contable*; se guarda con **Crear**. Se elige **Persona** o **Empresa**; solo la empresa tiene *Nombre comercial* e *identificación VAT*. Para que la factura sea válida: nombre, NIF y dirección fiscal completa (dirección, población, CP, provincia y país) | Administración, Ventas | **E** | H6921812 |
| H-002 | Tipo de contacto | Sin especificar, Cliente, Proveedor, Oportunidad (lead), Deudor y Acreedor. Es informativo, pero decide la cuenta contable. API: `type` = `client`, `debtor`, `supplier`, `creditor` o `lead` | Administración | U (solo clientes) | H6911739, API v2 |
| H-003 | Emails y teléfonos | Email, teléfono, móvil y web. Admite varios emails separados por coma; los documentos van al principal | Administración | **E** | H6921812 |
| H-004 | Asignar contacto a un comercial | Campo *Asignar usuarios*, también en bloque (**Asignar → Asignar usuarios → Aplicar**). El «Agente de ventas» solo ve sus contactos y documentos. Desde el plan Estándar | Administración | U (en Audax ya existe el responsable del cliente, D-232) | H6921812, H8851048 |
| H-005 | Banco y mandato SEPA | Banco, IBAN (imprescindible para remesas), SWIFT, *Ref. mandato* y *Fecha mandato* (caduca a los 36 meses del último cobro). Varios bancos con *Banco predeterminado*. API: `iban`, `swift`, `sepa_ref`, `sepa_date` | Finanzas | U (solo si hay domiciliaciones, P6) | H6921812, API v2 |
| H-006 | Preferencias por defecto | Idioma, moneda, cuentas de venta y compra, referencia interna, **forma de pago**, **vencimiento** y *día fijo de pago*, **descuento** y **tarifa**, *Mostrar nombre comercial* y *Mostrar país* en la factura, **serie de numeración por defecto** y campos de factura-e. Se aplican al crear un documento (prioridad contacto > producto > empresa). API v2: `defaults{due_days, payment_day, payment_method, discount, language, currency, sales_tax[], numbering_series{}}` | Administración | **E** | H6921812, H6834950 |
| H-007 | Régimen fiscal e información contable | Impuestos de venta y de compra por defecto, cuenta contable del cliente (4300…), desplegable **Operación**: `general`, `intra`, `impexp`, `nosujeto`, `exento`, `receq`; casilla *Acumula en el 347* | Finanzas, Asesoría | **E** (régimen e impuestos); U (cuenta contable) | H6921812, H7325554 |
| H-008 | Personas de contacto | **Más → Personas de contacto → + Añadir persona**. Con **«Enviar copias de mensajes»**, la persona recibe en copia documentos y recordatorios | Administración | **E** (el contacto de facturación del cliente casi nunca es el del proyecto) | H6922239, H6836216 |
| H-009 | Ficha 360 del contacto | Resumen anual de ventas y compras, pestañas con sus documentos, informes de productos, actividades y oportunidades del CRM, **Archivos** y **Notas**; botón **+** para crear un documento ya con el contacto | Administración, Ventas | **E** (pestaña «Facturación» en la ficha de cliente de Audax) | H6922239 |
| H-010 | Direcciones de envío | Varias direcciones (`shippingAddresses[]` en la API v1) y elección de cómo se muestra la dirección fiscal en el documento. **[SIN VERIFICAR: la pantalla]** | Administración | U | API v1, H7325554 |
| H-011 | Campos personalizados de contacto | Configuración → CRM → Campos personalizados → **+ Crear** (texto, número, fecha, selección…), filtrables, importables y exportables | Administración | S | H6927107 |
| H-012 | Etiquetas (tags) | Configuración → Gestionar tags, o al vuelo con Intro. En minúsculas, sin tildes. Sirven en contactos, documentos y servicios; filtro **+ Filtro → Tags** | Todos | U | H6983650 |
| H-013 | Grupos, archivar y buscar | Grupos de contactos, archivado en bloque y búsqueda por nombre (API v2 y v1 `groupId`) | Administración | S (en Audax, activo e inactivo, D-037) | API v2 |
| H-014 | Importar contactos | Contactos → ⋮ → **Importar** → plantilla → **Subir archivo**. Obligatorios *Nombre fiscal* y *NIF*; opcionales dirección, IBAN, mandato, tags, régimen, impuestos por *key* (`s_iva_21`), moneda, idioma, forma de pago, vencimiento, tarifa y descuento | Administración | U (la migración usa la API) | H7325554 |

## B. Servicios, productos y tarifas

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-015 | Servicios | Ventas → Servicios → **Nuevo servicio**: nombre (el concepto del documento), código, descripción, tag, cuenta de ventas, precio y coste por unidad, **tiempo**, impuestos (hasta tres: IVA, retención y recargo). Archivar y desarchivar. API `POST /api/v2/services` | Administración | **E** (catálogo: «Hora de diseño UX», «Bolsa de horas», «Fee mensual»…) | H6896098, API v2 |
| H-016 | Productos con stock | Productos con variantes, lotes y números de serie (gema Inventario) | — | S | H6838658 |
| H-017 | Tarifas o listas de precios | **+Nueva tarifa** por producto y como tarifa por defecto del contacto; en el presupuesto, *Opciones → Tarifa* (gema Inventario) | Administración | S (Audax ya tiene tarifas por bolsa, proyecto, cliente y persona: `RateResolver`) | H6865131, H6987424 |
| H-018 | Coste privado en la línea | En la línea se puede ver el coste y calcular el precio de venta; el agente de ventas no lo ve | Administración | U (el coste sale de las horas: `hourly_cost_snapshot`) | H6834950, H8851048 |

## C. Documentos de venta y conversiones

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-019 | Editor común de documentos | Factura, ticket, presupuesto, proforma y rectificativa comparten editor en tres zonas: datos fiscales; conceptos, precios e impuestos; pagos y categorización (panel derecho) | Administración, Ventas | **E** | H6834950 |
| H-020 | Crear factura | Ventas → Facturas → **Nueva factura** (o N). El desplegable del botón ofrece *Venta rectificativa* y *Ticket de venta*. Botones al pie: **Vista previa**, **Editar diseño**, **Guardar como borrador** y **Aprobar** | Administración | **E** | H6834950 |
| H-021 | Menú «Opciones» del documento | Numeración (otra serie o número manual), idioma, moneda (con cambio editable), plantilla de email, plantilla de diseño y paneles de Verifactu, factura-e y SII | Administración | **E** (serie, idioma, plantilla); U (moneda) | H6834950 |
| H-022 | Categorización | Cuenta contable (o por concepto), etiquetas, **Asignar usuarios**, **Proyecto** y descripción interna; editable después desde el panel derecho | Administración | **E** (el proyecto y la bolsa son la base de «vendido frente a real») | H6834950, H6877413 |
| H-023 | Ticket de venta (simplificada) | Mismo editor; con Verifactu el contacto no es obligatorio | — | S (Audax factura a empresas) | H11406991 |
| H-024 | Presupuestos | Ventas → Presupuestos → **+**. Opciones propias: tarifa, *Impuestos incluidos*, *Mostrar total* | Dirección, cuentas | **E** | H6987424 |
| H-025 | Convertir presupuesto | Botón **Convertir** → Factura, Ticket, Proforma, Pedido de venta, Albarán o Pedido de compra | Administración | **E** (a factura y, en Audax, también a proyecto) | H6987424 |
| H-026 | Pagos a cuenta (anticipos) | En presupuesto, proforma o pedido: *Pagos a cuenta* → **Añadir pago**; al convertir, la factura sale pagada | Finanzas | U | H6895939 |
| H-027 | Previsión de facturación (hitos) | En el presupuesto: **Añadir previsión** (fecha, vencimiento, descripción, porcentaje o importe) y, en cada previsión, **Crear factura**. El presupuesto pasa a «facturado parcialmente». Desde Estándar. API `POST /api/v2/treasury/cashflow/invoicing-forecasts` | Administración | **E** (precio cerrado: 50 % al empezar, 50 % al entregar) | H6895939, API v2 |
| H-028 | Aceptación del presupuesto | El cliente abre el presupuesto en el portal y pulsa **Aceptar presupuesto**. API `POST /api/v2/estimates/{id}/accept` (`name`, `comment`) y rechazar. **[SIN VERIFICAR: rechazar desde el portal]** | Cliente | **E** | H9382835, API v2 |
| H-029 | Firma digital | Gema: el cliente firma presupuestos, proformas, pedidos o albaranes desde el portal (varios firmantes). Estados: Pendiente, Firmado, Expirado, Cancelado, No requiere. Evidencias: email, IP, hash y fecha (no es firma cualificada). Cupo mensual por plan | Cliente | U (aceptar con nombre, IP y fecha cubre lo esencial) | H10900972 |
| H-030 | Proformas | Sin efecto contable; botón **Convertir** | Administración | U (útil para cobrar por adelantado sin emitir factura) | H6987492 |
| H-031 | Pedido de venta y albarán | Gema Inventario. Albarán valorado, peso, etc. | — | S | H6881603, H6908409 |
| H-032 | Agrupar albaranes en una factura | Marcar albaranes del mismo contacto → **Convertir** → Factura | — | S (el equivalente en Audax es facturar varias bolsas o proyectos de un cliente en una factura) | H6908433 |
| H-033 | Convertir factura | **Convertir** → albarán, **venta rectificativa** o **factura recurrente** | Administración | **E** (rectificativa y recurrente) | H6877413 |
| H-034 | Árbol de documentos | **«Ver relación entre documentos»**: árbol con tipo, número, estado, total y % facturado | Administración | U | H16201260 |
| H-035 | Acciones del detalle | **Enviar**, **Compartir** (enlace al portal), **Subir archivo**, ver asiento, pestaña **Mensajes** (nota interna o mensaje al cliente) y pestaña **Historial** | Administración | **E** (enviar, compartir, adjuntos, historial); U (mensajes) | H6877413, H7206561 |
| H-036 | Acciones del listado | ⋮ → Editar, **Duplicar**, Eliminar o Anular, Descargar. En bloque: **Aprobar** y **Descargar PDF** (más de 50, por email). Exportar a Excel, PDF, Google Sheets o *por ítem*. Sumatorio del listado (hasta 1.000 filas) y filtro «No anuladas» | Administración | **E** | H6877413, H8727572 |

## D. Estados, aprobación, bloqueo, anulación y rectificación

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-037 | Borrador | Sin número, sin asiento, fuera de informes, impuestos y portal. Lo importado entra siempre como borrador; por API, `approveDoc` | Administración | **E** | H6887128 |
| H-038 | Borrador obligatorio | Obligatorio en España (salvo SII). Con Verifactu o TicketBAI no se puede desactivar | — | **E** | H6887128, H11508249 |
| H-039 | Aprobar (emitir) | Al guardar, con **Aprobar** en el panel o en bloque. Asigna el número de la serie. Conserva una fecha pasada, pero **avisa con error si es anterior a facturas aprobadas con número mayor**. API `POST /api/v2/invoices/{id}/approve` | Administración | **E** | H6887128, API v2 |
| H-040 | Bloquear aprobados | Casilla *Bloquear documentos de venta aprobados* (activa por defecto): candado en el botón y **candado verde** junto al número | — | **E** (sin casilla: siempre bloqueado) | H6887128 |
| H-041 | Editar una aprobada (campos no fiscales) | **Editar** permite cambiar concepto, descripción, texto adicional, forma de pago, cuenta, etiquetas, nota interna, usuarios y proyecto. Bloqueados: contacto, número, fechas, cantidades, precios, impuestos, total, serie, idioma y moneda. Regenera el PDF y queda en el registro de actividad | Administración | **E** | H14686589 |
| H-042 | Eliminar y anular | *Eliminar* solo borradores (papelera de 10 a 30 días). *Anular*: la factura queda «anulada» en el listado, el número no se reutiliza, sigue en el libro de emitidas y desaparece del portal. Criterio de Holded: si no se ha enviado, anular; si se envió, cobró o declaró, rectificar. API `POST /api/v2/invoices/{id}/cancel` (422 si está cobrada) | Administración | **E** | H6877413, H6887128 |
| H-043 | Rectificativa independiente | Nueva factura → **Venta rectificativa**; el importe se escribe en positivo y Holded lo pasa a negativo. Si se devolvió dinero, **Añadir pago** negativo | Administración | U | H6895315 |
| H-044 | Rectificativa desde la original | **Convertir → Venta rectificativa**: queda relacionada y reduce el pendiente. Parcial: una línea con la diferencia | Administración | **E** | H6895315 |
| H-045 | Relacionar rectificativa a posteriori | En la original: **Añadir pago → «Relacionar con un pago existente»** → la rectificativa → **Confirmar** | Administración | U | H6895315, H6984864 |
| H-046 | Rectificación por diferencias o por sustitución | *Opciones → Verifactu → Tipo de rectificación*. Sustitución pide base, cuota de IVA y de recargo rectificadas; ambas piden serie, número y fecha de la original; **Verificar** y **Aprobar** | Administración | **E** (tipo y motivo; RD 1619/2012, art. 15) | H11508100 |
| H-047 | Estados | API v2: `status` = `pending`, `partial`, `completed`, `cancelled`, `failed` (filtros extra `overdue` y `outstanding`) y `approval_status` = `draft` o `approved`. Pagos `no_paid`, `paid`, `partial_paid`. Presupuesto: Pendiente, Aceptado, Completado. **[SIN VERIFICAR: las etiquetas literales del listado]** | Todos | **E** | API v2, API v1, H16201260 |
| H-048 | Fases (pipeline) | Etapas personalizables por tipo de documento (`PUT /api/v2/invoices/{id}/pipeline`) | Administración | S | API v2 |
| H-049 | Fecha de operación | Campo *Fecha de operación* distinto de la de expedición; se usa en modelos y Verifactu. API `accounting_date` | Administración | **E** (RD 1619/2012, art. 6.1.f) | H6834950, H6838907 |

## E. Series y numeración

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-050 | Series por tipo de documento | Configuración → Facturación → Preferencias → *Formato de documentos* → tipo → **Añadir línea**: nombre, **formato** (`[YY]` o `[YYYY]` y un `%` por dígito: `F[YY]%%%%` → F260001), **último número** y **Devolución** (la serie de rectificativas vinculada) | Administración | **E** | H6878171 |
| H-051 | Cambiar el siguiente número | Se edita *Último núm.* (149 para que salga 150) | Administración | **E** (solo para el arranque, auditado) | H6878171, H7854006 |
| H-052 | Reinicio anual | Manual: tras la última del año, último = 0; con la primera del año, aviso con **Reiniciar** | Administración | **E** (automático por año si el formato lleva `[YY]`) | H8727471 |
| H-053 | Número manual | *Opciones → Numeración*; aviso de duplicado futuro si sigue el formato de una serie activa | Administración | S (solo en la importación histórica) | H6834950, API v2 |
| H-054 | Serie por contacto | *Asignar líneas de numeración predeterminadas* en el contacto | Administración | U | H6921812 |
| H-055 | Serie excluida de Verifactu | Casilla *No enviar a Verifactu* (autofacturas o registros de otro programa). API `verifactu_excluded` | Administración | U (series de facturas importadas) | H6878171, API v2 |
| H-056 | API de series | `GET/POST /api/v2/numbering-series/{type}` con `name`, `format`, `last`, `refund_numbering_series_id`, `verifactu_excluded` | — | **E** (migración) | API v2 |

## F. Facturas recurrentes

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-057 | Crear recurrente | Ventas → Facturas → pestaña **Facturas recurrentes** → **+**: *Intervalo*, *Inicio*, *Final* y los interruptores **Crear** y **Enviar automáticamente** | Administración | **E** (fees mensuales) | H6895111 |
| H-058 | Modo de generación | *Aprobar automáticamente*, *Crear como borradores* o ninguno (aprobación manual). **[La ayuda se contradice en un artículo]** | Administración | **E** (por defecto, borrador) | H6895111, H6887128 |
| H-059 | Gestión de recurrentes | Panel con automatizaciones, historial y **calendario**; **Ver pendientes** → *Convertir a borrador* o *Convertir y aprobar*; **Convertir → factura recurrente** desde una normal | Administración | **E** | H6895111 |
| H-060 | Palabras dinámicas | `[currentmonthname]`, `[previousmonth]`, `[nextyear]`… en la descripción | Administración | **E** («Fee de [mes]») | H6893888 |
| H-061 | API de recurrentes | `POST /api/v2/recurring-invoices` (`periodicity` d, w, bw, m, bm, q, ba, a; `convert_automatically`, `due_days`, `end_date`, `items`, `number_line_id`) y «Omitir una ocurrencia». Cupo por plan: 0 a 1.000 | — | **E** (migración y «Omitir este mes») | API v2, PRE |

## G. Impuestos

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-062 | Catálogo de impuestos | IVA 21, 10, 5, 4, 2 y 0 % (exportación, intracomunitario), recargo de equivalencia, retenciones IRPF 19, 15 y 7 %, inversión del sujeto pasivo, exento y no sujeto. Cada uno con una *key* (`s_iva_21`, `s_ret_15`…). Por línea, uno de cada grupo | Administración | **E** (IVA 21, 0 % intracomunitario o exportación de servicios, no sujeto y exento); S (recargo) | H6923165, H7325554 |
| H-063 | Crear impuesto | Configuración → Facturación → Impuestos → **Nuevo impuesto** (nombre, key, ámbito, porcentaje o grupo, cuentas) | Administración | U | H6984324 |
| H-064 | IVA por país (OSS) | Un impuesto por país para ventas a consumidores de la UE | — | S | H6984520 |
| H-065 | Impuestos por defecto | En la empresa, el contacto y el servicio | Administración | **E** | H6877971, H6921812 |
| H-066 | Intracomunitarias y extranjero | Régimen `intra` o `impexp` en el contacto, IVA 0 % y casillas del 303 y del 349. **[SIN VERIFICAR: comprobación del NIF-IVA en VIES]** | Administración | **E** (con la mención «Inversión del sujeto pasivo») | H6924889, H11651288 |
| H-067 | Suplidos | Línea fuera de la base imponible (cuenta 555) desde *Añadir línea* | Administración | U (gastos pagados en nombre del cliente) | H6834950 |
| H-068 | Tablas de IVA y calendario fiscal | Soportado, repercutido y saldo trimestral; resumen por key; calendario | Finanzas | S (gestoría) | H6901010, H6923150 |
| H-069 | Modelos tributarios | 303, 390, 111, 115, 347, 349… con **Presentar en AEAT** o descarga `.txt` | Finanzas, Asesoría | S (los presenta la gestoría con sus datos; Audax da el libro de emitidas) | H6923150, H6924889 |

## H. Líneas, descuentos y modo de documento

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-070 | Tipos de línea | Producto, servicio o **título** (agrupa conceptos); *Añadir línea* también ofrece suplido | Administración | **E** (título para agrupar por proyecto) | H6834950, API v2 |
| H-071 | Descuentos | **+ Añadir descuento** por línea o sobre el total, en %; se puede ocultar | Administración | **E** | H6834950, H8727572 |
| H-072 | Unidades | `unit_type` y `units` | Administración | **E** (horas, unidades, meses) | API v2 |
| H-073 | Modo documento | Columnas del PDF: *Por defecto*, *Ítems*, **Tiempo** (columnas Horas y Precio/hora), *Total* y *Sin impuestos* | Administración | **E** (el modo «Tiempo» es el de una agencia) | H6877971 |
| H-074 | Texto final y nota interna | `body` (texto impreso) y `notes` (interna) | Administración | **E** | H6834950, API v2 |

## I. Vencimientos, cobros y tesorería

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-075 | Varios vencimientos | Vencimiento → **Múltiples fechas** (sin recordatorios en ese caso) | Finanzas | U | H6834950, H6984213 |
| H-076 | Añadir pago | Panel *Pagos* → **Añadir pago** (cantidad, fecha y cuenta). Cobros parciales sin límite. API `POST /api/v2/invoices/{id}/payments` | Finanzas | **E** | H6877350, API v2 |
| H-077 | Conciliar desde la factura | *Pagos* → **Conciliar** → elegir movimiento del banco | Finanzas | U (P6) | H6877350 |
| H-078 | Pagos y cobros sueltos | Tesorería → **Nuevo pago**; relacionar después con una factura | Finanzas | U | H7908194 |
| H-079 | Compensar facturas | Cliente que también es proveedor: pasarela manual «Compensación» | Finanzas | S | H6984864 |
| H-080 | Formas de pago | Configuración → Facturación → Formas de pago → **+ Añadir método**: nombre, **texto visible en el documento**, banco (muestra el IBAN), vencimiento; *predeterminado* | Administración | **E** | H6881253 |
| H-081 | Pasarelas | Stripe, PayPal, Square y GoCardless (**Conectar**) | Finanzas | S (las agencias cobran por transferencia) | H6881253 |
| H-082 | Holded Wallet | Cobro con tarjeta desde el portal (10 €/mes) | Cliente | S | H13741751 |
| H-083 | Remesas SEPA | Tesorería → Remesas → **+** → cobro o pago; se marcan facturas y se descarga el fichero para el banco. Estados Pendiente, Procesando, Completado. **[SIN VERIFICAR: formato del fichero]** | Finanzas | U (solo si domicilian fees, P6) | H6955882 |
| H-084 | Cuentas bancarias | Banco, tarjeta, pasarela o caja; sincronización diaria | Finanzas | U | H6927398 |
| H-085 | Conciliación bancaria | Movimientos a la izquierda y documentos a la derecha, con **sugerencias inteligentes** | Finanzas | U (P6) | H10511734, H6948805 |
| H-086 | Reglas de conciliación | Condiciones sobre descripción o importe → sugerir o conciliar solo | Finanzas | S | H6975866 |

## J. Envío por email y recordatorios

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-087 | Enviar documento | **Enviar** → destinatario, plantilla editable, CC y CCO → **Enviar**. Las personas con «Enviar copias» van en copia; opción de adjuntar los archivos del documento | Administración | **E** | H6836216, API v2 |
| H-088 | Sistema de envío | Desde Holded, desde un SMTP propio (Avanzado) o desde un proveedor; **Enviar email de prueba** | Administración | **E** (SMTP de Audax, aún pendiente, D-030) | H6893981 |
| H-089 | Historial y apertura | Documento, destinatario, si llegó y **si se abrió** | Administración | U (enviado y entregado; la apertura, sin píxel espía) | H6893981 |
| H-090 | Plantillas de email | **+ Nueva plantilla**: diseño, idioma, *predeterminada*, **Incluir link al Portal**, *Incluir PDF*, asunto, mensaje, firma, **Enviar test**; una por tipo de documento | Administración | **E** | H6884614 |
| H-091 | Palabras dinámicas del email | `[contactname]`, `[docnum]`, `[doclink]`, `[total]`, `[duedate]`… | Administración | **E** | H6893888 |
| H-092 | Recordatorios de cobro | Ventas → **Recordatorios**: condiciones (importe, tags, idioma), X días antes o después del vencimiento, mensaje y **activar**. Un envío por factura y regla. Solo Avanzado y Premium | Finanzas | **E** | H6984213, PRE |

## K. Plantillas PDF

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-093 | Editor de plantillas | **Nueva plantilla**: más de 50 diseños, logo, tipografía, color, orientación, marca de agua, campos y pie | Administración | U (Audax tendrá **una** plantilla de marca con Gotenberg, D-140) | H6883800 |
| H-094 | Opciones avanzadas | Columnas de la tabla, **campos legales** (condiciones, **registro mercantil**), datos de la empresa y nombres de los documentos | Administración | **E** (los textos legales) | H6883800 |
| H-095 | HTML propio | Variables `%DOCNUM%`, `%TOTAL%`, `%VERIFACTU%`, condicionales y saltos de página | — | S | H16782294 |
| H-096 | Plantilla por tipo de documento | Diseño y email por tipo; se cambia en cada documento desde Opciones | Administración | U | H6883800 |

## L. Portal del cliente

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-097 | Qué ve el cliente | Sus documentos (sin borradores ni anuladas), pagar, **aceptar presupuestos**, descargar, comentar, catálogo y pedidos | Cliente | **E** (facturas, presupuestos y aceptación) | H9382815 |
| H-098 | Acceso | Con usuario y contraseña (sesión de 30 días) o con **enlace a un documento válido 48 h**; invitación masiva; API `GET /api/v2/contacts/{id}/portal-link` | Cliente | **E** (Audax ya tiene usuarios de portal; falta el enlace firmado al documento) | H9382825, H14321243 |
| H-099 | Visibilidad | Ajuste general y por contacto | Administración | **E** (con los ajustes del portal del cliente, D-097) | H9382825 |
| H-100 | Pago online | Interruptor de PayPal, Stripe o Square por contacto; si no, se muestra el IBAN | Cliente | S (IBAN visible: **E**) | H6951801 |
| H-101 | Marca del portal | Nombre, logo, fuente, botones y color | — | S (el portal ya es de Audax) | H12529773 |
| H-102 | Comentarios del cliente | En cada documento | Cliente | U | H9382835 |

## M. Gastos y compras (lo que afecta a una agencia)

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-103 | Factura de compra | Compras → **Nueva compra**: número del proveedor e interno, fecha contable, líneas, cuenta, **proyecto** y adjunto | Finanzas | U (solo para imputar costes externos a proyectos; la contabilidad, en la gestoría, P5) | H6899680 |
| H-104 | Escáner con OCR | Subida, buzón de email o foto; estados Procesando, Revisar, Hecho, Error; resumen con IA y **Crear documento** | Finanzas | S | H6908098, H6908247 |
| H-105 | Compras recurrentes, rectificativas e importación | Vistas en el índice, no leídas en detalle | Finanzas | S | índice de la ayuda |

## N. Informes

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-106 | Informe de ventas | Por cliente, producto, servicio, cuenta, forma de pago, ciudad y país; métricas coste, impuestos, margen, unidades y total; 12 meses; Excel o PDF | Dirección | **E** (por cliente y servicio, y además por proyecto y tipo de facturación) | H7222638 |
| H-107 | Informe de ingresos | Todo el grupo 7 | Dirección | S | H7222764 |
| H-108 | Libro de facturas emitidas | Número, ejercicio, periodo, tipo, fechas de expedición y operación, serie, NIF, base, IVA, retención, recargo, total y pagado; incluye anuladas | Finanzas, Asesoría | **E** (es lo que pide la gestoría) | H7222830 |
| H-109 | Informes de presupuestos | Por cliente, producto, servicio y cuenta | Dirección | U (tasa de aceptación) | H7226946 |
| H-110 | Tableros | Widgets de ventas, ingresos, **cobros pendientes** y beneficio | Dirección | **E** (cuadro de facturación) | H7916935 |
| H-111 | Pendiente de cobro | Widget y filtro `pending` con vencimiento pasado | Finanzas | **E** | H7916935, API v2 |
| H-112 | Registro de actividad | Crear, aprobar, actualizar, eliminar y restaurar, con los datos anteriores (solo administrador y propietario) | Administración | **E** (Audax ya lo tiene con `activitylog`) | H8064603 |

## O. Contabilidad, gestoría y exportaciones

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-113 | Asientos automáticos | Cuentas por defecto (4300, 7000000…), prioridad contacto > servicio > general | Finanzas | S (la contabilidad la lleva la gestoría; P5) | H6826765, H6984553 |
| H-114 | Libro diario | Importar y exportar, bloquear periodo, cierre | Asesoría | S | H6895995 |
| H-115 | Acceso de la asesoría | Mi Asesoría → **Conceder acceso** con un rol, **Quitar acceso**; casilla *«Es una asesoría»*; no ocupa plaza | Asesoría | **E** (rol «Gestoría» de solo lectura) | H7067788, H6921921 |
| H-116 | Holded para asesorías | Licencias contables y certificados para presentar modelos | Asesoría | S | H8486233 |
| H-117 | Exportar a A3 | Fichero `SUENLACE.DAT` con *Identificador A3* | Asesoría | U (según el programa de la gestoría, P5) | help.holded.com/en/articles/6907973 |
| H-118 | Exportar a Sage | Ingresos y compras en `.xls`. **[SIN VERIFICAR: ContaPlus]** | Asesoría | U (P5) | help.holded.com/en/articles/7052929 |
| H-119 | Exportaciones generales | Excel, PDF, Google Sheets o por ítem; ZIP o lote de PDF por email | Finanzas, Asesoría | **E** (XLSX, CSV y ZIP de PDF del periodo) | H6877413 |

## P. Transversales

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-120 | Multimoneda | Divisas con cambio diario o fijo; en el documento, moneda y cambio editable | Administración | U (clientes fuera de la zona euro; la cuota de IVA siempre en euros, RD 1619/2012, art. 12) | H6985003 |
| H-121 | Multiidioma | Idioma por contacto, documento, plantilla de email y portal | Administración | **E** (español e inglés: clientes de la UE y de fuera) | H6921812, H6884614 |
| H-122 | Adjuntos | **Subir archivo** al documento, visibles en el portal | Administración | **E** (reutiliza `attachments`) | H6877413 |
| H-123 | Campos personalizados en documentos | `custom_fields[]` (plan Avanzado) | — | S (el número de pedido del cliente, como campo fijo: **U**) | API v2, PRE |
| H-124 | Canales de venta y almacenes | Cuentas por canal; almacenes con Inventario | — | S | API v2 |

## Q. Proyectos de Holded (lo más cercano a una agencia)

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-125 | Proyecto ↔ contacto | *Asignar proyecto a un contacto*, requisito para facturarlo | Administración | **E** (en Audax, `projects.client_id`) | H6899295 |
| H-126 | Modo de facturación del proyecto | Tarifa fija, por hora de proyecto, por miembro o por categoría | Administración | **E** (Audax: `billing_type` y `RateResolver`) | H6899295 |
| H-127 | Previsión de costes | Coste por miembro o categoría y horas totales | Dirección | **E** (Audax: estimaciones y `hourly_cost`) | H6899295 |
| H-128 | Tarifas y costes por usuario | Tarifa facturable y coste por hora por usuario; categorías facturables; cambios no retroactivos | Administración | **E** (Audax: instantáneas al aprobar) | H6899693 |
| H-129 | Vincular documentos al proyecto | Pestaña **Facturación** del proyecto → **Crear** factura, compra, pedido o presupuesto, **Relacionar** o **Desasignar**; una factura puede repartirse entre proyectos (`projects_summary`) | Administración | **E** | H6899295, API v2 |
| H-130 | Presupuesto desde el proyecto | Botón **Presupuesto** → cantidad → **Revisar** → **Guardar** | Administración | U | H6899295 |
| H-131 | Registros horarios | Cronómetro o fracciones; **Enviar para aprobación**; solo cuentan las aprobadas | Todos | Ya existe en Audax (y mejor: semanas, bloqueo y bolsas) | H6902989 |
| H-132 | **Facturar horas** | Pestaña Facturación → **Factura → Registros horarios** → *Opciones de facturación*: horas (todas las pendientes, sin facturar desde una fecha o ninguna) y **cómo mostrarlas** (una línea por tarea, por persona, por proyecto, por categoría o por registro) → **Revisar factura** → **Guardar** | Administración | **E** (el flujo que hay que calcar) | H6902989 |
| H-133 | Carga y rentabilidad | Carga de trabajo, rentabilidad por proyecto y horas introducidas | Dirección | Ya existe en Audax (informes de la Fase 2 y cargas) | H6972308, H6907970 |

## R. VeriFactu, TicketBAI, SII y factura electrónica

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-134 | Activar Verifactu | Configuración → Facturación → **Conformidad** → *Ley Antifraude* → **Configurar** → obligada o exenta → **Verifactu**. Certificado propio o el de Holded (colaborador social). Hay que mantenerlo hasta el 31/12 | Propietario | **E** cuando sea exigible (PLAN §2) | H11406443 |
| H-135 | Reglas Verifactu | España, intracomunitaria, exportación y tickets sin contacto; **Añadir nueva regla** | Administración | **E** (con VeriFactu) | H11651288 |
| H-136 | Campos Verifactu de la factura | Tipo de ID, clave de operación, descripción, fecha de operación, tipo de factura, **Causa** si no hay IVA; **Verificar** antes de aprobar | Administración | **E** (con VeriFactu) | H11406991 |
| H-137 | Envío y QR | Al **Aprobar** se envía a la AEAT, que devuelve el **QR** y el identificador; estado *Aceptada* o *Rechazada*; QR de 30 a 40 mm arriba. **[SIN VERIFICAR: la leyenda literal]** | — | **E** (con VeriFactu) | H11406283, H16782294 |
| H-138 | TicketBAI y SII | País Vasco y grandes empresas | — | S (Audax está en territorio común y, previsiblemente, por debajo de 6 M€) | H6951082, H6951710 |
| H-139 | Factura-e (Facturae 3.2.x) y FACe | DIR3, envío directo a FACe o descarga firmada. **[SIN VERIFICAR: factura electrónica B2B de la Ley Crea y Crece]** | Administración | U (FACe solo si hay clientes públicos); la B2B, **E** antes de octubre de 2028 | H6967114, H7263115 |

## S. Importación, exportación y API

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-140 | Importar facturas de venta | **Acciones → Importar** (XLSX); entran siempre como borrador | Administración | S | H7228579 |
| H-141 | Salir de Holded | Al cancelar, **los datos se borran y no se recuperan**: hay que exportar antes (Excel, PDF en lote, libro diario, A3, Sage o API) | Propietario | **E** (migración, PLAN §5) | H7854188 |
| H-142 | API v2 | Facturas: `GET/POST /api/v2/invoices`, `GET …/{id}`, `…/{id}/approve`, `…/{id}/cancel`, `…/{id}/payments`, `…/{id}/send`, `GET …/{id}/pdf`. Además `POST /api/v2/documents/convert`, `/credit-notes`, `/recurring-invoices`, `/estimates/{id}/accept`, `/numbering-series/{type}`, `/taxes`, `/payments`, `/contacts`, `/contacts/{id}/portal-link`, `/services`, `/projects`. Webhooks firmados. Límites por plan: de 60 a 600 peticiones por minuto y de 500 a 100.000 al mes | — | **E** (migración y, en la opción A, emisión) | API v2 |

**Campos de una factura en la API v2:** `contact_id`, `date`, `due_date`, `notes`, `body`, `language`, `currency`, `currency_change`, `number_line_id` (serie) o `number`, `payment_method_id`, `design_id`, `discount`, `tags[]`, `custom_fields[]` e `items[]` con `name`, `type` (product, service o title), `description`, `service_id`, `units`, `price`, `discount`, `taxes[]` (`s_iva_21`), `retention`, `project_id`, `unit_type` y `supplied`. La respuesta añade `document_number`, `subtotal`, `tax`, `total` (cadenas decimales), `status`, `approved_at`, `draft`, `projects_summary[]`, `payments_total` y `payments_pending`. En la v1: `contactId`, `date` (Unix), `dueDate`, `numSerieId`, `invoiceNum`, `approveDoc` e `items[]`; el PDF llega en base64.

## T. Usuarios y permisos

| Id | Funcionalidad | Qué hace y cómo se usa en Holded | Rol | Audax | Fuente |
|---|---|---|---|---|---|
| H-143 | Tipos de acceso | Usuario de plataforma (con rol), usuario de tarjeta y usuario de equipo («Mi zona») | Propietario | — | H6921921 |
| H-144 | Roles predefinidos | Propietario, Administrador, **Finanzas** (ventas, contabilidad, bancos, contactos y proyectos), **Ventas**, Inventario, RR. HH., Miembro de proyectos, Miembro de CRM y **Agente de ventas** (solo sus documentos y contactos, sin costes). Personalizados en Avanzado | Propietario | **E** (Finanzas, Gestoría y «prepara borradores») | H6921921, H8851048 |
| H-145 | Restricciones fijas | Solo administrador y propietario suben el certificado y ven el registro de actividad; solo los administradores presentan modelos; 2FA; claves de API por ámbito | Propietario | **E** | H6923150, H8064603 |
| H-146 | Planes | Plus 15 €, Básico 29 €, Estándar 59 €, Avanzado 99 € y Premium 199 € al mes, sin IVA. Recurrentes, remesas, asientos y roles desde Estándar; recordatorios, SMTP propio y roles personalizados desde Avanzado | Propietario | — | PRE |

---

## Resumen: lo esencial para Audax

1. **Ciclo del documento:** borrador sin número → **Aprobar** (número correlativo de la serie, bloqueado) → enviar → cobrar (parcial o total) → anular o rectificar. Editar una aprobada solo en lo no fiscal (H-037 a H-046).
2. **Presupuesto → aceptación en el portal → previsión de facturación por hitos → factura** (H-024 a H-028). En Audax, además, **presupuesto aceptado → proyecto** con su presupuesto de horas.
3. **Facturar horas desde el proyecto**, con la elección de cómo agrupar las líneas (H-132), y la columna «Horas × Precio/hora» del PDF (H-073).
4. **Recurrentes** para los fees, en borrador por defecto y con «Fee de [mes]» (H-057 a H-061).
5. **Series con su serie de rectificativas**, reinicio anual y aviso de fechas desordenadas (H-050 a H-052, H-039).
6. **Contacto con datos fiscales, régimen (general, intracomunitario, extranjero), forma y días de pago, idioma y personas en copia** (H-001 a H-008).
7. **Envío por email con plantilla, recordatorios de cobro y portal** con facturas y aceptación de presupuestos (H-087 a H-092, H-097 a H-099).
8. **Libro de emitidas, pendiente de cobro, ventas por cliente** y **acceso de la gestoría** de solo lectura con exportaciones (H-106 a H-119).

**Sobra:** inventario, albaranes y pedidos, tickets, pasarelas y Wallet, OSS, contabilidad y modelos (gestoría), escáner, pipelines, campos personalizados y canales de venta.

**Lo que en Audax ya es mejor que en Holded:** las horas (semanas, aprobación y bloqueo), las bolsas con su exceso, la valoración al céntimo (D-083) y los informes de rentabilidad.

## Lo que no se pudo verificar
- Etiquetas literales de estado del listado de facturas («Pendiente», «Pagada», «Vencida»…): solo los valores de la API.
- Rechazar un presupuesto desde el portal (por API sí existe).
- Validación del NIF-IVA en VIES, formato del fichero de remesas, exportación a ContaPlus, factura electrónica B2B y la leyenda literal de Verifactu en el PDF.
- Pantalla de varias direcciones de envío por contacto.
- Rutas exactas de la mayoría de los ~360 endpoints de la v2.

## Fuentes

| Id | URL |
|---|---|
| H6834950 | https://help.holded.com/es/articles/6834950-crear-una-factura-de-venta |
| H6887128 | https://help.holded.com/es/articles/6887128-factura-en-modo-borrador-como-funciona-y-cuando-se-aplica |
| H6877413 | https://help.holded.com/es/articles/6877413-gestionar-tus-facturas-de-venta |
| H7206561 | https://help.holded.com/es/articles/7206561-otras-operaciones-disponibles-para-los-documentos-de-venta |
| H16201260 | https://help.holded.com/es/articles/16201260-arbol-de-conversion-relacion-entre-documentos |
| H14686589 | https://help.holded.com/es/articles/14686589-como-editar-una-factura-aprobada |
| H6987424 | https://help.holded.com/es/articles/6987424-crear-y-gestionar-presupuestos |
| H6895939 | https://help.holded.com/es/articles/6895939-gestionar-las-opciones-avanzadas-de-un-presupuesto |
| H6987492 | https://help.holded.com/es/articles/6987492-crear-y-gestionar-proformas |
| H6895315 | https://help.holded.com/es/articles/6895315-crear-y-gestionar-ventas-rectificativas |
| H6895111 | https://help.holded.com/es/articles/6895111-crear-y-gestionar-facturas-recurrentes |
| H6878171 | https://help.holded.com/es/articles/6878171-crear-la-numeracion-de-tus-documentos |
| H6877971 | https://help.holded.com/es/articles/6877971-configurar-las-preferencias-de-ventas-y-compras |
| H6893888 | https://help.holded.com/es/articles/6893888-usar-las-palabras-dinamicas |
| H7854006 | https://help.holded.com/es/articles/7854006-resolver-problemas-numeracion-duplicada |
| H8727471 | https://help.holded.com/es/articles/8727471-resolver-problemas-reiniciar-la-numeracion-de-facturacion |
| H6836216 | https://help.holded.com/es/articles/6836216-enviar-tus-facturas-por-email |
| H6884614 | https://help.holded.com/es/articles/6884614-crear-y-asignar-plantillas-de-emails |
| H6893981 | https://help.holded.com/es/articles/6893981-configurar-el-sistema-de-envio-por-email |
| H6984213 | https://help.holded.com/es/articles/6984213-como-funcionan-los-recordatorios-de-facturas |
| H6881253 | https://help.holded.com/es/articles/6881253-incluir-formas-de-pago-en-tus-facturas |
| H6883800 | https://help.holded.com/es/articles/6883800-crear-y-asignar-las-plantillas-de-tus-documentos |
| H16782294 | https://help.holded.com/es/articles/16782294-guia-para-editar-plantillas-de-documentos-de-holded-html |
| H6921812 | https://help.holded.com/es/articles/6921812-crear-un-contacto |
| H6922239 | https://help.holded.com/es/articles/6922239-gestionar-tus-contactos |
| H6911739 | https://help.holded.com/es/articles/6911739-glosario-de-contactos |
| H6927107 | https://help.holded.com/es/articles/6927107-crear-y-gestionar-campos-personalizados |
| H6983650 | https://help.holded.com/es/articles/6983650-como-usar-tags-o-etiquetas-en-holded |
| H7325554 | https://help.holded.com/es/articles/7325554-importar-y-actualizar-tus-contactos |
| H6896098 | https://help.holded.com/es/articles/6896098-crear-y-gestionar-tus-servicios |
| H6838658 | https://help.holded.com/es/articles/6838658-crear-un-producto |
| H6865131 | https://help.holded.com/es/articles/6865131-gestionar-tarifas-de-producto |
| H6877350 | https://help.holded.com/es/articles/6877350-anadir-pagos-a-tus-facturas-de-venta |
| H7908194 | https://help.holded.com/es/articles/7908194-pagos-y-cobros |
| H6955882 | https://help.holded.com/es/articles/6955882-crear-y-gestionar-remesas-en-holded |
| H6984864 | https://help.holded.com/es/articles/6984864-compensar-facturas-entre-si |
| H6985003 | https://help.holded.com/es/articles/6985003-divisas-y-tipos-de-cambio |
| H9382815 | https://help.holded.com/es/articles/9382815-portal-del-cliente-que-es-y-como-funciona |
| H9382825 | https://help.holded.com/es/articles/9382825-habilitar-y-configurar-el-portal-del-cliente |
| H9382835 | https://help.holded.com/es/articles/9382835-acciones-disponibles-en-el-portal-del-cliente |
| H14321243 | https://help.holded.com/es/articles/14321243-como-accede-un-contacto-al-portal-del-cliente |
| H12529773 | https://help.holded.com/es/articles/12529773-portal-del-cliente-personalizar-la-apariencia |
| H6951801 | https://help.holded.com/es/articles/6951801-activar-metodos-de-pago-online-en-el-portal-del-cliente |
| H13741751 | https://help.holded.com/es/articles/13741751-holded-wallet-aceptar-pagos-con-tarjeta-y-cobrar-facturas-online |
| H11406283 | https://help.holded.com/es/articles/11406283-como-funciona-verifactu-en-holded |
| H11406443 | https://help.holded.com/es/articles/11406443-paso-a-paso-para-configurar-la-ley-antifraude-y-verifactu-en-holded |
| H11406991 | https://help.holded.com/es/articles/11406991-verifactu-crear-y-tramitar-una-factura-de-venta |
| H11508100 | https://help.holded.com/es/articles/11508100-verifactu-y-facturas-rectificativas-en-holded |
| H11508249 | https://help.holded.com/es/articles/11508249-verifactu-preguntas-frecuentes |
| H11651288 | https://help.holded.com/es/articles/11651288-verifactu-como-funcionan-las-reglas-y-para-que-sirven |
| H6951082 | https://help.holded.com/es/articles/6951082-como-funciona-ticketbai |
| H6951710 | https://help.holded.com/es/articles/6951710-como-funciona-la-integracion-con-el-suministro-de-informacion-inmediata-sii |
| H6967114 | https://help.holded.com/es/articles/6967114-crear-y-gestionar-una-factura-electronica |
| H7263115 | https://help.holded.com/es/articles/7263115-factura-e-preguntas-frecuentes |
| H6923165 | https://help.holded.com/es/articles/6923165-tipos-de-impuestos-y-sus-porcentajes |
| H6901010 | https://help.holded.com/es/articles/6901010-controlar-las-tablas-de-iva-y-de-impuestos |
| H6984324 | https://help.holded.com/es/articles/6984324-crear-un-impuesto |
| H6984520 | https://help.holded.com/es/articles/6984520-configurar-impuestos-por-pais |
| H6923150 | https://help.holded.com/es/articles/6923150-que-puedes-hacer-en-el-apartado-de-impuestos |
| H6924889 | https://help.holded.com/es/articles/6924889-modelo-349-que-tener-en-cuenta |
| H6921921 | https://help.holded.com/es/articles/6921921-la-gestion-de-usuarios |
| H7067788 | https://help.holded.com/es/articles/7067788-gestionar-el-acceso-de-una-asesoria-a-tu-cuenta |
| H8851048 | https://help.holded.com/es/articles/8851048-resolver-problemas-restringir-el-acceso-a-los-documentos-de-venta-y-a-los-contactos |
| H7854188 | https://help.holded.com/es/articles/7854188-planes-y-seguridad-preguntas-frecuentes |
| H8486233 | https://help.holded.com/es/articles/8486233-como-funcionan-las-licencias-de-gestion-contable |
| H8064603 | https://help.holded.com/es/articles/8064603-como-funciona-el-registro-de-actividad |
| H6899295 | https://help.holded.com/es/articles/6899295-facturacion-costes-y-rentabilidad-de-un-proyecto |
| H6899693 | https://help.holded.com/es/articles/6899693-configuracion-general-de-facturacion-y-presupuestos-en-proyectos |
| H6902989 | https://help.holded.com/es/articles/6902989-los-registros-horarios |
| H6972308 | https://help.holded.com/es/articles/6972308-la-carga-de-trabajo |
| H6907970 | https://help.holded.com/es/articles/6907970-los-informes-de-proyectos |
| H7222638 | https://help.holded.com/es/articles/7222638-consultar-el-informe-de-ventas |
| H7222764 | https://help.holded.com/es/articles/7222764-consultar-el-informe-de-ingresos |
| H7222830 | https://help.holded.com/es/articles/7222830-consultar-el-libro-de-facturas-emitidas |
| H7226946 | https://help.holded.com/es/articles/7226946-consultar-el-informe-de-presupuestos |
| H7916935 | https://help.holded.com/es/articles/7916935-los-tableros-de-mando-boards |
| H10511734 | https://help.holded.com/es/articles/10511734-la-conciliacion-bancaria-en-holded |
| H6975866 | https://help.holded.com/es/articles/6975866-reglas-para-automatizar-la-conciliacion |
| H6948805 | https://help.holded.com/es/articles/6948805-sugerencias-inteligentes-de-conciliacion |
| H6927398 | https://help.holded.com/es/articles/6927398-anadir-y-configurar-un-banco-tarjeta-pasarela-de-pago-o-caja |
| H7228579 | https://help.holded.com/es/articles/7228579-importar-tus-facturas-de-venta |
| H6895995 | https://help.holded.com/es/articles/6895995-importar-y-exportar-tus-asientos-contables |
| H6826765 | https://help.holded.com/es/articles/6826765-automatizar-tus-cuentas-contables |
| H6984553 | https://help.holded.com/es/articles/6984553-asignar-una-cuenta-de-venta-y-de-gastos |
| H8727572 | https://help.holded.com/es/articles/8727572-documentos-de-venta-y-compra-preguntas-frecuentes |
| H10900972 | https://help.holded.com/es/articles/10900972-firma-digital |
| H6908433 | https://help.holded.com/es/articles/6908433-facturar-un-albaran |
| H6908409 | https://help.holded.com/es/articles/6908409-facturar-un-pedido |
| H6881603 | https://help.holded.com/es/articles/6881603-crear-un-albaran-de-venta-o-compra |
| H6908098 | https://help.holded.com/es/articles/6908098-escaner-que-es-y-como-funciona |
| H6908247 | https://help.holded.com/es/articles/6908247-escaner-convertir-tus-gastos-en-documentos-de-compra |
| H6899680 | https://help.holded.com/es/articles/6899680-crear-una-factura-de-compra |
| H6838907 | https://help.holded.com/es/articles/6838907-glosario-de-ventas |
| API v2 | https://www.holded.com/es/desarrolladores (referencia en `/es/desarrolladores/referencia-api/…`) |
| API v1 | https://www.holded.com/es/desarrolladores/v1/invoice-api/ |
| PRE | https://www.holded.com/es/precios (consultada el 06/10/2026, con oferta temporal) |
| Reseñas | https://www.tramitapp.com/blog/holded-opiniones/ y https://rankiabusiness.com/opiniones-holded/ |
