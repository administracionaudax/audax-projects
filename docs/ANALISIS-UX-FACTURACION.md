# Facturación: análisis de usabilidad e interfaz y propuesta de mejora

Fecha: 08/10/2026. Rama analizada: `fase-10` (worktree `fase-7-contrato`), servidor local en el puerto 8090 con el Holded falso (`HOLDED_DRIVER=fake`) y el módulo `billing` encendido.

Material de apoyo, en esta misma carpeta:
- `actual/`: 44 capturas a 1440 px y 390 px, más las interacciones (prefijo `i`).
- `recortes/`: ampliaciones de algunas capturas largas.
- `investigacion.md`: la investigación completa de las 11 herramientas, con sus fuentes y el grado de fiabilidad de cada patrón.

## Resumen en cinco líneas

1. Facturación hoy es un conjunto de informes y listados correctos por separado, pero sin una portada que diga qué hay que hacer. Las tres tareas diarias (cobrar lo vencido, enlazar facturas y casar contactos) están repartidas en cuatro pantallas.
2. La navegación está duplicada: la barra lateral y las pestañas enseñan las mismas seis entradas, los títulos no coinciden con las pestañas y las migas de pan no son coherentes.
3. El listado de facturas y los contactos no escalan a los datos reales (910 facturas y 283 contactos, D-248 y D-404). Los filtros ocupan el primer pliegue, no hay orden, ni totales al pie, ni acciones en bloque, y cada contacto es una tarjeta de 130 px con un formulario.
4. Las cifras mezclan bases (sin IVA y con IVA) en tarjetas vecinas, y la más útil para una agencia, lo pendiente de facturar, está escondida en un texto secundario.
5. La propuesta: una portada «Resumen» orientada a la acción, un listado de facturas denso y rápido (vistas por acción, barra de importes que filtra, vista lateral para enlazar en serie), una bandeja única «Por revisar» y la pantalla «Por facturar», que es la semilla de la emisión de la Fase 12.

---

## Estado de la implantación

- **Tanda 1 (09/10/2026, rama `facturacion-ux-1`, decisiones D-405 a D-410):** I8, I2, I6, I3, I4 y R6, adaptados al aspecto de Audax (los bocetos del apartado 3.4 eran ilustrativos). Capturas en `tanda-1/`. Diferencias con la propuesta:
  - La entrada «Resumen» no sale en la barra lateral hasta que exista la pantalla (I1); la ruta `/facturacion` ya está.
  - Ajustes va como última entrada de la lista, no como engranaje en la cabecera de la sección.
  - En el listado, «Vencimiento» no es columna propia: el estado ya dice los días y la columna Estado se ordena por el vencimiento. Sin vista lateral, selección múltiple ni «Deshacer» (R1, R3 y R2 siguen pendientes).
  - En el móvil, el listado sigue siendo una tabla que se desplaza dentro de su caja (las tarjetas son I7).
- **Tanda 2 (09/10/2026, rama `facturacion-ux-2`, decisiones D-411 a D-416):** I1, I5, I10, I7, I9 y R7. Capturas en `tanda-2/`. Diferencias con la propuesta:
  - Resumen: «Pendiente» y «Vencido» son de todas las facturas, a hoy (no del periodo), y la gráfica por mes va con IVA en las dos series para poder comparar lo facturado con lo cobrado (D-411). El periodo es el chip del listado, sin «Todo».
  - Por revisar: «Rechazar» una propuesta de factura no se guarda (deja elegir otro proyecto); en los contactos, ✗ es «Descartar», como siempre. Sin atajos de teclado ni selección múltiple (R9 y R3 siguen pendientes); «Deshacer» guarda la última acción en la sesión, no una lista «Hechos hoy» (D-414).
  - Por facturar: los precios cerrados no entran (se facturan por hitos, F3) y la lista no tiene aún el botón «Crear factura» (L2).
  - Vendido frente a real: sin el interruptor «Horas | Importes» (la tabla ya cabe, D-410); por horas, «Por facturar» o «Facturado al día» en lugar del porcentaje (D-416). Las filas siguen sin enlazar a sus facturas (VFR-5).
  - Móvil: las tablas de las gráficas («Ver como tabla») se desplazan dentro de su caja; el resto, tarjetas.
- **Siguiente tanda:** R1, R3, R2, R4, R5, R8 y R9.

---

## 1. Diagnóstico de lo actual, pantalla por pantalla

Cada problema lleva un código, su gravedad (alta, media o baja) y la captura donde se ve. Las rutas de las capturas son relativas a `actual/`.

### 1.0 Lo que ya funciona bien (y hay que conservar)

- Las gráficas cumplen las normas: paleta `--chart-1..6` en orden fijo, cada gráfica con «Ver como tabla», tooltips también con el teclado y textos que explican cómo leerla (`01-informe-1440.png`, `i03-informe-mes-como-tabla.png`).
- El gráfico de bala de «Vendido frente a real» es claro y se adapta bien al móvil (`02-vendido-390.png`, ampliado en `recortes/02-390-b.png`).
- Los estados de cobro combinan icono, texto y color, no solo color (`04-facturas-1440.png`).
- La lista de «Facturas vencidas» del informe, agrupada por cliente y con los días de retraso, es lo más accionable del módulo (`01-informe-1440.png`, abajo a la derecha).
- Las sugerencias de enlace explican su motivo («Fee mensual», «Horas») y se aceptan con un clic (`i06-enlazar-sugerencia-antes.png`).
- Los avisos de permisos están bien resueltos: un responsable ve «Vendido frente a real» solo en horas y con una nota que lo explica (`13-responsable-vendido-1440.png`).
- Los filtros viven en la URL y las exportaciones respetan los mismos filtros.

### 1.1 Problemas transversales

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| T-1 | Alta | No hay portada. `/facturacion` lleva al informe, que es un análisis de ventas y no responde a «qué tengo que hacer hoy». Lo vencido está al final del informe, las facturas sin enlazar en un aviso del listado y los contactos sin casar en otra pestaña. | `01-informe-1440.png` |
| T-2 | Alta | Navegación duplicada: la sección Facturación de la barra lateral y las pestañas `BillingTabs` repiten las mismas entradas. Dos sistemas para lo mismo ocupan 60 px de alto en cada pantalla y obligan a decidir dónde pulsar. | `01-informe-1440.png`, `04-facturas-1440.png` |
| T-3 | Media | Títulos incoherentes: el H1 es «Informe de facturación» o «Vendido frente a real» en unas pantallas y el genérico «Facturación» en Facturas, Contactos y Ajustes. «Horas para [facturar]» es la única con la palabra destacada en color. Las migas de Contactos apuntan a `/facturacion/facturas` y las del informe a `/facturacion`. | `04-facturas-1440.png`, `06-contactos-sin-casar-1440.png`, `03-horas-1440.png` |
| T-4 | Alta | Los filtros se comen el primer pliegue. En el informe, periodo, comparación, clientes y 12 botones de servicio en dos filas: los indicadores empiezan a 480 px a 1440 y a 760 px en el móvil. En el listado, siete campos con etiqueta encima. | `01-informe-1440-pliegue.png`, `01-informe-390-pliegue.png`, `recortes/04-390-a.png` |
| T-5 | Alta | Bases mezcladas. «Facturado 399.791 €» (sin IVA) está junto a «Cobrado 472.016 €» (con IVA): parece que se ha cobrado más de lo facturado. En Facturas, «Base imponible», «Total con IVA», «Cobrado» y «Pendiente» van en tarjetas idénticas sin agrupar. | `recortes/i02-a.png`, `04-facturas-1440-pliegue.png` |
| T-6 | Media | Las cifras no llevan a ninguna parte. Ningún indicador es pulsable: «Vencido 11.900 €» no abre las facturas vencidas y «Pendiente de cobro» no filtra el listado. | `01-informe-1440.png` |
| T-7 | Media | La sincronización con Holded solo se ve como un texto («Holded leído el…») y solo se lanza desde Ajustes. No hay aviso de error ni de datos viejos en las pantallas de trabajo. | `04-facturas-1440.png`, `09-ajustes-1440.png` |
| T-8 | Media | Las facturas no aparecen en la búsqueda global (Ctrl K): para abrir la F260314 hay que ir al listado y escribirla. `app/Search` no tiene fuente de facturas. | Código |
| T-9 | Alta | Móvil: el listado de facturas y la tabla de líneas son tablas de 64 rem con desplazamiento lateral; el estado y el pendiente quedan fuera de la pantalla. Las pestañas se cortan («Horas para factura…»). | `recortes/04-390-a.png`, `recortes/05-390.png` |
| T-10 | Baja | La barra lateral no cabe: el pie con el usuario tapa «Contactos de Holded» a 1440 × 900 y en el menú móvil. | `06-contactos-sin-casar-1440.png`, `12-menu-movil-390.png` |
| T-11 | Media | Ayuda en contexto desigual: los indicadores llevan un icono (i) con la fórmula, pero los estados, las sugerencias y los avisos («no cuentan en Vendido frente a real») no explican qué hacer ni por qué. | Varias |

### 1.2 Informe de facturación (`/facturacion/informe`)

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| INF-1 | Alta | Ocho indicadores con el mismo peso visual: Facturado, Frente a 2025, Cobrado, Pendiente, Vencido, Previsto, Facturas y Ticket medio. Ninguno destaca y tres hablan de comparación con el año anterior de forma distinta («+120,3 %», «↑ 1200 % más», «↓ 83 % menos»). | `recortes/i02-a.png` |
| INF-2 | Media | El filtro de servicio son 12 botones en dos filas. Con una selección, los botones no dicen cuántos hay activos ni se pueden combinar de forma evidente. | `01-informe-1440.png` |
| INF-3 | Media | «Hoy» con el periodo «Año» significa «este año»: la etiqueta confunde. | `i01-informe-periodo-abierto.png` |
| INF-4 | Media | Etiquetas cortadas en el ranking de clientes («Clínica Dental …», «Grupo Ferrán Lo…», «Sin cliente cas…») y mucho espacio vacío en «Por servicio». | `01-informe-1440.png` |
| INF-5 | Media | La antigüedad de lo pendiente es una gráfica de columnas sin desglose por cliente: dice cuánto hay en «1-30», pero no de quién. La lista de vencidas, al lado, no tiene acciones (abrir, copiar recordatorio, ver PDF). | `01-informe-1440.png` |
| INF-6 | Baja | No hay gráfico de lo facturado frente a lo cobrado por mes: lo cobrado solo existe como cifra total. | `01-informe-1440.png` |

### 1.3 Vendido frente a real (`/facturacion/vendido-frente-a-real`)

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| VFR-1 | Alta | La tabla no cabe a 1440 px: las columnas Facturado, Cobrado y Margen quedan fuera (se ve «FAC» cortado) y hay que desplazarse en horizontal. Justo las columnas de dinero, que son las que el propietario quiere comparar. | `recortes/02-1440-tabla-dcha.png` |
| VFR-2 | Alta | «Pendiente de facturar 81.300 €», la cifra más accionable para una agencia, va en letra pequeña bajo «Facturado». | `02-vendido-1440.png` |
| VFR-3 | Media | Siete indicadores en dos filas, con formatos difíciles de leer: «Desviación −2618:45» (horas en formato reloj con signo) y «Unidades de venta 18» en rojo sin que 18 sea malo. | `02-vendido-1440.png` |
| VFR-4 | Media | En los proyectos por horas, «vendido» son las horas facturadas. Si se enlaza una factura parcial, el proyecto sale «Pasado 1.556 %» con una barra roja enorme que aplasta la escala de los demás. En un proyecto por horas, pasar de lo facturado no es un exceso: son horas pendientes de facturar. | `13-responsable-vendido-1440.png` (tras enlazar la F260314 con LAM-INT en `i10`) |
| VFR-5 | Baja | El botón «Facturas» de la cabecera duplica la pestaña. Las filas llevan a la bolsa o al proyecto, pero no a las facturas de esa unidad. | `02-vendido-1440.png` |

### 1.4 Horas para facturar (`/facturacion/horas-para-facturar`)

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| HPF-1 | Alta | La pantalla está vacía hasta que eliges un cliente. No dice qué clientes tienen horas sin facturar ni cuántas: hay que probar uno a uno. Harvest resuelve esto con un informe de horas no facturadas por cliente y proyecto. | `03-horas-1440.png` |
| HPF-2 | Media | El selector de cliente va fuera de la caja de filtros y con otro estilo; el estado vacío no propone ningún cliente. | `03-horas-1440.png` |

### 1.5 Listado de facturas (`/facturacion/facturas`)

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| FAC-1 | Alta | Sin periodo por defecto, los indicadores suman toda la historia («Cobrado 781.685 €», «31 documentos»): no sirven para nada. | `04-facturas-1440-pliegue.png` |
| FAC-2 | Alta | Los siete filtros van en una rejilla de ocho columnas que se desborda a 1440 px: el campo «Hasta» sale del contenedor por la derecha. | `04-facturas-1440.png`, `i07-enlazar-sugerencia-despues.png` |
| FAC-3 | Alta | No hay vistas por tarea. Para ver lo vencido hay que abrir el desplegable «Estado de cobro» y para lo que falta enlazar, el de «Enlace». Lo que se mira cada día no está a un clic. | `i04-facturas-filtro-estado.png` |
| FAC-4 | Media | La búsqueda exige pulsar la lupa o Intro, mientras que los desplegables filtran al momento: dos comportamientos distintos en la misma barra. | `i05-facturas-busqueda.png` |
| FAC-5 | Media | No se puede ordenar por ninguna columna (importe, vencimiento, cliente) ni hay totales al pie de lo filtrado. | `04-facturas-1440.png` |
| FAC-6 | Media | Densidad irregular: las filas con sugerencia miden el doble que las demás. La columna «Cobro» repite «Cobrada» en verde en 25 de 31 filas, y eso es ruido. Lo vencido solo dice «Vencida», sin cuántos días. | `04-facturas-1440.png` |
| FAC-7 | Media | Solo el número es un enlace: la fila entera no se puede pulsar y no hay vista previa. Para revisar diez facturas hay que entrar y salir diez veces, y al volver se pierde la posición. | `04-facturas-1440.png` |
| FAC-8 | Media | Al aceptar una sugerencia en la vista «Sin enlazar», la fila desaparece y el aviso no ofrece «Deshacer». | `i06-enlazar-sugerencia-antes.png`, `i07-enlazar-sugerencia-despues.png` |
| FAC-9 | Baja | No hay acciones en bloque (enlazar varias con el mismo proyecto, descargar PDF ni exportar la selección). | `04-facturas-1440.png` |
| FAC-10 | Baja | Los borradores van arriba con «Borrador» como número, mezclados con las emitidas. | `04-facturas-1440.png` |

### 1.6 Ficha de factura (`/facturacion/facturas/{id}`)

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| FIC-1 | Alta | La cabecera no dice lo importante: el H1 es el número (o «Borrador») y el total y el pendiente están dentro de una rejilla 3 × 3 con el mismo peso que «Moneda: EUR». | `05-ficha-enlazada-1440.png` |
| FIC-2 | Alta | El formulario «Enlazar a mano» sale siempre, también cuando la factura ya está enlazada, y su botón deshabilitado en azul claro parece roto. La sugerencia, el enlace actual y el formulario son tres cajas para una sola decisión. | `05-ficha-enlazada-1440.png`, `05b-ficha-sin-enlazar-1440.png` |
| FIC-3 | Media | El buscador de proyecto solo ofrece los proyectos del cliente de la factura. Si escribes otro, responde «Ningún proyecto con ese código, nombre o cliente», aunque exista. | `i08-ficha-enlazar-selector.png`, `i09-ficha-enlazar-buscando.png` |
| FIC-4 | Media | Sin navegación entre facturas: no hay anterior y siguiente, y la miga «Facturas» vuelve al listado sin los filtros con los que llegaste. | `05-ficha-enlazada-1440.png` |
| FIC-5 | Media | No hay línea de tiempo (emitida, vence, cobros, enlazada por quién y cuándo, rectificada). Esa información está repartida o no está. | `05-ficha-enlazada-1440.png` |
| FIC-6 | Baja | El PDF solo se abre en otra pestaña o se descarga; no hay vista previa ni enlace «Abrir en Holded». | `05-ficha-enlazada-1440.png` |
| FIC-7 | Baja | En el móvil, las acciones van arriba y el enlace con el proyecto, que es la tarea, queda al final de una página de 1.350 px. | `recortes/05-390.png` |

### 1.7 Contactos de Holded (`/facturacion/contactos`)

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| CON-1 | Alta | Cada contacto es una tarjeta de 130 px con un selector, «Asignar» y «Descartar». Con los 283 contactos reales, «Todos» mediría unos 40.000 px. No hay búsqueda, orden ni paginación. | `08-contactos-todos-1440.png`, `recortes/08-390.png` |
| CON-2 | Alta | En «Sin casar» no se propone nada: el contacto aparece con el selector vacío aunque haya un cliente parecido (D-248 solo propone cuando hay un único candidato y lo manda a «Por revisar»). Es el caso más frecuente y el que más trabajo da. | `06-contactos-sin-casar-1440.png` |
| CON-3 | Media | En los contactos ya casados se repite el formulario completo, con «Asignar» deshabilitado y «Descartar» a la vista: mucho ruido para no hacer nada. | `08-contactos-todos-1440.png` |
| CON-4 | Media | El selector de vistas usa botones primarios llenos: pesa más que las pestañas de la sección y parece una acción. | `06-contactos-sin-casar-1440.png` |
| CON-5 | Media | Al casar el último contacto, el estado vacío no propone el paso siguiente (por ejemplo, «Ahora enlaza las 6 facturas sin proyecto»). | `i13-contacto-casado.png` |
| CON-6 | Baja | No hay vista de cobertura («8 de 9 contactos casados, 1 factura sin cliente»). | `06-contactos-sin-casar-1440.png` |

### 1.8 Ajustes (`/facturacion/ajustes`)

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| AJU-1 | Media | Los datos del emisor, que hoy no se usan (Holded emite), ocupan la mitad izquierda y el primer pliegue. La conexión con Holded, que sí se usa, queda a la derecha. | `09-ajustes-1440.png` |
| AJU-2 | Baja | Hay dos botones de guardar en la misma página («Guardar los datos del emisor» y «Guardar el acceso»), uno de ellos deshabilitado sin explicación. | `09-ajustes-1440.png` |

### 1.9 Facturación dentro del proyecto, la bolsa y el cliente

| Código | Gravedad | Problema | Captura |
|---|---|---|---|
| FICHA-1 | Media | En la bolsa, «Vendido frente a real» repite seis tarjetas grandes. Una sola barra de vendido, facturado, cobrado y consumido diría lo mismo en una línea. | `11-proyecto-facturacion-1440.png`, `recortes/11-1440-a.png` |
| FICHA-2 | Baja | Errata de plural: «1 facturas y rectificativas de Holded enlazadas». | `recortes/11-1440-a.png` |

### 1.10 Accesibilidad

- Bien: tablas con `caption`, regiones desplazables con foco, `aria-current` en pestañas y vistas, estados con icono y texto, gráficas con tabla alternativa y el análisis axe sin fallos graves en los E2E.
- A mejorar:
  - Los botones deshabilitados en azul claro («Asignar», «Enlazar», «Guardar el acceso») no dicen por qué están deshabilitados. Hace falta un texto de ayuda o mantenerlos activos y validar al pulsar.
  - Las etiquetas cortadas de las barras no tienen `title` ni tooltip con el nombre completo (INF-4).
  - El (i) de los indicadores depende del hover; en el móvil hay que poder tocarlo.
  - El cambio de vista en Contactos y el filtrado del listado no se anuncian (`aria-live`) con el número de resultados.

---

## 2. Comparativa con las herramientas

La investigación completa, con 6-10 patrones por herramienta, está en `investigacion.md`. Aquí va lo que más le sirve a Audax. Leyenda de la última columna: dónde aplicarlo según la propuesta del apartado 3.

| Herramienta | Qué hace bien | Patrón que conviene adoptar | Dónde en Audax |
|---|---|---|---|
| Holded | Widgets con la fórmula de cada cifra; ficha en dos columnas con una barra lateral de pagos, proyecto y adjuntos; árbol de documentos con el % facturado; conciliación con sugerencias que se confirman (✓) o rechazan (✗) y, en la v2, en bloque por confianza. Es el vocabulario que ya conocen los socios. | Ficha en dos columnas con el enlace a proyecto o bolsa en la barra lateral. Mismos nombres de estados. Sugerencias con ✓ y ✗ y «Aceptar las de confianza alta». | Ficha (I4), Por revisar (I5) |
| Xero | «Te deben» en el inicio: pendiente y vencido, con clic al detalle. Antigüedad por tramos (corriente, 1-30, 31-60, 61-90, +90) por cliente. Las sugerencias dicen por qué casan (regla, memoria, coincidencia). | Tarjeta «Por cobrar» con tramos pulsables. Motivo visible en cada sugerencia. | Resumen (I1), Por revisar (I5) |
| QuickBooks Online | Barra de importes segmentada en la cabecera del listado (vencido, sin vencer, cobrado en 30 días) en la que cada tramo filtra. Estados relativos («vence en 5 días», «vencida hace 12 días»). Bandeja Para revisar, Revisados y Excluidos con «Deshacer». | Barra de importes que filtra. Estado relativo con días. Deshacer en todo lo que enlaza o casa. | Facturas (I3), Por revisar (I5) |
| FreeAgent | Gráfico mensual de lo esperado frente a lo cobrado. Las sugerencias no se proponen si hay empate («mejor sin casar que mal casado»). Se pueden aprobar desde el móvil. | Gráfico facturado frente a cobrado. Regla de no sugerir ante la duda (ya la sigue D-248). Revisar sugerencias en el móvil. | Resumen (I1), Por revisar (I5), móvil (I7) |
| Pennylane | Estados por acción («por tratar», «próximas», «en retraso», «cobradas») en vez de contables. Línea de tiempo por factura con quién hizo cada cosa. Indicador de cobertura de la automatización. | Vistas del listado por acción. Línea de tiempo en la ficha. «38 de 41 contactos casados». | Facturas (I3), Ficha (I4), Por revisar (I5) |
| Stripe Dashboard | Badge visible distinto del estado técnico, con tooltip. Cada estado tiene su tabla de acciones posibles. Pestañas por estado y chips de filtro combinables. Partidas pendientes de facturar en la ficha del cliente. | Matriz estado → acciones como contrato de diseño, que prepara la emisión. Badge relativo con explicación. | Ficha (I4, L1), Facturas (I3) |
| Harvest | Informe de horas sin facturar por cliente y proyecto con un botón por fila. Presupuesto del proyecto con aviso al pasar de un %, y reinicio mensual para igualas. | «Por facturar» como lista por cliente con lo pendiente, en vez de un formulario vacío. Avisos al 80 % y 100 %. | Por facturar (I10) |
| Productive.io | Barra de facturación del presupuesto: facturado, borradores y restante. «Listo para facturar» como métrica. Plan de facturación por hitos. Las facturas del proyecto dentro del proyecto. | Barra vendido / facturado / cobrado / consumido en la bolsa y el proyecto. «Pendiente de facturar» como cifra principal. | Vendido frente a real (I9), fichas (R8) |
| Scoro | Tabla presupuestado frente a real por servicio; «ingresos hasta la fecha» (horas × tarifa) distinto de lo facturado; un punto rojo o verde de cobro junto al importe facturado; columnas de coste solo para quien tiene permiso. | Punto de cobro junto a lo facturado. Leer distinto el exceso según el tipo de venta (en precio cerrado es margen perdido; en horas, es lo pendiente de facturar). | Vendido frente a real (I9) |
| Linear | Filtros como chips, en la URL; vistas guardadas que se fijan en la barra lateral; agrupación con subtotales; selección con X y barra de acciones abajo; vista lateral («peek») que se recorre con las flechas; Cmd+K. | Chips con «+ Filtro», vistas guardadas, vista lateral con ↑ y ↓, selección múltiple y atajos. | Facturas (I3, R1-R3), búsqueda (R4) |
| Attio | Vistas con un objetivo y un nombre; cálculos al pie de cada columna; edición en la propia celda; acciones en bloque. | Totales al pie según el filtro. Elegir el cliente o el proyecto en la propia fila. | Facturas (I3), Por revisar (I5) |

Fuentes principales (todas consultadas el 08/10/2026; la lista completa está en `investigacion.md`):
- Holded:
  - https://help.holded.com/es/articles/6877413-gestionar-tus-facturas-de-venta
  - https://help.holded.com/es/articles/6877350-anadir-pagos-a-tus-facturas-de-venta
  - https://help.holded.com/es/articles/16201260-arbol-de-conversion-relacion-entre-documentos
  - https://help.holded.com/es/articles/6984213-como-funcionan-los-recordatorios-de-facturas
  - https://help.holded.com/en/articles/7916935-the-boards
  - https://help.holded.com/en/articles/6948805
- Xero:
  - https://www.xero.com/accounting-software/bank-reconciliation/
  - https://www.xero.com/accounting-software/send-invoices/
- QuickBooks Online:
  - https://quickbooks.intuit.com/learn-support/en-us/help-article/accounts-receivable-reports/run-accounts-receivable-aging-report/L4N7PC2hg_US_en_US
  - https://quickbooks.intuit.com/learn-support/en-ca/help-article/invoicing/send-invoice-reminders-automatically-manually/L84cQjpxo_CA_en_CA
  - https://community.intuit.com/articles/1779794-how-to-add-and-match-downloaded-banking-transactions
- FreeAgent:
  - https://support.freeagent.com/hc/en-gb/articles/115001218610
  - https://support.freeagent.com/hc/en-gb/articles/115001586004
  - https://www.freeagent.com/features/projects/
- Pennylane: https://www.pennylane.com/fr/blog/produit/automatisez-gestion-ventes
- Stripe:
  - https://docs.stripe.com/invoicing/dashboard/manage-invoices
  - https://docs.stripe.com/invoicing/overview
  - https://docs.stripe.com/invoicing/automatic-collection
- Harvest:
  - https://www.getharvest.com/blog/2011/05/the-uninvoiced-report-for-time-expenses
  - https://www.getharvest.com/blog/2018/06/more-powerful-budgeting-to-keep-your-monthly-retainers-on-track
- Productive:
  - https://help.productive.io/en/articles/9246352-budget-navigation
  - https://help.productive.io/en/articles/2179656-accessing-your-invoices
- Scoro:
  - https://support.scoro.com/hc/en-us/articles/13750171945101-Step-8-Track-project-progress-and-results
  - https://support.scoro.com/hc/en-us/articles/12695429082765-How-to-track-revenue-in-Scoro
- Linear:
  - https://linear.app/docs/filters
  - https://linear.app/docs/custom-views
  - https://linear.app/docs/select-issues
  - https://linear.app/docs/peek
- Attio: https://attio.com/help/reference/managing-your-data/views/filter-and-sort-views

Fiabilidad: cuatro patrones vienen de fuentes de terceros o de conocimiento previo y conviene verlos en una cuenta real antes de usarlos como argumento ante terceros: la barra de importes actual de QuickBooks (la fuente es de 2018), las vistas guardadas de Stripe, la línea de tiempo de la ficha de Stripe y la barra de presupuesto de Harvest. Como patrones de diseño siguen siendo válidos.

---

## 3. Propuesta

### 3.1 Principios

1. Primero lo que hay que hacer, luego el análisis. La portada responde a tres preguntas: qué me deben, qué falta por facturar y qué falta por revisar.
2. Una cifra, una base. Todo lo facturado sin IVA; todo lo cobrado y pendiente con IVA, agrupado y rotulado una vez por grupo, no en cada tarjeta.
3. Cada cifra es un atajo: pulsarla abre el listado ya filtrado.
4. Listas densas y rápidas, para dos personas expertas que las usan a diario: orden, chips de filtro, totales al pie, vista lateral y teclado.
5. Una sola bandeja para el trabajo de emparejar (contactos y facturas), con sugerencias explicadas, deshacer y acciones en bloque.
6. Preparar la emisión sin construirla: «Por facturar» y la ficha ya tienen el hueco de «Crear borrador», «Registrar cobro» y la línea de tiempo.

### 3.2 Nueva arquitectura de navegación

Barra lateral, sección Facturación, en este orden (se quitan las pestañas `BillingTabs`):

| Orden | Entrada | URL | Nombre de ruta | Qué es | Quién la ve |
|---|---|---|---|---|---|
| 1 | Resumen (portada) | `/facturacion` | `billing.index` | Qué te deben, qué falta por facturar, qué falta por revisar y cómo va el año | `view-billing` |
| 2 | Facturas | `/facturacion/facturas` | `billing.invoices.index` | Listado con vistas: Todas · Por cobrar · Vencidas · Sin proyecto · Borradores · Rectificativas. Ficha en `/facturacion/facturas/{id}` y vista lateral | `view-billing` |
| 3 | Por facturar | `/facturacion/por-facturar` | `billing.unbilled` | Lo que se ha trabajado o vendido y aún no se ha facturado, por cliente: horas aprobadas sin facturar, bolsas y fees sin factura. Dentro, la exportación por cliente de hoy | `exportBillingHours` (en horas); importes con `view-billing` (D-402) |
| 4 | Vendido frente a real | `/facturacion/vendido-frente-a-real` | `billing.sold-vs-actual` | Igual que hoy, con la tabla rehecha | `view-sold-vs-actual` (responsables en horas) |
| 5 | Por revisar (con contador) | `/facturacion/por-revisar` | `billing.review` | Bandeja única: contactos sin casar o por confirmar y facturas sin proyecto | `view-billing` |
| 6 | Ventas (antes «Informe») | `/facturacion/ventas` | `billing.sales` | El análisis actual: por mes, servicio y cliente, con exportación | `view-billing` |
| — | Ajustes (icono de engranaje en la cabecera de la sección, no en la lista) | `/facturacion/ajustes` | `billing.settings` | Conexión con Holded, directorio de contactos de Holded (Todos y Descartados), quién ve Facturación y datos del emisor | `view-billing` |

Reglas:
- `/facturacion` es la portada para quien tiene `view-billing`. A quien solo ve «Vendido frente a real» lo sigue llevando allí, como hoy (D-401), y su sección tiene una o dos entradas (Vendido frente a real y, si tiene el permiso, Por facturar en horas).
- Redirecciones 301 con su query, como en D-401: `/facturacion/informe` → `/facturacion/ventas`, `/facturacion/horas-para-facturar` → `/facturacion/por-facturar` (con `?cliente=` abre directamente el detalle de hoy) y `/facturacion/contactos` → `/facturacion/por-revisar?tipo=contactos` (las vistas Todos y Descartados → Ajustes, Contactos de Holded). Los envíos programados guardan el tipo de informe y no la URL, así que no se rompen.
- Huecos reservados para más adelante, sin entrada visible hasta que existan: Presupuestos (F3) entre Facturas y Por facturar, y Recurrentes como vista de Facturas (F4/F5). «Por cobrar» puede pasar a pantalla propia cuando haya cobros y recordatorios (F5); hasta entonces es una vista de Facturas.
- En el móvil, la cabecera de cada pantalla lleva un selector compacto de la sección (sustituye a las pestañas cortadas), porque la barra lateral está escondida en el cajón.
- Migas: siempre «Facturación › Pantalla», y en la ficha «Facturación › Facturas › F260314», con la miga «Facturas» devolviendo al listado con los filtros de los que vienes.

### 3.3 Mejoras priorizadas

Esfuerzo: S (hasta 2 días), M (3-6 días), L (más de una semana). Riesgo: lo que se puede romper o lo que necesita una decisión.

#### Resumen

| Nivel | Código | Mejora | Esfuerzo | Riesgo | Estado |
|---|---|---|---|---|---|
| Imprescindible | I1 | Portada «Resumen» orientada a la acción | M | Bajo | Hecho, tanda 2 (D-411) |
| Imprescindible | I2 | Una sola navegación, títulos y migas coherentes | S | Bajo | Hecho, tanda 1 (D-405) |
| Imprescindible | I3 | Listado de facturas rápido: vistas, barra de importes, chips, orden y totales | L | Medio | Hecho, tanda 1 (D-406 y D-407) |
| Imprescindible | I4 | Ficha de factura con cabecera útil, enlace en un solo bloque y línea de tiempo | M | Bajo | Hecho, tanda 1 (D-408) |
| Imprescindible | I5 | Bandeja «Por revisar» para contactos y facturas | M | Medio | Hecho, tanda 2 (D-413 y D-414) |
| Imprescindible | I6 | Una base por grupo de cifras y «Pendiente de facturar» como cifra principal | S | Bajo | Hecho, tanda 1 (D-410) |
| Imprescindible | I7 | Móvil: listas en tarjetas y filtros en una hoja | M | Bajo | Hecho, tanda 2 (D-415) |
| Imprescindible | I8 | Errores visibles: desbordes, columnas cortadas, plural, barra lateral | S | Bajo | Hecho, tanda 1 (D-410) |
| Imprescindible | I9 | «Vendido frente a real» sin scroll lateral y con la lectura correcta por tipo de venta | M | Medio | Hecho, tanda 1 (tabla, D-410) y tanda 2 (por horas, D-416) |
| Imprescindible | I10 | «Por facturar» con la lista de clientes pendientes | M | Bajo | Hecho, tanda 2 (D-412) |
| Muy recomendable | R1 | Vista lateral con ↑ y ↓ para revisar y enlazar en serie | M | Bajo | Pendiente |
| Muy recomendable | R2 | Vistas guardadas con nombre, fijables en la barra lateral | M | Bajo | Pendiente |
| Muy recomendable | R3 | Selección múltiple con barra de acciones | M | Medio | Pendiente |
| Muy recomendable | R4 | Facturas en la búsqueda global (Ctrl K) | S | Medio | Pendiente |
| Muy recomendable | R5 | «Ventas» (el informe actual) más compacto y con cobros | M | Bajo | Pendiente |
| Muy recomendable | R6 | Estado de la sincronización en todas las pantallas | S | Bajo | Hecho, tanda 1 (D-409) |
| Muy recomendable | R7 | Estados vacíos con siguiente paso y ayuda en contexto | S | Bajo | Hecho, tanda 2 (D-415) |
| Muy recomendable | R8 | Barra de facturación compacta en bolsa, proyecto y cliente | S | Bajo | Pendiente |
| Muy recomendable | R9 | Atajos de teclado | S | Bajo | Pendiente |
| Más adelante | L1 | Matriz estado → acciones y menú ⋯ por fila (emisión) | M | Medio | Pendiente |
| Más adelante | L2 | «Crear borrador» desde Por facturar (F4) | L | Alto | Pendiente |
| Más adelante | L3 | Cobros: registrar, recordatorios por escalones y antigüedad por cliente | L | Medio | Pendiente |
| Más adelante | L4 | Presupuestos y recurrentes en la misma arquitectura | L | Medio | Pendiente |
| Más adelante | L5 | Cadena de documentos con % facturado | M | Bajo | Pendiente |
| Más adelante | L6 | Conciliación bancaria reutilizando la bandeja «Por revisar» | L | Alto | Pendiente |

#### Imprescindible

I1 · Portada «Resumen» orientada a la acción
- Problema: T-1, T-6, INF-5 e INF-6. No hay un sitio que diga qué hacer hoy; lo vencido está al final de un informe.
- Solución:
  - Bloque «Requiere atención» arriba, con una línea por tipo y su botón: «5 facturas vencidas · 11.900 € → Ver», «6 facturas sin proyecto → Revisar», «1 contacto sin cliente → Casar», «3 bolsas por encima del 85 % → Ver». Si no hay nada, un estado vacío grande y positivo («Todo al día»), donde se permite el degradado de marca.
  - Cuatro cifras pulsables en dos grupos rotulados una sola vez: «Facturación del año (sin IVA)»: Facturado y su comparación con el año anterior; «Cobros (con IVA)»: Pendiente de cobro y Vencido. Cada una abre Facturas con el filtro correspondiente.
  - «Por cobrar»: barra horizontal por tramos (en plazo, 1-30, 31-60, 61-90, +90) en la que cada tramo filtra el listado, más los cinco clientes que más deben.
  - «Facturado y cobrado por mes»: columnas de lo facturado y línea de lo cobrado, con el año anterior opcional.
  - «Por facturar»: total pendiente y los tres clientes con más horas sin facturar → Por facturar.
- Pantallas: nueva `/facturacion`; reutiliza datos de `InvoicingReport` y `SoldVsActual`.
- Esfuerzo: M. Riesgo: bajo; las cifras ya se calculan. Hay que vigilar el tiempo de carga (caché como el resto de informes) y respetar D-245 y D-247.

I2 · Una sola navegación, títulos y migas coherentes
- Problema: T-2, T-3 y T-10.
- Solución: quitar `BillingTabs` en escritorio; la barra lateral pasa a las seis entradas de 3.2 con el contador de «Por revisar». H1 = nombre de la pantalla (nunca «Facturación» a secas). Quitar la palabra destacada de «Horas para facturar» o usarla igual en toda la sección. Migas «Facturación › …» siempre. Revisar la altura de la barra lateral para que el pie no tape la última entrada (desplazamiento propio de la lista).
- Pantallas: todas las de Facturación y `app-sidebar.tsx`.
- Esfuerzo: S. Riesgo: bajo; los E2E que buscan la navegación «Secciones de facturación» hay que adaptarlos.

I3 · Listado de facturas rápido
- Problema: FAC-1 a FAC-7 y FAC-10.
- Solución:
  - Pestañas de vista con contador: Todas · Por cobrar · Vencidas · Sin proyecto · Borradores · Rectificativas. Las anuladas, dentro de Todas y atenuadas.
  - Barra de importes segmentada encima de la tabla: Vencido · Por vencer · Cobrado (en el periodo). Cada tramo filtra, como en QuickBooks. Sustituye a las cuatro tarjetas.
  - Una sola línea de filtros: buscador que filtra al escribir (con 300 ms de espera), periodo (por defecto, el año en curso) y «+ Filtro» que añade chips (cliente, proyecto, bolsa, servicio, importe, documento). Los chips se quitan con su ×. Todo en la URL.
  - Tabla de altura fija por fila (40 px): número, cliente, proyecto o bolsa, fecha, vencimiento, base, total, pendiente y estado. El estado relativo («Vence en 5 d», «Vencida hace 8 d», «Cobro parcial 40 %») sustituye a «Pendiente» o «Vencida»; «Cobrada» se muestra en gris, sin color, para que destaque lo que no está cobrado.
  - La sugerencia de enlace va dentro de la celda «Proyecto» como un botón compacto «MIR-FE1 ✓» con su motivo en el tooltip, sin duplicar la altura de la fila.
  - Orden por cualquier columna; totales al pie de lo filtrado (base, total y pendiente); paginación con el número de resultados y «Mostrar 50/100».
  - Toda la fila es pulsable y abre la vista lateral (R1) o la ficha; Cmd + clic abre en otra pestaña.
  - Al enlazar: aviso con «Deshacer» durante 8 segundos.
- Pantallas: `/facturacion/facturas`, `invoice-table.tsx` y `HoldedInvoiceController@index` (orden, vistas y totales).
- Esfuerzo: L. Riesgo: medio; cambia el contrato de filtros (mantener los parámetros actuales `estado`, `tipo`, `cliente`, `enlace`, `desde` y `hasta` como alias) y hay que adaptar `billing.spec.ts` (`invoice-table`, `accept-suggestion`).

I4 · Ficha de factura
- Problema: FIC-1 a FIC-6.
- Solución:
  - Cabecera: «Factura F260314 · Construcciones Lamas», badge de estado relativo y, en grande, «Pendiente 5.608,35 € de 5.608,35 €» con una barra del % cobrado. Botones: «Ver PDF» (vista previa en un panel o diálogo), «Descargar», «Abrir en Holded» y ⋯. Flechas de anterior y siguiente dentro de la lista de la que vienes.
  - Columna derecha «Proyecto y bolsa» en un solo bloque: si está enlazada, el enlace con su método y «Cambiar»; si no, la sugerencia principal con «Enlazar» y debajo «Elegir otro proyecto…», que abre el buscador con todos los proyectos activos (los del cliente primero, agrupados).
  - Columna derecha «Cliente»: el cliente de Audax y el contacto de Holded, con «Cambiar» si no está casado.
  - Línea de tiempo en la columna principal, bajo las líneas: emitida, vencimiento, cada cobro, rectificativa, enlazada por quién y cuándo y la última lectura de Holded. Todo sale de datos que ya se sincronizan.
  - Hechos secundarios (moneda, etiquetas, notas) en un bloque plegado «Más datos».
- Pantallas: `/facturacion/facturas/{id}`.
- Esfuerzo: M. Riesgo: bajo; la línea de tiempo es solo lectura. Mantener `invoice-links`, `invoice-pdf` e `invoice-link-project` en los E2E.

I5 · Bandeja «Por revisar»
- Problema: CON-1 a CON-6, FAC-8 y FIC-3.
- Solución:
  - Una pantalla con dos pestañas y contador: «Contactos» (sin casar y por confirmar) y «Facturas sin proyecto».
  - Tabla densa, una fila por elemento, ordenada por importe: contacto o factura, NIF, importe facturado, propuesta (cliente o proyecto), motivo de la propuesta («mismo NIF», «nombre parecido: Montó ⊂ PINTURAS MONTÓ», «mismo cliente y servicio, fecha cercana») y su confianza (alta o media), y dos acciones: ✓ Aceptar y ✗ Rechazar. Con el teclado: Intro acepta, Supr descarta y la flecha baja pasa a la siguiente.
  - Si no hay propuesta (empate o ningún candidato, como hoy en D-248), la celda es un buscador en la propia fila (como en Attio) con el cliente o proyecto a elegir.
  - Selección múltiple y «Aceptar las de confianza alta (N)» en la cabecera.
  - Cobertura arriba: «8 de 9 contactos casados · 25 de 31 facturas con proyecto».
  - Todo lo hecho va a «Hechos hoy» con «Deshacer».
  - El directorio completo de contactos (Todos y Descartados) se va a Ajustes, como tabla con búsqueda; casar a mano también se puede desde allí.
- Pantallas: nueva `/facturacion/por-revisar`; `/facturacion/contactos` pasa a Ajustes; el aviso amarillo del listado se sustituye por la vista «Sin proyecto».
- Esfuerzo: M. Riesgo: medio; hay que dar a las sugerencias de contactos un candidato aunque no casen solas (un «mejor candidato» con confianza media). Es un cambio de D-248 que se registra como decisión nueva: casar solo sigue exigiendo un único candidato, pero se propone el mejor para confirmarlo a mano.

I6 · Una base por grupo y «Pendiente de facturar» como cifra principal
- Problema: T-5, INF-1, VFR-2 y VFR-3.
- Solución: agrupar las cifras en «Facturación (sin IVA)» y «Cobros (con IVA)», rotulados una vez. Máximo cuatro cifras principales por pantalla; las demás, como texto secundario o en «Ver más». «Pendiente de facturar» sube a cifra principal en Resumen y en Vendido frente a real. La desviación de horas se escribe «46 h 15 min por encima» o «1.493 h por debajo», no «−1493:45». Una sola forma de escribir la comparación con el año anterior («+120 % frente a 2025»).
- Pantallas: Resumen, Ventas, Facturas, Vendido frente a real y la pestaña de la bolsa.
- Esfuerzo: S. Riesgo: bajo; afecta a textos de `lang/ui/billing.json` y a `format.ts` (un formateador de duraciones con signo, con su test en Vitest).

I7 · Móvil
- Problema: T-4, T-9 y FIC-7.
- Solución: debajo de 768 px, las facturas son una lista de tarjetas de dos líneas (número y cliente; estado relativo y pendiente); los filtros van en una hoja inferior tras un botón «Filtros (2)»; las vistas, en un carrusel de pastillas; las cifras, en dos columnas compactas. En la ficha, la barra de acciones queda fija abajo con «Enlazar» y «PDF». La bandeja «Por revisar» se puede vaciar desde el móvil (aceptar o rechazar con un toque).
- Pantallas: Facturas, ficha, Por revisar, Resumen y Ventas.
- Esfuerzo: M. Riesgo: bajo; ya hay E2E de «sin desplazamiento lateral» que se pueden extender.

I8 · Errores visibles
- Problema: FAC-2, VFR-1, FICHA-2, INF-4 y T-10.
- Solución: la línea de filtros no se desborda (I3); la tabla de Vendido frente a real cabe a 1440 (I9); plural correcto («1 factura»); nombres completos en tooltip en las barras y etiquetas más anchas en el ranking; la barra lateral no tapa la última entrada.
- Esfuerzo: S. Riesgo: bajo. Se puede hacer ya, antes que el resto.

I9 · Vendido frente a real
- Problema: VFR-1 a VFR-5.
- Solución:
  - Interruptor «Horas | Importes» encima de la tabla (solo con `view-billing`): en Horas, vendidas, reales, pendientes de aprobar, desviación y estado; en Importes, vendido, facturado (con un punto de cobro verde o rojo, como en Scoro), cobrado, pendiente de facturar y margen. Así cabe sin desplazarse.
  - Lectura por tipo de venta: en «Por horas», en vez de «Pasado 1.556 %», la fila dice «Pendiente de facturar: N h» y no entra en la escala de la gráfica de bala (va aparte o con su propio indicador). En «Precio cerrado», el exceso se rotula «margen perdido».
  - Cada fila enlaza también a sus facturas (Facturas filtradas por la unidad).
  - Quitar el botón «Facturas» de la cabecera.
- Esfuerzo: M. Riesgo: medio; cambia la lectura de una regla (D-390) y hay que registrarlo como decisión. Los casos compartidos `tests/fixtures/billing/sold-vs-actual-status.json` necesitan un caso nuevo para «por horas».

I10 · Por facturar
- Problema: HPF-1 y HPF-2.
- Solución: la pantalla abre con una tabla por cliente: horas aprobadas sin facturar, su valor (con `view-billing`), horas pendientes de aprobar, bolsas o fees del periodo sin factura y la fecha del último trabajo. Cada fila abre el detalle de hoy (resumen por proyecto y bolsa y exportación a Excel o CSV). Es la bandeja del §4.5 del PLAN-FASE-12 sin el botón «Crear factura», que llegará en F4.
- Pantallas: `/facturacion/por-facturar` (antes `/facturacion/horas-para-facturar`).
- Esfuerzo: M. Riesgo: bajo; los cálculos existen en `BillingReport` y `SoldVsActual`. Sin el módulo `billing` (D-402) se sigue viendo, en horas.

#### Muy recomendable

R1 · Vista lateral para revisar y enlazar en serie
- Problema: FAC-7 y FIC-4.
- Solución: al pulsar una fila de Facturas o Por revisar se abre un panel a la derecha (`ui/sheet`, 480 px) con la cabecera de la ficha, el bloque «Proyecto y bolsa» y el PDF en miniatura. ↑ y ↓ pasan a la siguiente sin cerrar; Esc cierra; «Abrir la ficha» lleva a la página completa. Tras enlazar, salta a la siguiente sin enlazar.
- Esfuerzo: M. Riesgo: bajo; foco atrapado y devuelto a la fila al cerrar.

R2 · Vistas guardadas
- Problema: FAC-3 (más allá de las vistas fijas).
- Solución: «Guardar vista» aparece cuando hay chips; la vista tiene nombre y se puede fijar en la barra lateral bajo Facturas (por ejemplo, «Hoteles Mirador · 2026» o «Rectificativas del trimestre»). Se guarda por persona, como las secciones plegadas del menú.
- Esfuerzo: M. Riesgo: bajo.

R3 · Selección múltiple
- Problema: FAC-9.
- Solución: casilla por fila, X con el teclado y Mayús para rango. Barra abajo con «Enlazar con…», «Aceptar sugerencias», «Descargar PDF» (ZIP) y «Exportar selección».
- Esfuerzo: M. Riesgo: medio; enlazar en bloque necesita un endpoint nuevo y su test de permisos.

R4 · Facturas en la búsqueda global
- Problema: T-8.
- Solución: nueva fuente en `app/Search/Sources` para facturas y rectificativas (número, cliente y contacto), solo para quien tiene `view-billing`, sin excluidos (D-245) y sin importes en el resultado para nadie más.
- Esfuerzo: S. Riesgo: medio por permisos: hace falta su test de aislamiento.

R5 · «Ventas» más compacta
- Problema: INF-1 a INF-6 y T-4.
- Solución: los filtros en una línea (periodo, clientes, y servicio como selector múltiple con chips, no 12 botones); cuatro cifras principales; añadir la línea de lo cobrado al gráfico mensual; antigüedad como tabla por cliente con celdas pulsables (estilo Xero) en vez de columnas; «Hoy» pasa a «Este año», «Este trimestre», etc.
- Esfuerzo: M. Riesgo: bajo; `billing.spec.ts` comprueba los botones de servicio (`Fees` con `aria-pressed`).

R6 · Estado de la sincronización
- Problema: T-7.
- Solución: en la cabecera de cada pantalla, «Holded: hace 3 h» con un punto verde, ámbar (más de 26 h) o rojo (error), y «Sincronizar» para quien tiene `sync-holded`. El historial sigue en Ajustes.
- Esfuerzo: S. Riesgo: bajo.

R7 · Estados vacíos y ayuda
- Problema: CON-5, HPF-2 y T-11.
- Solución: cada estado vacío propone el paso siguiente («Ahora enlaza las 6 facturas sin proyecto»). Tooltips con el significado de cada estado relativo y de cada motivo de sugerencia. Un enlace «¿Cómo se calcula?» en cada grupo de cifras que abre la ayuda del módulo.
- Esfuerzo: S. Riesgo: bajo.

R8 · Barra de facturación en las fichas
- Problema: FICHA-1.
- Solución: en la bolsa, el proyecto y el cliente, una barra horizontal (como en Productive) con vendido, facturado, cobrado y consumido en horas, y las cifras debajo en una línea. Las seis tarjetas pasan a «Ver detalle».
- Esfuerzo: S. Riesgo: bajo.

R9 · Atajos de teclado
- Solución: `/` busca, J y K o ↑ y ↓ recorren, Espacio abre la vista lateral, E enlaza, X selecciona, G luego F va a Facturas, `?` muestra la lista. Sin conflicto con los atajos globales (Ctrl K).
- Esfuerzo: S. Riesgo: bajo; hay que comprobar que no interfieren con los campos de texto.

#### Más adelante (prepara la Fase 12 sin bloquearla)

L1 · Matriz estado → acciones. Una tabla en el código (y en la documentación) con lo que permite cada estado: hoy Ver PDF, Abrir en Holded y Enlazar; con la emisión, Editar (solo borrador), Aprobar, Duplicar, Anular, Rectificar (D-244) y Registrar cobro. La cabecera de la ficha y el menú ⋯ por fila la leen. Esfuerzo M, riesgo medio (depende de `manage-billing`).

L2 · «Crear borrador» en Por facturar (F4). La fila de cada cliente gana el botón; varias filas de un cliente se juntan en una factura. Esfuerzo L, riesgo alto (bloqueo de horas y emisión en Holded).

L3 · Cobros. «Por cobrar» pasa a pantalla propia con la antigüedad por cliente, «Registrar cobro», recordatorios por escalones (antes y después del vencimiento, con importe mínimo y plantilla) que se anotan en la línea de tiempo y se paran si entra un cobro. Esfuerzo L, riesgo medio (envío de correos: SMTP pendiente del propietario).

L4 · Presupuestos y recurrentes. Presupuestos entra como entrada de la sección (entre Facturas y Por facturar) con el mismo listado, chips y vista lateral; Recurrentes como vista de Facturas. Esfuerzo L, riesgo medio.

L5 · Cadena de documentos. En la ficha, presupuesto → factura → rectificativa con el % facturado, como el árbol de Holded pero en una línea. Esfuerzo M, riesgo bajo.

L6 · Conciliación bancaria (P6). Reutiliza la bandeja «Por revisar» con una tercera pestaña «Movimientos» y el mismo patrón de propuesta, motivo, ✓ y ✗. Esfuerzo L, riesgo alto.

### 3.4 Bocetos de las pantallas clave

Las cifras de los bocetos salen de los datos de ejemplo o son ilustrativas (por ejemplo, la fila de PINTURAS MONTÓ); sirven para ver la jerarquía, no para cuadrar.

#### Resumen (`/facturacion`), 1440 px

```
┌ Facturación › Resumen ─────────────────────────────── Holded: hace 3 h ● Sincronizar ─ ⚙ ┐
│ Resumen                                                       Periodo: Este año ▾         │
│                                                                                           │
│ REQUIERE ATENCIÓN                                                                         │
│ ┌───────────────────────────────────────────────────────────────────────────────────────┐ │
│ │ ⓘ 5 facturas vencidas · 11.900 € (la más antigua, 219 días)            Ver vencidas → │ │
│ │ ⛓ 6 facturas sin proyecto ni bolsa · no cuentan en Vendido frente a real   Revisar → │ │
│ │ 👤 1 contacto de Holded sin cliente · 2.400 €                                Casar → │ │
│ │ ◔ 3 bolsas por encima del 85 %                                                Ver → │ │
│ └───────────────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                           │
│ FACTURACIÓN (SIN IVA)                       │ COBROS (CON IVA)                            │
│ ┌──────────────────┐ ┌──────────────────┐   │ ┌──────────────────┐ ┌──────────────────┐   │
│ │ Facturado        │ │ Por facturar     │   │ │ Pendiente        │ │ Vencido          │   │
│ │ 399.791 €        │ │ 81.300 €         │   │ │ 11.900 €         │ │ 11.900 €         │   │
│ │ +120 % vs 2025 → │ │ 4 clientes     → │   │ │ 6 facturas     → │ │ 5 facturas     → │   │
│ └──────────────────┘ └──────────────────┘   │ └──────────────────┘ └──────────────────┘   │
│                                                                                           │
│ Facturado y cobrado por mes                              ▢ Año anterior   Ver como tabla │
│  120k ┤        ▇                                                                         │
│   60k ┤ ▇    ▇ ▇ ▇ ▇──•──•                         ▇ facturado   • cobrado              │
│     0 ┼─ene─feb─mar─abr─may─jun─jul─ago─sep─oct─nov─dic                                   │
│                                                                                           │
│ Por cobrar                                     │ Por facturar                             │
│ [██████████ en plazo ][▓▓ 1-30 ][  ][  ][▒ +90] │ Construcciones Lamas   1.202 h  72.135 € │
│  Grupo Ferrán      3.388 €  ·  2 vencidas      │ Fundación Aula Viva       40 h   2.025 € │
│  Construcciones L. 5.608 €  ·  1 vencida       │ Hoteles Mirador           12 h     720 € │
│  Estudio Nébula    1.452 €  ·  1 vencida       │                    Ver todo Por facturar → │
└───────────────────────────────────────────────────────────────────────────────────────────┘
```
Notas: «Requiere atención» desaparece si está vacío y deja un estado vacío grande «Todo al día» (degradado de marca permitido). Los iconos son de lucide, no emojis; aquí solo indican la posición. Las cifras en DM Sans 400, sin negritas; los rótulos de grupo en versalitas o `text-muted-foreground`.

#### Facturas con la vista lateral (`/facturacion/facturas?vista=sin-proyecto`), 1440 px

```
┌ Facturación › Facturas ───────────────────────────────────────── Holded: hace 3 h ● ─────┐
│ Facturas                                                         Exportar ▾   (+ Nueva)* │
│ Todas 31 │ Por cobrar 6 │ Vencidas 5 │ [Sin proyecto 6] │ Borradores 2 │ Rectificativas 1  │
│ ┌────────────────────────────────────┬───────────────┬──────────────────────────────────┐ │
│ │ Vencido 11.900 €                   │ Por vencer 0 €│ Cobrado en el periodo 781.686 €  │ │
│ └────────────────────────────────────┴───────────────┴──────────────────────────────────┘ │
│ 🔍 Número, cliente o concepto   Este año ▾   [Cliente: Hoteles Mirador ×]  + Filtro  Guardar vista │
│ ┌──────────────────────────────────────────────────────────┐┌─ F260316 ──────────── ↑ ↓ × ┐│
│ │☐ Número   Cliente           Proyecto        Pendiente Est.││ Hoteles Mirador              ││
│ │☐ F260316  Hoteles Mirador   MIR-FE1 ✓ ?     1.452 € Venc.8d││ Pendiente 1.452 € de 1.452 € ││
│ │☐ F260315  Grupo Ferrán      FER-FE1 ✓ ?     1.694 € Venc.7d││ ░░░░░░░░░░░░░░░ 0 % cobrado  ││
│ │☐ F260314  Construcciones L. LAM-INT ✓ ?     5.608 € Venc.8d││                              ││
│ │☐ F260313  Estudio Nébula ⚠  Elegir…  ▾      1.452 € Venc.29││ PROYECTO Y BOLSA             ││
│ │☐ F260312  Hoteles Mirador   MIR-FE1 ✓ ?         0 € Cobr.  ││ Propuesta: MIR-FE1 Redes     ││
│ │☐ F260311  Grupo Ferrán      FER-FE1 ✓ ?         0 € Cobr.  ││ sociales · fee mensual       ││
│ │                                                            ││ Motivo: mismo cliente, fee,  ││
│ │ 6 facturas    Base 9.235 €   Total 11.175 €   Pend. 10.206 €││ fecha del mes                ││
│ └──────────────────────────────────────────────────────────┘││ [ Enlazar (E) ] Elegir otro… ││
│  ◀ 1 de 1 ▶                                     Mostrar 50 ▾ ││ LÍNEAS · PDF · HISTORIA      ││
│                                                              │└──────────────────────────────┘│
│ ┌ 2 seleccionadas ─ Enlazar con… ─ Aceptar sugerencias ─ PDF ─ Exportar ─ × ┐               │
└───────────────────────────────────────────────────────────────────────────────────────────┘
* «+ Nueva» solo cuando Audax emita (L1/L2).
```
Notas: «✓ ?» es la sugerencia compacta: el check acepta, el «?» muestra el motivo. Las cobradas, en gris. La fila activa lleva el borde izquierdo en `--primary`. Al enlazar, la vista lateral pasa a la siguiente sin proyecto y el aviso ofrece «Deshacer».

#### Por revisar (`/facturacion/por-revisar`), 1440 px

```
┌ Facturación › Por revisar ──────────────────────────────────────────────────────────────┐
│ Por revisar                                                                              │
│ [Contactos 1] │ Facturas sin proyecto 6                                                  │
│ Cobertura: 8 de 9 contactos casados · 1 factura sin cliente (2.400 €)                    │
│ 🔍 Buscar contacto o NIF         Ordenar: Importe ▾          Aceptar las de confianza alta (0) │
│ ┌───────────────────────────────────────────────────────────────────────────────────────┐ │
│ │☐ Contacto de Holded        Facturado  Propuesta                Motivo          Acción  │ │
│ │☐ Estudio Nébula, S.L.      2.400 €   [Buscar cliente…   ▾]    Sin candidato   ✓  ✗   │ │
│ │  NIF B46000999 · Valencia                                                              │ │
│ │☐ PINTURAS MONTÓ, S.A.U.   18.300 €   Montó                     Nombre parecido ✓  ✗   │ │
│ │  Sin NIF · Castellón                                           confianza media         │ │
│ └───────────────────────────────────────────────────────────────────────────────────────┘ │
│ Hechos hoy (2) ▾   Librería El Faro ← Estudio Nébula · a mano · Deshacer                 │
│                                                                                          │
│ Con el teclado: ↑ ↓ moverse · Intro aceptar · Supr descartar · / buscar                  │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```
Notas: «Montó» es el ejemplo real de D-248. Si la propuesta no convence, el buscador de la fila sustituye a la propuesta. «✗» descarta el contacto (equivale al «Descartar» de hoy) y se puede deshacer.

#### Facturas en el móvil (390 px)

```
┌──────────────────────────────┐
│ ☰  Facturas ▾        🔍  ⋯   │
│ (Por cobrar 6)(Vencidas 5)(…)│
│ Vencido 11.900 € · Por vencer│
│ [Filtros (1)]   Este año ▾   │
│ ┌──────────────────────────┐ │
│ │ F260316  Hoteles Mirador │ │
│ │ Vencida hace 8 d 1.452 € │ │
│ │ ⛓ MIR-FE1 ✓              │ │
│ └──────────────────────────┘ │
│ ┌──────────────────────────┐ │
│ │ F260314  Construcciones L│ │
│ │ Vencida hace 8 d 5.608 € │ │
│ └──────────────────────────┘ │
└──────────────────────────────┘
```

### 3.5 Orden de trabajo sugerido

1. Primer bloque, una semana: I8, I2, I6 (errores y coherencia; cambian poco y se notan mucho).
2. Segundo bloque: I3 e I4 (el listado y la ficha), con R6.
3. Tercer bloque: I1, I5 e I10 (las tres pantallas nuevas), con R7.
4. Cuarto bloque: I9, I7, R1 y R3.
5. Después: R2, R4, R5, R8 y R9; la fase de emisión retoma L1-L6.

Decisiones nuevas que habría que registrar en `docs/DECISIONES.md`: la nueva arquitectura y las redirecciones (cambia D-393 y D-401), el mejor candidato propuesto en contactos (amplía D-248), la lectura de «Vendido frente a real» por tipo de venta (amplía D-390) y el periodo por defecto del listado de facturas. Para el propietario queda una sola pregunta abierta: si «Rechazar» en un contacto debe poder ofrecer «Crear el cliente en Audax con estos datos», lo que cambiaría la regla de D-387 de no crear nunca un cliente desde Holded. La propuesta no lo incluye.

---

## 4. Respeto a las normas del proyecto

### 4.1 Tema, tipografía y textos (CLAUDE.md)

- Colores: solo los tokens de `resources/css/app.css`, claros y oscuros: `--primary` para la fila activa y la vista seleccionada; `--success`, `--warning` y `--danger` (con sus `-soft`) para los estados de cobro y los motivos de atención; `--muted-foreground` para «Cobrada» y los textos secundarios. Ningún color nuevo; el test de contraste AA del tema sigue valiendo.
- Tipografía: DM Sans 400 y 500. Los rótulos de grupo («Facturación (sin IVA)», «Cobros (con IVA)») van en 500 o en `text-muted-foreground` y mayúsculas pequeñas, nunca en negrita. Las cifras grandes, en 400 como hoy.
- Radio de 3 px (`--radius`) en tarjetas, chips, barra de importes, vista lateral y barra de selección.
- Degradado de marca: solo en el estado vacío grande de la portada («Todo al día»). Nunca en la barra de importes ni en las cifras.
- Gráficas: `--chart-1..6` en orden fijo (D-012). Facturado `--chart-1`, cobrado `--chart-2`, año anterior `--chart-3` (como hoy en `invoicing-month-chart.tsx`). La barra «Por cobrar» usa la rampa secuencial de `--chart-1` que ya usa la antigüedad, y los tramos vencidos pueden llevar el rojo de estado tras un hueco de 2 px, el mismo recurso que el exceso de D-390. Cada gráfica conserva «Ver como tabla».
- Textos: todo a través de `t()` en `lang/ui/billing.json` (y `__()` en `lang/es/billing.php` para los mensajes del servidor). Español de España con tuteo. URLs en español y nombres de ruta en inglés (`billing.index`, `billing.unbilled`, `billing.review`, `billing.sales`).
- Formatos: importes con `formatCurrency` (decimal, nunca float), fechas en Europe/Madrid con `format.ts`, duraciones con un formateador nuevo con signo y su test en Vitest.

### 4.2 Permisos

- Importes, facturas, cobros, contactos, PDF, la portada «Resumen», «Ventas», «Por revisar» y la búsqueda de facturas: solo `view-billing` (admins y `view-financials`, con el módulo visible). El contador de «Por revisar» en la barra lateral, también solo para ellos.
- Responsables y gestores: su sección Facturación solo enseña «Vendido frente a real» (en horas, y un gestor solo sus proyectos) y, si tiene `exportBillingHours`, «Por facturar» en horas. El interruptor «Horas | Importes» no aparece sin `view-billing`. `/facturacion` los sigue llevando a «Vendido frente a real» (D-401).
- Exclusiones de D-245: quien esté excluido no ve la sección, ni las rutas nuevas (404), ni las facturas en la búsqueda global, ni la barra de facturación de proyectos, bolsas y clientes. Desde D-247, tampoco importes en Informes, sin cambios.
- «Por facturar» sin el módulo `billing` (D-402): se sigue viendo con `ClientPolicy::viewBilling`, en horas para quien no ve importes.
- `sync-holded` (admins) para el botón «Sincronizar» de la cabecera; los demás solo ven el estado.
- Acciones en bloque y aceptar sugerencias en bloque: los mismos permisos que la acción individual, comprobados en el servidor fila a fila.
- Tests obligatorios: Pest para las rutas nuevas, las redirecciones 301, el enlace en bloque, la fuente de búsqueda (aislamiento de cliente y de excluidos) y el mejor candidato de contactos; Vitest para el formateador de duraciones, el estado relativo y el caso nuevo «por horas» de `sold-vs-actual-status.json`; Playwright para la portada, Por revisar, la vista lateral con el teclado, el móvil sin desplazamiento lateral y axe sin fallos graves en cada pantalla. Hay que adaptar `tests/e2e/billing.spec.ts` (navegación «Secciones de facturación», `invoice-table`, `accept-suggestion`, `holded-contacts`, `contact-client` y los botones de servicio).

---

## Anexo: cómo se hicieron las capturas

- Servidor: `e2e-server-8090.sh` con la base `ux-facturacion` (SQLite con el DemoDataSeeder y Holded falso). El módulo `billing` se encendió en `/admin/ajustes` con el admin de ejemplo, como `setBillingModule` en `billing.spec.ts`.
- Spec temporal de Playwright en `tests/e2e/tmp/` (ya borrado), contra `http://127.0.0.1:8090`, a 1440 × 900 y 390 × 844, con capturas de página completa y del primer pliegue.
- Interacciones capturadas: periodo abierto y filtro de servicio del informe (`i01`-`i03`), filtro de estado y búsqueda del listado (`i04`, `i05`), enlazar una factura con su sugerencia desde el listado (`i06`, `i07`), enlazar a mano desde la ficha (`i08`-`i10`), casar un contacto con buscador (`i11`-`i14`) y la vista de un responsable (`13`).
- Ojo con `13-responsable-vendido-1440.png`: se tomó después de enlazar a mano la F260314 con LAM-INT (`i10`), por eso ese proyecto aparece al 1.556 % (VFR-4).
- El servidor del puerto 8090 quedó parado al terminar y el repositorio sin cambios.
