# PROYECTO: Gestor interno de proyectos, horas y carga de trabajo

> Documento de especificación y objetivo para Claude Code.
> Guárdalo en la raíz del repositorio como `SPEC.md` y referéncialo desde `CLAUDE.md`.

---

## 0. Cómo debes trabajar (instrucciones para Claude Code)

1. **Lee este documento completo antes de escribir código.** Es la fuente de verdad del producto.
2. **Trabaja por fases** (sección 17). No empieces una fase hasta que la anterior cumpla sus criterios de aceptación y tenga tests en verde.
3. **Al inicio de cada fase**, entra en modo plan y presenta:
   - las migraciones y modelos que vas a crear o tocar,
   - las rutas y pantallas,
   - los tests que escribirás.
   Espera mi aprobación antes de implementar.
4. **Si algo es ambiguo o contradictorio, pregunta.** No inventes reglas de negocio. Si tienes que tomar una decisión menor, tómala, déjala anotada en `docs/DECISIONES.md` y avísame.
5. **Mantén un `CLAUDE.md`** con comandos de desarrollo, convenciones, estructura de carpetas y el estado actual de las fases.
6. **Commits pequeños y descriptivos** (Conventional Commits). Una rama por fase o por funcionalidad grande.
7. **Tests obligatorios** para toda regla de negocio: bolsas de horas, cálculo de carga, métricas, permisos. Usa Pest (backend) y Vitest (frontend). Añade tests E2E con Playwright para los flujos críticos.
8. **Verifica versiones y licencias** de cada dependencia antes de instalarla. Usa siempre la última versión estable. Todo lo que uses debe tener licencia compatible con uso comercial interno (MIT, Apache 2.0, BSD o similar). No uses librerías de pago ni con licencia GPL de componentes que se distribuyan al navegador sin avisarme.
9. **La interfaz está en español** (España). El código, los nombres de tablas y las variables, en inglés.
10. **Simplicidad por encima de todo.** Este producto existe porque ClickUp tiene demasiadas opciones. Ante la duda, menos campos, menos configuración y menos clics.
11. **Continuidad entre sesiones.** Mantén `docs/PROGRESO.md` con:
    - qué está hecho, qué está en curso y qué es lo siguiente,
    - los problemas abiertos.
    Al empezar cualquier sesión, lee `CLAUDE.md`, `SPEC.md`, `docs/PROGRESO.md` y `docs/DECISIONES.md` antes de hacer nada.
12. **El servidor de producción es un servidor en uso con otros servicios.** Todo lo que hagas en él se rige por la **sección 16 (Servidor y despliegue)**, que tiene prioridad sobre cualquier otra instrucción de este documento. Ante la duda, **para y pregunta**.
13. **Nunca subas secretos al repositorio** (`.env`, claves, tokens, contraseñas). Usa `.env.example` con valores ficticios.

### Datos que te facilitaré (pídemelos cuando los necesites, no los inventes)
- Acceso SSH al servidor y nombre del subdominio.
- Remoto del repositorio Git.
- Datos SMTP para el correo saliente.
- Destino de las copias de seguridad fuera del servidor.
- Logotipo oficial de Audax Studio en SVG (versión clara y oscura) y favicon.
- Lista inicial de empleados: nombre, email, departamento, rol, jornada y coste/hora.
- Texto informativo para la plantilla sobre el uso de los datos (lo validaremos con nuestro asesor laboral o de RGPD).

---

## 1. Contexto

- **Empresa:** **Audax Studio** (https://www.audaxstudio.com), agencia de UX/UI, negocio e innovación en Valencia, con tres departamentos: **Diseño**, **Desarrollo** y **Marketing**. Cada departamento tiene varias personas.
- **Usuarios:** unos 10 hoy. Debe funcionar sin cambios de arquitectura hasta **100 usuarios internos** más los accesos de clientes.
- **Uso:** exclusivamente interno. **No es un SaaS**, es de una sola empresa (single-tenant).
- **Alojamiento:** servidor propio de la empresa, **ya en funcionamiento y con otros servicios y webs alojados**. La app se instalará en un **subdominio**, en una suscripción o espacio que creará el responsable del servidor (ver sección 16).
- **Desarrollo:** lo hace el equipo interno, poco a poco y sin plazo fijo.
- **Herramienta actual:** ClickUp. Es demasiado pesada y cuesta mucho sacar informes de horas y productividad.

### Objetivos del producto (por orden de prioridad)

1. **Control de proyectos**, a nivel de cliente, proyecto y bolsa de horas.
2. **Control de productividad** por persona, departamento y empresa.
3. **Planificación de carga futura:** ver de forma muy visual la carga de cada persona la semana que viene y las siguientes.
4. **Portal de cliente** donde el cliente consulta el consumo de sus bolsas de horas.
5. **Comunicación interna:** chat de proyecto y chat privado, con mensajes de audio transcritos automáticamente.

Los datos tienen que salir de forma **visual, rápida y fácil**. Es el principal dolor actual.

---

## 2. Stack técnico

| Capa | Tecnología | Motivo |
|---|---|---|
| Backend | **Laravel** (última LTS/estable), PHP 8.3+ | Ecosistema completo (colas, eventos, websockets, auth) y equipo con experiencia en PHP |
| Frontend | **Inertia.js + React + TypeScript** | SPA sin mantener una API separada |
| UI | **Tailwind CSS + shadcn/ui** (Radix) | Componentes accesibles, tema claro/oscuro nativo |
| Base de datos | **PostgreSQL** (preferido). Si el servidor ya tiene MariaDB/MySQL y añadir PostgreSQL supone riesgo o coste, propón usar MariaDB 10.11+/MySQL 8+ y evita funciones exclusivas de PostgreSQL | Consultas analíticas, integridad |
| Caché / colas | **Redis** + Laravel Horizon | Colas de notificaciones, informes, procesado de adjuntos |
| Tiempo real | **Laravel Reverb** (WebSockets) + Laravel Echo | Chat, notificaciones, presencia |
| Transcripción de audios | **whisper.cpp** o **faster-whisper** autoalojado (MIT), ejecutado en cola, modelo pequeño/medio en español. Sin APIs externas | Transcribir los audios del chat sin enviar datos a terceros ni pagar por uso |
| Almacenamiento | Disco local con abstracción `Storage` (preparado para MinIO/S3) | Adjuntos |
| Gráficas | **Recharts** o **Apache ECharts** (elige una y justifícalo) | Dashboards |
| Gantt | Librería con licencia MIT/Apache (evalúa SVAR React Gantt, frappe-gantt u otras). Si ninguna encaja, componente propio | Gantt con hitos y dependencias |
| Auth | Laravel Fortify: login, recuperación de contraseña, **2FA** | Seguridad |
| Permisos | **spatie/laravel-permission** + Policies | Roles y permisos |
| Auditoría | **spatie/laravel-activitylog** | Historial de cambios |
| Exportación | Laravel Excel (XLSX/CSV) + generación PDF (DomPDF preferido, porque no requiere instalar Chromium en el servidor) | Informes y facturación |
| PWA | Manifest + service worker | Instalable en móvil, web adaptable |
| Desarrollo local | **Docker Compose** (Laravel Sail o equivalente) | Entorno reproducible |
| Producción | **Adaptado al servidor existente** tras auditarlo (sección 16): nativo con el panel de control, contenedores Docker aislados o mixto | Convivir sin riesgo con lo que ya hay |
| Tests | Pest, Vitest, Playwright | |
| Calidad | Laravel Pint, PHPStan (Larastan) nivel ≥ 6, ESLint, Prettier | |

Si consideras que alguna pieza tiene una alternativa claramente mejor para este caso, **propónla antes de usarla** y explica por qué.

---

## 3. Principios de producto y UX

- **Idioma:** español (España). Zona horaria `Europe/Madrid`. **La semana empieza en lunes.** Fechas en formato `dd/mm/aaaa`. Horas mostradas como `h:mm` (por ejemplo `2:30`) y almacenadas en **minutos enteros**.
- **Fechas y horas internas:** los instantes (`timestamps`, inicio y fin del temporizador) se guardan en UTC y se muestran en `Europe/Madrid`. Los días de imputación (`date`) son fechas locales sin hora. Ojo con los cambios de horario de verano e invierno en los cálculos.
- **Moneda y números:** euros (EUR), formato `es-ES` (`1.234,56 €`). Importes almacenados como decimal (nunca float).
- **Tema claro y oscuro**, con selector manual y opción "según sistema". Se guarda en la preferencia del usuario.
- **Web adaptable (responsive) y PWA.** Imputar horas y usar el chat desde el móvil tiene que ser cómodo.
- **Navegación principal** (barra lateral):
  - Inicio
  - Mis tareas
  - Proyectos
  - Clientes
  - Bolsas (vista global, solo gestores, responsables y admin)
  - Horas
  - Carga
  - Informes
  - Chat
  - Administración (según rol)
- **Búsqueda global** (Ctrl/Cmd + K) de clientes, proyectos, tareas, personas y mensajes del chat (incluido el texto transcrito de los audios), respetando siempre los permisos de cada usuario.
- **Pocos campos obligatorios.** Formularios cortos y valores por defecto inteligentes.
- **Accesibilidad:** contraste AA en ambos temas y navegación por teclado.
- **Rendimiento:** las pantallas principales deben cargar en menos de 1 s con 100 usuarios y 5 años de datos. Los dashboards usan consultas agregadas e índices, nunca cálculos en PHP fila a fila.

### 3.1 Identidad visual (look and feel de Audax Studio)

> **Actualización del 03/10/2026 (D-137):** la referencia del tema pasa a ser la **hoja de estilos de Audax v1.2** (kit de maquetación de presupuestos y presentaciones): tinta #0B1B33, fondo gris #F5F6F7 con superficies blancas, bordes #E2E6EC, estilo plano sin esquinas redondeadas ni sombras, DM Sans 400/500/600 y la imagen original de portada como fondo de marca. Donde esta sección y D-137 no coincidan, manda D-137.

La app debe sentirse parte de la marca **Audax Studio**. Toma como referencia la web **https://www.audaxstudio.com**. Si tienes acceso web, revísala tú mismo antes de montar el tema. Los valores siguientes están extraídos de su CSS (variables globales de Elementor) y **son la fuente de verdad**:

**Colores de marca**
| Token | Valor | Uso en la web |
|---|---|---|
| `primary` (navy) | `#001B39` | Titulares, texto fuerte, fondos oscuros |
| `accent` (azul Audax) | `#0171FF` | Botones principales, enlaces, palabras destacadas en titulares, estados activos |
| `accent-60` | `#0171FF99` | Acento suavizado (hover, fondos de selección) |
| `text` | `#001B3999` (navy al 60 %) | Texto de párrafo |
| `border` | `#001B3933` (navy al 20 %) | Bordes y separadores |
| `surface-muted` | `#001B390A` (navy al 4 %) | Fondos de tarjetas, filas alternas, zonas secundarias |
| `white-60` | `#FFFFFF99` | Texto secundario sobre fondo oscuro |
| Fondo | `#FFFFFF` | Fondo general en tema claro |

**Tipografía**
- **DM Sans** en toda la interfaz. **Autoalójala** (archivos de fuente en el proyecto, no Google Fonts CDN) por RGPD y rendimiento.
- La web usa peso **400** en casi todo, incluidos los titulares, con un aire limpio y ligero. Mantén 400 como base. Usa 500 solo para énfasis funcional (cabeceras de tabla, cifras de KPI, etiquetas activas) y nunca negritas pesadas.
- Escala de referencia: titular grande 48/56 px, título de sección 32/44 px, subtítulo 24 px, cuerpo 16/24 px, texto secundario 14 px. En la app, reduce la escala para pantallas densas (título de página unos 28–32 px, cuerpo 14–15 px en tablas).
- Recurso de marca: en titulares y encabezados destacados, **una o varias palabras clave en azul `#0171FF`** dentro de un texto navy (por ejemplo "Carga de la **semana que viene**"). Úsalo con moderación: cabeceras de página y estados vacíos.

**Componentes**
- **Radio de borde: 3 px**, casi rectos. Es un rasgo de la marca: nada de esquinas muy redondeadas ni botones tipo píldora. Adapta el `--radius` de shadcn/ui.
- **Botón principal:** fondo `#0171FF`, texto blanco, padding unos 12 × 16 px, 3 px de radio, sin sombra.
- **Botón secundario:** contorno de 1 px `#0171FF`, texto `#0171FF`, fondo transparente o blanco.
- **Botón sobre fondo oscuro:** fondo blanco con texto `#0171FF`.
- Sombras mínimas o inexistentes. La separación se consigue con bordes finos (`border`) y fondos `surface-muted`.
- Mucho espacio en blanco, líneas divisorias finas y composición limpia.
- **Degradado de marca:** la web usa en sus cabeceras un degradado oscuro de navy a azul intenso con toques turquesa. Úsalo **solo** en la pantalla de login, la cabecera del portal de cliente y los estados vacíos grandes. Nunca detrás de datos o tablas.
- **Logo:** el logotipo "AUDAX" en SVG. Pídeme los archivos oficiales (versión clara y oscura). Mientras tanto, usa un texto provisional.
- Iconografía lineal y fina (Lucide encaja bien con este estilo).

**Tema oscuro**
- Se construye a partir del navy de marca: fondo base unos `#000F20`, superficies `#001B39`, bordes blanco al 10–15 %, texto blanco al 90 % y secundario blanco al 60 % (`#FFFFFF99`). El acento `#0171FF` se mantiene. Ajusta su luminosidad solo si no cumple el contraste AA.

**Colores de datos y estados**
- La marca solo tiene un color de acento. Para gráficas, departamentos, tipos de tarea y el semáforo de carga, define una **paleta complementaria** coherente con el azul y el navy: tonos fríos para las categorías, y verde, ámbar y rojo desaturados para los estados.
- Ningún dato debe depender solo del color. Valida el contraste en ambos temas.
- Los colores por defecto de los departamentos, por ejemplo Diseño, Desarrollo y Marketing, salen de esa paleta y se pueden editar.

**Entregable:** antes de construir pantallas, crea el tema (variables CSS de shadcn/ui y Tailwind para claro y oscuro) y una página interna `/styleguide` con colores, tipografía, botones, formularios, tablas, tarjetas, badges y gráficas de ejemplo. **Enséñamela para aprobarla** antes de seguir con la Fase 1.

---

## 4. Modelo de dominio

A continuación va el modelo propuesto. Ajusta los tipos y añade índices donde convenga. Todas las tablas llevan `timestamps`; donde tenga sentido, también `soft deletes`.

### 4.1 Organización y personas

- **Department**: `id`, `name` (Diseño, Desarrollo, Marketing, editable), `color`, `manager_user_id` (nullable).
- **User**:
  - datos básicos: `id`, `name`, `email`, `password`, `avatar`,
  - organización: `department_id` (nullable para admin y clientes), `role`,
  - economía: `hourly_cost` (coste empresa/hora, visible solo para admin), `default_hourly_rate` (nullable),
  - estado y preferencias: `is_active`, `theme_preference`, `locale`, `notification_preferences` (JSON),
  - portal de cliente: `client_id` (nullable, solo para usuarios de tipo cliente).
- **WorkSchedule** (capacidad, versionada en el tiempo): `id`, `user_id`, `valid_from`, `valid_to` (nullable), horas por día de la semana (`mon_minutes` … `sun_minutes`). Permite jornadas parciales e intensivas. Para calcular la capacidad de una fecha se usa el horario vigente en esa fecha.
- **Holiday** (festivos): `id`, `date`, `name`, `scope` (empresa). Se editan desde administración.
- **Absence**: `id`, `user_id`, `type` (vacaciones, baja, permiso, formación externa, otro), `start_date`, `end_date`, `partial_minutes` (nullable, para ausencias de parte del día), `status` (solicitada, aprobada, rechazada), `approved_by`, `notes`.

### 4.2 Clientes, proyectos y bolsas

- **Client**:
  - `id`, `name`, `tax_id` (nullable), `contact_name`, `contact_email`, `phone`, `notes`, `is_active`,
  - `default_hourly_rate` (nullable).
  - Un cliente puede tener **varios usuarios de portal** (contactos) con acceso independiente.
- **Project**:
  - identificación: `id`, `client_id` (nullable para proyectos internos), `name`, `code` (corto, único, por ejemplo `ACME-WEB`), `description`, `color`,
  - clasificación: `billing_type` (`hour_bank` = bolsas de horas, `fixed_price` = precio cerrado, `time_and_materials` = por horas sin bolsa, `internal` = interno no facturable),
  - estado y fechas: `status` (planificado, activo, en pausa, completado, archivado), `start_date`, `due_date`,
  - economía: `budget_minutes` (nullable, estimación global), `fixed_price_amount` (nullable), `hourly_rate` (nullable, hereda del cliente),
  - responsable: `owner_user_id`.
  - `ProjectMember` (pivote): `project_id`, `user_id`. Controla quién ve el proyecto si no es admin ni responsable de departamento.
- **HourBank** (bolsa de horas):
  - `id`, `project_id`, `name` (por ejemplo "Bolsa Desarrollo 50h – Q4"),
  - `department_id` (**nullable**: si tiene valor, solo pueden imputar personas de ese departamento),
  - `total_minutes`, `hourly_rate` (nullable, hereda del proyecto), `price_amount` (nullable, importe cobrado al cliente por la bolsa), `start_date`, `end_date` (nullable),
  - `status` (activa, agotada, cerrada, renovada),
  - `overage_policy` (`inherit`, `allow`, `block`; por defecto `inherit`, que usa el ajuste global),
  - renovación: `renewed_from_id` (nullable, apunta a la bolsa anterior),
  - facturación: `invoice_reference` (nullable), `notes`,
  - campos calculados (vista o caché mantenida por eventos): `consumed_minutes`, `remaining_minutes` (nunca negativo), `overage_minutes`, `consumed_pct` (puede superar el 100 %).

### 4.3 Tareas

- **TaskType** (tipologías configurables por admin):
  - `id`, `name` (por ejemplo Diseño UI, Maquetación, Desarrollo, Bug, Reunión, SEO, Contenidos, Campaña, Soporte, Gestión),
  - `color`, `icon`, `department_id` (nullable), `is_billable_default` (bool), `is_active`.
- **TaskStatus**: conjunto global y sencillo, editable por admin. Por defecto: *Por hacer*, *En curso*, *En revisión*, *Bloqueada*, *Hecha*. Cada estado tiene `category` (`todo`, `in_progress`, `done`) para que los cálculos no dependan de los nombres.
- **Task**:
  - relaciones: `id`, `project_id`, `hour_bank_id` (**nullable**; obligatorio si el proyecto es `hour_bank`), `parent_task_id` (nullable, **subtareas de un solo nivel**),
  - contenido: `title`, `description` (texto enriquecido), `task_type_id`, `status_id`, `priority` (baja, normal, alta, urgente),
  - asignación y fechas: `assignee_user_id` (**un responsable**; opcionalmente `TaskWatcher` para seguidores), `start_date`, `due_date`,
  - planificación: `estimated_minutes`, `is_billable` (hereda del tipo, editable), `is_milestone` (bool),
  - otros: `position` (orden manual), `completed_at`, `created_by`.
- **TaskDependency**: `predecessor_task_id`, `successor_task_id`, `type` (solo `finish_to_start` en esta versión). Hay que validar que no se creen ciclos.
- **Milestone** (hito): se modela como `Task` con `is_milestone = true`, duración 0 y sin horas. Se muestra como rombo en el Gantt. Puede tener dependencias con tareas normales.
- **TaskComment**: `id`, `task_id`, `user_id`, `body`, menciones `@usuario`, adjuntos.
- **Attachment** (polimórfico): `id`, `attachable_type`, `attachable_id`, `user_id`, `disk`, `path`, `original_name`, `mime`, `size`, `thumbnail_path`.
- **RecurringTaskRule**: plantilla de tarea más regla (semanal o mensual). Un job diario genera las instancias.
- **ProjectTemplate**: estructura de tareas e hitos reutilizable al crear un proyecto.

### 4.4 Horas

- **TimeEntry**:
  - relaciones: `id`, `user_id`, `task_id`, `project_id` y `hour_bank_id` (**desnormalizados** desde la tarea, para informes rápidos y para no perder el histórico si se mueve la tarea),
  - tiempo: `date`, `minutes`, `started_at` y `ended_at` (nullable, si vino del temporizador), `description`,
  - facturación: `is_billable` (hereda de la tarea), `hourly_rate_snapshot` y `hourly_cost_snapshot` (se congelan al aprobar),
  - exceso: `is_overage` (bool; `true` si la entrada es exceso sobre una bolsa agotada),
  - flujo: `status` (`draft`, `submitted`, `approved`, `locked`), `approved_by`, `approved_at`.
- **ActiveTimer**: `user_id` (único), `task_id`, `started_at`. **Máximo un temporizador activo por usuario.**
- **TimesheetPeriod**: semana (lunes a domingo) por usuario, con `status` (abierta, enviada, aprobada, bloqueada).

### 4.5 Chat

- **Conversation**: `id`, `type` (`project`, `direct`, `group`), `project_id` (nullable), `name` (grupos), `created_by`.
  - Cada proyecto tiene **una conversación de proyecto** que se crea automáticamente.
  - Las conversaciones `direct` son 1:1 y únicas por pareja de usuarios.
- **ConversationParticipant**: `conversation_id`, `user_id`, `last_read_message_id`, `muted_until`, `role` (miembro o admin del grupo).
- **Message**:
  - `id`, `conversation_id`, `user_id`, `type` (`text`, `audio`, `file`, `system`), `body` (markdown ligero),
  - relaciones: `reply_to_message_id` (hilos o citas), `task_id` (nullable, para enlazar un mensaje a una tarea),
  - estado: `edited_at`, `deleted_at`,
  - metadatos: `metadata` (JSON: duración del audio, forma de onda, previsualización de enlaces).
- **AudioTranscription**: `id`, `message_id`, `status` (`pending`, `processing`, `done`, `failed`), `text`, `language`, `model`, `duration_ms`, `error` (nullable).
- **MessageReaction**: `message_id`, `user_id`, `emoji`.

### 4.6 Transversales

- **Notification**: las de Laravel (base de datos más broadcast).
- **ActivityLog**: spatie. Es **obligatorio** en TimeEntry, HourBank, Task y Project.
- **Setting**: clave-valor global (nombre de empresa, logo, horas de jornada por defecto, umbrales de alertas, tamaño máximo de adjunto, etc.).

---

## 5. Roles y permisos

| Rol | Descripción |
|---|---|
| **Admin** | Acceso total, configuración y datos económicos (costes, tarifas, rentabilidad). |
| **Responsable de departamento** | Ve y gestiona proyectos y tareas. Ve horas, carga y productividad **de su departamento**. Aprueba horas y ausencias de su equipo. No ve costes salariales individuales salvo que el admin lo habilite. |
| **Gestor de proyecto** | Gestiona los proyectos que tiene asignados (tareas, bolsas, planificación). Ve las horas imputadas a sus proyectos. |
| **Empleado** | Ve los proyectos de los que es miembro, sus tareas, sus horas, su carga y su propio dashboard. Imputa horas. Usa el chat. |
| **Cliente** | Solo accede al **portal de cliente** (sección 11). Nunca ve la aplicación interna. |

- Un usuario interno puede combinar el rol de **Gestor de proyecto** por proyecto con su rol base.
- Todos los permisos se implementan con Policies y se testean.
- Los datos económicos (coste/hora, rentabilidad, tarifas) se protegen con un permiso específico (`view-financials`).

### 5.1 Panel personal del empleado ("Inicio")

Cada usuario interno tiene **su propia zona de trabajo**. Es la pantalla de inicio al entrar y **solo muestra sus cosas**:

- **Mis tareas de hoy y de esta semana**, más las vencidas, con acceso directo al temporizador en cada tarea.
- **Temporizador activo** y botón de imputación rápida.
- **Mis horas:**
  - hoy y esta semana frente a mi capacidad,
  - estado de mi hoja semanal (abierta, enviada, aprobada, devuelta con comentario),
  - días sin imputar.
- **Mi carga** esta semana y la que viene (sección 9), con el mismo semáforo de colores.
- **Mis indicadores** del mes: ocupación, facturabilidad, precisión de estimación y reparto de mis horas por cliente y proyecto.
- **Mis próximos hitos**, en los proyectos donde soy miembro.
- **Menciones y mensajes sin leer**, y notificaciones recientes.
- **Mis ausencias**: solicitar vacaciones o permisos y ver su estado.

**Visibilidad del empleado:**
- Ve **solo sus propias horas** y sus propios indicadores. Nunca ve las horas ni la productividad de otros compañeros.
- Ve los proyectos de los que es miembro, con sus tareas, el Gantt, el chat y el consumo de las bolsas en %. El detalle por persona de las bolsas y la pestaña **Horas** del proyecto con las entradas de todos solo los ven gestores, responsables y admin. En esa pestaña, el empleado ve únicamente sus propias entradas.
- No ve costes, tarifas ni rentabilidad.

Los responsables, gestores y admin tienen **el mismo panel personal**, más los accesos a los dashboards de su ámbito (sección 10).

---

## 6. Módulo: Proyectos y tareas

### Clientes
- Listado con búsqueda y filtros (activo o inactivo).
- Ficha con: datos básicos, proyectos, bolsas activas y su consumo, horas del mes y del año, y acceso al portal (gestión de usuarios cliente).

### Proyectos
- Listado con filtros: cliente, estado, tipo de facturación, responsable, departamento implicado.
- Ficha de proyecto con pestañas:
  1. **Resumen:** estado, fechas, horas estimadas frente a reales, bolsas con barras de consumo, próximos hitos, actividad reciente.
  2. **Tareas**, con tres vistas:
     - **Lista** (agrupable por estado, responsable, bolsa o tipo),
     - **Tablero Kanban** por estado, con arrastrar y soltar,
     - **Calendario**.
  3. **Gantt** (sección 6.1).
  4. **Bolsas de horas** (sección 8).
  5. **Horas:** entradas del proyecto con filtros y exportación.
  6. **Chat:** conversación del proyecto.
  7. **Archivos:** todos los adjuntos del proyecto (tareas y chat).
  8. **Ajustes:** miembros, tarifa, tipo de facturación, plantilla.
- **Crear proyecto** desde cero o desde plantilla.

### Tareas
- Creación rápida en línea (título, pulsar Enter y seguir), con edición completa en un panel lateral. Nada de páginas nuevas.
- Campos visibles por defecto: título, responsable, bolsa, tipo, estado, fechas, estimación.
- Subtareas de un solo nivel. Las horas de una subtarea suman en la tarea padre en los informes.
  - **Estimación con subtareas:** si una tarea tiene subtareas con estimación, la estimación de la tarea padre es la **suma de las subtareas** (solo lectura), para no contar dos veces la carga ni la precisión de estimación.
- **Mover una tarea** a otra bolsa o a otro proyecto no mueve las horas ya imputadas: esas entradas conservan su proyecto y su bolsa originales. Avisa de ello al usuario antes de confirmar.
- Comentarios con menciones, adjuntos y reacciones.
- **Botón de temporizador** en cada tarea (play/stop) y botón "Añadir horas" manual.
- Seguidores (watchers), que reciben notificaciones.
- **Mis tareas:** vista personal con secciones *Hoy*, *Esta semana*, *Próximas*, *Vencidas* y *Sin fecha*.
- Acciones masivas: cambiar estado, responsable, fechas o bolsa.

### 6.1 Gantt
- Muestra tareas (barras) e **hitos** (rombos) de un proyecto. Opcionalmente, vista multiproyecto filtrada por cliente, departamento o responsable.
- Escalas: día, semana y mes.
- Arrastrar para mover y redimensionar fechas.
- Crear dependencias arrastrando de una barra a otra (solo tipo fin-inicio).
- Al mover una tarea con sucesoras, **avisar** del conflicto y ofrecer desplazar las sucesoras. **No se desplazan automáticamente sin confirmación.** Las dependencias son una ayuda, no una restricción rígida.
- Colores por estado o por responsable (seleccionable). Marca de "hoy".
- Las tareas sin fechas se listan aparte con la opción de asignarles fechas.

---

## 7. Módulo: Imputación de horas

- **Quién puede imputar:** cualquier miembro del proyecto (no solo el responsable de la tarea), respetando la restricción de departamento de la bolsa. Gestores, responsables y admin pueden imputar en nombre de otra persona; queda registrado en la auditoría.
- **Temporizador:**
  - un único temporizador activo por usuario, visible siempre en la cabecera,
  - al parar, se crea un `TimeEntry` en borrador con los minutos redondeados según un ajuste global (por defecto, al minuto),
  - si el temporizador cruza la medianoche, al pararlo se crean dos entradas, una por día,
  - si el temporizador lleva más de X horas (configurable, por defecto 10), avisar al usuario.
- **Entrada manual:** tarea, fecha, duración (acepta `1:30`, `1.5`, `90m`, `1h30`) y descripción opcional.
- **Hoja semanal (timesheet):**
  - cuadrícula con las tareas en filas y los días en columnas, editable como una hoja de cálculo,
  - totales por día y por semana frente a la capacidad del usuario,
  - botón "Copiar tareas de la semana anterior".
- **Flujo de validación:**
  1. El empleado **envía** su semana.
  2. El responsable de departamento **aprueba** o **devuelve con comentario**.
  3. Las horas aprobadas pueden **bloquearse** (por ejemplo, al facturar). Una vez bloqueadas, solo el admin puede editarlas, y queda registrado en la auditoría.
  - Opción de configuración: aprobación obligatoria o no. **Por defecto, activada.** Si se desactiva, las horas cuentan como aprobadas al enviarse.
- **Validaciones:**
  - no imputar en tareas de proyectos archivados,
  - no imputar en bolsas cerradas o renovadas. En bolsas agotadas se permite como exceso, salvo que su política sea `block` (sección 8),
  - no imputar si la bolsa está restringida a otro departamento,
  - no imputar más de 24 h al día,
  - no imputar en fechas futuras (configurable),
  - no imputar en semanas enviadas, aprobadas o bloqueadas (hay que reabrirlas; reabrir queda auditado),
  - aviso (sin bloqueo) al imputar en una tarea ya completada o en un día con ausencia aprobada,
  - aviso (sin bloqueo) si el total del día supera la capacidad en más de un 25 %.
- **Descripción de la entrada:** opcional por defecto. Un ajuste permite hacerla obligatoria (útil si se muestra al cliente en el portal).
- **Horas no facturables:** reuniones internas, formación, gestión, etc. Se imputan a **proyectos internos** (`billing_type = internal`). Crea por defecto un proyecto interno "Interno – Agencia" con las tareas *Reuniones*, *Formación*, *Gestión* y *Comercial*.

---

## 8. Módulo: Bolsas de horas (regla de negocio crítica)

### Reglas
1. Un proyecto de tipo `hour_bank` puede tener **varias bolsas activas a la vez**. Por ejemplo: una de 50 h para Desarrollo y otra de 20 h para Marketing.
2. **Cada tarea pertenece a una bolsa concreta.** La elige quien crea la tarea. Todas las horas imputadas a esa tarea descuentan de esa bolsa.
3. Si la bolsa tiene `department_id`, **solo las personas de ese departamento** pueden imputar en sus tareas. Al crear una tarea, el selector de bolsa muestra primero las del departamento del usuario.
4. **Consumo** = suma de `minutes` de **todos** los `TimeEntry` de la bolsa, sea cual sea su estado (borrador, enviado, aprobado o bloqueado). Una hora imputada cuenta desde el primer momento, para que el saldo interno sea siempre real. Si una entrada devuelta se corrige o se borra, el consumo se recalcula. El portal de cliente aplica su propio filtro (sección 11).
5. **Alertas automáticas** al 75 %, 90 % y 100 % de consumo (umbrales configurables). Se envían al gestor del proyecto, al responsable del departamento de la bolsa y a los admins. Llegan como notificación en la app, por email y como mensaje de sistema en el chat del proyecto.
6. **Al llegar al 100 % (exceso permitido por defecto):**
   - la bolsa pasa a `agotada`,
   - **por defecto se sigue permitiendo imputar**. Toda hora que supere el total de la bolsa se considera **exceso**: se guarda con `is_overage = true` en el `TimeEntry`. Si una entrada cruza el límite, se parte automáticamente en dos: la parte dentro de la bolsa y la parte en exceso,
   - el exceso se muestra **en rojo** en la bolsa, en los informes, en la vista global de bolsas y en el portal de cliente (por ejemplo "52:30 / 50:00 · +2:30 de exceso"),
   - al imputar en exceso, el usuario ve un **aviso no bloqueante** ("Esta bolsa está agotada; estas horas se registrarán como exceso"),
   - cada imputación en exceso notifica al gestor del proyecto y al responsable del departamento (agrupando las notificaciones, como máximo una al día por bolsa),
   - **opción para bloquear el exceso:**
     - hay un ajuste global `allow_hour_bank_overage` (por defecto `true`),
     - cada bolsa tiene `overage_policy` con los valores `inherit` (por defecto), `allow` y `block`,
     - con `block`, no se permite imputar más allá del saldo, y una imputación que lo supere se **rechaza** con un mensaje que indica el saldo disponible (sin recortarla),
   - los informes muestran siempre por separado las horas en bolsa y las horas en exceso, para poder facturar el exceso o descontarlo de la renovación,
   - **recálculo:** si se edita o borra una entrada de una bolsa (por ejemplo, al devolver una semana), el marcado de exceso de las entradas **no bloqueadas** de esa bolsa se recalcula en orden cronológico (`date`, luego `created_at`). Las entradas bloqueadas nunca cambian. Esto debe ir cubierto por tests.
7. **Renovación:** desde una bolsa agotada o próxima a agotarse, el botón "Renovar" crea una **nueva bolsa** con los mismos parámetros (editables) y `renewed_from_id` apuntando a la anterior. La anterior pasa a `renovada`. Se ofrece **mover las tareas abiertas** de la bolsa antigua a la nueva. Las horas ya imputadas **nunca se mueven**.
8. Histórico completo de renovaciones por proyecto y cliente.
9. Una bolsa se puede **cerrar** manualmente (por ejemplo, fin de contrato con horas sobrantes). El saldo no consumido queda registrado.

### UI
- Tarjeta por bolsa con: barra de progreso con colores por umbral (verde, ámbar, rojo), horas consumidas / totales / restantes / exceso, departamento, fechas, estado y botón de renovar.
- **Horas comprometidas:** en cada bolsa se muestra también la suma de las horas estimadas restantes de sus tareas abiertas. Si consumido + comprometido supera el total, aparece un aviso ("Las tareas planificadas superan el saldo de la bolsa en X h"). Así se anticipa el agotamiento antes de que ocurra.
- Detalle de la bolsa: consumo por semana (gráfica), por persona, por tipo de tarea, y listado de entradas.
- **Vista global de bolsas** (para gestión): todas las bolsas activas de todos los clientes ordenadas por % de consumo, para anticipar renovaciones y facturación.

---

## 9. Módulo: Capacidad y carga futura (dolor principal)

### Cálculo de la capacidad
- `capacidad(usuario, día)` = minutos del `WorkSchedule` vigente para ese día de la semana, **menos** festivos (0 ese día) y **menos** ausencias aprobadas (día completo o `partial_minutes`).

### Cálculo de la carga planificada
- Para cada tarea abierta (estado distinto de `done`) con responsable, estimación y fechas:
  - `restante = max(estimated_minutes − minutos ya imputados, 0)`,
  - el restante se **reparte uniformemente entre los días laborables** del responsable desde `max(hoy, start_date)` hasta `due_date`, excluyendo días con capacidad 0,
  - si la tarea solo tiene `due_date`, se reparte desde hoy hasta esa fecha,
  - si la fecha está **vencida**, todo el restante se asigna a **hoy** y la tarea se marca como vencida,
  - las tareas **sin estimación o sin fechas** no suman carga, pero aparecen en una bandeja "Sin planificar" con un aviso, para que se completen,
  - las tareas **sin responsable** aparecen en una bandeja "Sin asignar" por departamento (según el departamento de la bolsa o del tipo de tarea), con sus horas estimadas, para repartirlas desde la vista de carga,
  - en tareas con subtareas se usa la carga de las subtareas, no la de la tarea padre.
- `carga(usuario, periodo)` = suma de lo repartido en el periodo.
- `ocupación planificada` = carga / capacidad.

### Vista "Carga"
- **Matriz personas × días o semanas**, con selector de horizonte: semana actual, semana siguiente, próximas 4 semanas o próximos 3 meses (por semanas).
- Cada celda muestra horas planificadas / capacidad, con **color de semáforo**:
  - gris: sin capacidad (vacaciones o festivo), con icono,
  - azul: por debajo del 70 %,
  - verde: entre el 70 % y el 100 %,
  - ámbar: entre el 100 % y el 120 %,
  - rojo: por encima del 120 %.
- Filtros: departamento, persona, cliente, proyecto.
- Agrupación por departamento, con totales por departamento.
- Al hacer clic en una celda se abre un panel con las tareas que forman esa carga, y se pueden **reasignar** (cambiar responsable o fechas) desde el propio panel. La matriz se recalcula al momento.
- **Vista personal** en *Inicio*: "Mi carga esta semana y la que viene".
- **Por defecto se abre en "Semana que viene"**, que es la pregunta principal.

---

## 10. Módulo: Informes y dashboards (dolor principal)

### Definiciones (documéntalas también en la UI con tooltips)
| Métrica | Fórmula |
|---|---|
| Capacidad | Suma de la capacidad diaria del periodo (sección 9) |
| Horas imputadas | Suma de `TimeEntry.minutes` del periodo |
| Horas facturables | Horas imputadas con `is_billable = true` |
| **Ocupación** | Horas imputadas / capacidad |
| **Facturabilidad** | Horas facturables / horas imputadas |
| **Productividad facturable** | Horas facturables / capacidad |
| **Precisión de estimación** | Horas estimadas / horas reales, en tareas completadas del periodo. Se muestra también la desviación en % |
| Carga planificada | Sección 9 |
| Ingreso estimado | Horas facturables × tarifa aplicable (bolsa > proyecto > cliente > usuario). En bolsas con `price_amount`, el ingreso de las horas dentro de la bolsa es su parte proporcional de ese importe y el exceso se valora a la tarifa de la bolsa. En precio cerrado, prorrateo del importe según avance. Documenta el criterio exacto en `docs/DECISIONES.md` |
| Coste | Horas imputadas × `hourly_cost` del usuario |
| **Rentabilidad** | Ingreso − coste, y margen en % (solo con permiso `view-financials`) |
| Consumo de bolsa | Sección 8 |

### Filtros globales (comunes a todos los informes)
- **Periodo**: semana, mes, trimestre, año o rango personalizado, con navegación anterior/siguiente y comparación con el periodo anterior.
- **Persona**, **Departamento**, **Cliente**, **Proyecto**, **Bolsa**, **Tipo de tarea**, **Facturable sí/no**.
- Los filtros se reflejan en la URL, de modo que se pueden compartir y guardar como favoritos.

### Dashboards
1. **Dashboard de dirección (empresa):**
   - KPIs: horas imputadas, capacidad, ocupación, facturabilidad, ingreso y margen,
   - evolución semanal o mensual (líneas),
   - reparto por departamento y por cliente (barras),
   - top 10 de clientes y de proyectos por horas,
   - bolsas en riesgo (más del 75 %),
   - tareas vencidas.
2. **Por cliente:** horas por proyecto y por mes, bolsas y consumo, histórico de renovaciones, rentabilidad.
3. **Por proyecto:** estimado frente a real, horas por persona, por tipo de tarea y por semana, estado de las tareas, hitos.
4. **Por departamento:** ocupación y facturabilidad de cada miembro, reparto por cliente, carga futura.
5. **Por persona:**
   - capacidad, imputadas, facturables y ocupación,
   - reparto por cliente, proyecto y tipo de tarea,
   - precisión de estimación,
   - calendario de calor (heatmap) diario de horas,
   - días sin imputar.
6. **Informe de horas detallado:** tabla dinámica sencilla. Agrupa por dos dimensiones cualesquiera (por ejemplo cliente × persona, o proyecto × semana) con subtotales.

### Exportación
- Cualquier informe o tabla se exporta a **XLSX y CSV**.
- **PDF de consumo de bolsa** con la marca de la agencia, pensado para enviar al cliente.
- Exportación de horas para facturación: por cliente y periodo, con el detalle de cada entrada.

### Alertas de productividad (configurables)
- Resumen semanal por email a cada responsable, con: personas con días sin imputar, ocupación por encima o por debajo de los umbrales, bolsas en riesgo y tareas vencidas.

---

## 11. Portal de cliente

- Acceso con usuario y contraseña propios (rol **Cliente**, vinculado a un `Client`). Diseño simplificado con el logo de la agencia y los temas claro y oscuro.
- **Alta por invitación:** el admin o el gestor invita por email y el cliente crea su contraseña desde el enlace, que caduca. Incluye recuperación de contraseña y 2FA opcional. Se puede revocar el acceso en cualquier momento.
- Las URL del portal van bajo una ruta separada (por ejemplo `/portal`) con su propio layout y middleware.
- **Por defecto el cliente solo ve sus bolsas de horas:**
  - total, consumido, restante y % (barra),
  - estado y fechas,
  - consumo por mes (gráfica),
  - listado de entradas: fecha, tarea, tipo, persona (configurable por cliente: nombre, iniciales o "Equipo"; por defecto, nombre), duración y descripción,
  - histórico de bolsas renovadas,
  - descarga del PDF de consumo.
- **Opcionalmente**, por proyecto, el admin puede dar acceso también a:
  - la vista del proyecto (tareas y estados, sin horas individuales si así se configura),
  - el Gantt en solo lectura con hitos.
- El cliente **nunca** ve costes, tarifas internas, otros clientes, comentarios internos ni el chat interno.
- Solo se muestran al cliente las horas en estado `approved` o `locked` (configurable), para no enseñar borradores.
- Notificación por email opcional al cliente cuando su bolsa alcanza el 90 % y el 100 %.

---

## 12. Chat

### Chat
- **Tipos:** chat de proyecto (automático, con todos los miembros), mensajes directos 1:1 y grupos.
- **Funciones:**
  - texto con markdown ligero (negrita, cursiva, código, enlaces con previsualización),
  - **emojis** con selector completo y **reacciones** con emoji,
  - **audios**: grabación desde el navegador (MediaRecorder), con reproducción, forma de onda y duración,
  - **adjuntos**: imágenes (con miniatura y visor), PDF y archivos en general, con arrastrar y soltar y pegar desde el portapapeles. Límite configurable, por defecto **50 MB** por archivo,
  - **menciones** `@persona` y `@todos`, que generan notificación,
  - **respuestas en hilo** (responder citando un mensaje),
  - editar y borrar mensajes propios (queda marcado como "editado" o "eliminado"),
  - indicador de "escribiendo…", **presencia** (en línea / ausente / desconectado) y **leído por**,
  - contadores de no leídos por conversación y total,
  - búsqueda de mensajes y archivos,
  - **fijar mensajes** en una conversación,
  - **crear tarea desde un mensaje** (el mensaje queda enlazado a la tarea),
  - silenciar conversaciones,
  - mensajes de sistema automáticos en el chat del proyecto (bolsa al 90 %, hito completado, etc.),
  - audios con duración máxima configurable (por defecto 5 minutos),
  - moderación: el admin puede ocultar mensajes; queda auditado.
- Los usuarios desactivados siguen apareciendo en el histórico del chat, marcados como inactivos.
- **Tiempo real** con Reverb. Paginación infinita hacia atrás.
- **Notificaciones del navegador** (Web Push mediante el service worker de la PWA).

### Transcripción de audios
- **Regla obligatoria: todo audio tiene siempre su transcripción.** Cada mensaje de audio se guarda con **las dos cosas**: el archivo de audio y su texto transcrito. No es opcional ni se puede desactivar.
- La transcripción se muestra bajo el audio (plegable), con opción de copiarla.
- **Todo se hace en el propio servidor, sin IA externa ni APIs de pago:** se usa **whisper.cpp** o **faster-whisper** (código abierto, MIT) con un modelo descargado localmente. Ningún audio sale del servidor.
- Se ejecuta en un **job de cola** de baja prioridad, con **un solo proceso de transcripción a la vez** y límite de CPU y memoria, para que nunca afecte al resto del servidor (sección 16). Mientras se procesa, el mensaje muestra "Transcribiendo…".
- Antes de elegir el modelo (`tiny`, `base`, `small`, `medium`), mide en el servidor real el tiempo y la RAM que consume cada uno con un audio de prueba de 1 minuto y propón el que dé buena calidad en español sin sobrecargar el servidor. Documenta la medición en `docs/DECISIONES.md`.
- **Garantía de que ningún audio se queda sin transcribir:**
  - si la transcripción falla, se reintenta automáticamente (por ejemplo 3 intentos con espera creciente),
  - una tarea programada revisa periódicamente (cada 15 minutos) los audios en estado `pending` o `failed` y los vuelve a poner en cola,
  - si un audio sigue fallando tras los reintentos, se avisa al admin, que puede relanzarlo manualmente,
  - mientras tanto, el audio se puede escuchar con normalidad y muestra "Transcribiendo…" o "Transcripción pendiente",
  - un comando Artisan (`transcriptions:backfill` o similar) transcribe cualquier audio que no tenga texto, por ejemplo tras una restauración o un cambio de motor,
  - hay tests que verifican que todo audio creado acaba con una transcripción asociada.
- Las transcripciones se **indexan en la búsqueda** del chat y en la búsqueda global: se puede buscar cualquier palabra dicha en un audio, y el resultado lleva directamente a ese mensaje.
- Diseña la transcripción detrás de una interfaz (`TranscriptionService`) para poder cambiar el motor más adelante sin tocar el resto del código.

---

## 13. Notificaciones

- **Canales:** en la app (campana y tiempo real), email y Web Push. Cada usuario elige qué recibe por cada canal.
- **Eventos:**
  - tareas: asignación, mención, comentario en una tarea que sigo, cambio de estado de una tarea que sigo, tarea que vence mañana o vencida,
  - horas: horas devueltas o aprobadas, recordatorio de enviar la semana (viernes),
  - bolsas: umbrales,
  - chat: mensaje directo, mención en el chat,
  - ausencias: solicitada, aprobada o rechazada.
- **Resumen diario opcional por email** en lugar de notificaciones sueltas.
- **Email saliente:** SMTP configurable en `.env`. Usa el servidor de correo existente o el que yo indique. Todos los emails van por cola. Plantillas en español con la identidad de la empresa.
- **Web Push:** claves VAPID generadas en el despliegue y guardadas en `.env`.

---

## 14. Administración

- Usuarios: alta por invitación, baja (desactivar, nunca borrar si tienen horas), rol, departamento, coste/hora, horario y 2FA obligatorio (opcional).
  - **Al desactivar un usuario**, se muestra un asistente para reasignar sus tareas abiertas y se para su temporizador si lo tiene activo. Su histórico de horas se conserva intacto.
- **Primer arranque:** un comando Artisan (`app:install` o similar) crea el primer admin, los departamentos, los estados y tipos por defecto y el proyecto interno.
- Departamentos.
- Tipos de tarea y estados.
- Festivos (con importación del calendario anual) y gestión de ausencias.
- Umbrales de alertas, redondeo del temporizador, aprobación obligatoria de horas, límite de adjuntos, permitir exceso en bolsas (sí por defecto).
- Plantillas de proyecto y tareas recurrentes.
- Identidad: nombre de la empresa, logo, colores (se usan en el portal de cliente y en los PDF).
- **Auditoría:** visor del registro de actividad con filtros.

---

## 15. Requisitos no funcionales

- **Seguridad:**
  - HTTPS obligatorio, CSRF, rate limiting en login y API, 2FA,
  - contraseñas con hash por defecto de Laravel,
  - política de sesiones,
  - adjuntos servidos mediante rutas firmadas con comprobación de permisos (nunca públicos),
  - validación de tipo MIME y escaneo opcional con ClamAV,
  - registro de inicios de sesión (correctos y fallidos) y pantalla de sesiones activas por usuario con opción de cerrarlas,
  - cabeceras de seguridad (CSP, HSTS, X-Frame-Options, etc.),
  - dependencias sin vulnerabilidades conocidas (`composer audit`, `npm audit` en la CI).
- **RGPD y aspecto laboral:**
  - la herramienta trata datos de productividad de empleados, así que se incluye un texto informativo configurable visible para los empleados,
  - política de retención de datos configurable,
  - exportación de los datos personales de un usuario,
  - ningún dato se envía a terceros (todo autoalojado, incluida la transcripción de audios).
- **Auditoría** de todo cambio en horas, bolsas, tareas y proyectos (quién, qué, antes y después, cuándo).
- **Backups:**
  - script y cron de copia diaria de la base de datos y del almacenamiento de adjuntos, con rotación (por ejemplo 7 diarias, 4 semanales, 6 mensuales),
  - **copia fuera del servidor** (destino que yo indique: otro servidor, almacenamiento S3 compatible, etc.),
  - comprobación periódica de que las copias se pueden restaurar,
  - documenta cómo restaurar.
- **Espacio en disco:** aviso al admin cuando el almacenamiento de adjuntos o el disco superen un umbral configurable.
- **Observabilidad:** logs estructurados, Horizon para las colas, página de salud (`/health`) y Laravel Pulse opcional.
- **Rendimiento:**
  - índices en `time_entries (date, user_id)`, `(project_id, date)`, `(hour_bank_id)` y en tareas por responsable y fechas,
  - vistas materializadas o tablas de agregados diarios si los dashboards lo necesitan,
  - caché de métricas invalidada por eventos.
- **Datos de ejemplo:** seeders realistas con 3 departamentos, 10 usuarios, 8 clientes, 15 proyectos, bolsas en distintos estados, 12 meses de horas, ausencias y festivos. Así los dashboards se pueden probar desde el primer día.
- **Internacionalización:** textos en ficheros de idioma (`es` completo), aunque solo se use español.

---

## 16. Servidor y despliegue (reglas de máxima prioridad)

El servidor de producción **ya está en uso**: aloja otras webs y servicios que **no pueden verse afectados**. La estabilidad del servidor está por encima de cualquier funcionalidad de esta app. Estas reglas prevalecen sobre cualquier otra instrucción de este documento.

### 16.1 Punto de partida
- Yo crearé un **subdominio** con su suscripción o espacio en el panel del servidor, y te daré acceso por SSH.
- Es posible que haya que **instalar o actualizar** alguna pieza (PHP, Redis, PostgreSQL, Node, Supervisor, Docker, etc.). Se puede hacer, pero **siempre según el procedimiento de esta sección**.

### 16.2 Primero, auditoría en solo lectura
Antes de cambiar nada, haz un reconocimiento **sin modificar nada** y guárdalo en `docs/SERVIDOR.md`:
- **Sistema:**
  - sistema operativo y versión, kernel,
  - CPU, RAM y swap, disco libre por partición,
  - carga media y uso actual de memoria.
- **Panel de control:** detecta si hay Plesk, cPanel, DirectAdmin, HestiaCP/VestaCP/myVesta, CloudPanel, otro o ninguno, y su versión.
- **Servicios existentes:**
  - servidor web (nginx, Apache o ambos) y quién gestiona su configuración,
  - versiones de PHP disponibles y cómo se asignan por dominio,
  - bases de datos (MariaDB/MySQL, PostgreSQL) y sus versiones,
  - Redis, Docker, Node, Supervisor o systemd, firewall (firewalld, ufw, iptables, CSF, fail2ban), servidor de correo, gestor de certificados,
  - puertos en escucha (`ss -tulpn`) y tareas cron existentes,
  - resto de sitios y servicios alojados, **solo para saber qué no se puede tocar**.
- **Permisos:** qué usuario te he dado, si tiene sudo o root, y qué puedes hacer sin privilegios.

Con esa auditoría, presenta una **propuesta de despliegue** que incluya:
- arquitectura en este servidor concreto,
- qué hay que instalar o actualizar y por qué,
- impacto previsto en el resto de servicios,
- consumo de recursos estimado,
- plan de vuelta atrás para cada cambio.

**Espera mi aprobación antes de ejecutar nada.**

### 16.3 Reglas de oro
1. **No toques nada fuera del espacio de la app**: ni otras suscripciones o dominios, ni sus vhosts, bases de datos, usuarios, cron o certificados.
2. **Prohibido sin mi aprobación expresa y previa:**
   - actualizaciones globales del sistema (`dnf/yum/apt upgrade` completos),
   - actualizar o cambiar la versión de PHP, MySQL/MariaDB, nginx o Apache que usan otros sitios,
   - reiniciar o recargar servicios compartidos (nginx, Apache, PHP-FPM global, base de datos, correo),
   - reiniciar el servidor,
   - modificar el firewall o abrir puertos,
   - cambiar la configuración de SSH, del panel, de DNS o del correo,
   - desinstalar paquetes,
   - cambiar ajustes globales (`php.ini` global, `my.cnf`, `sysctl`, límites del sistema).
3. **Instalar es aditivo:** instala solo lo necesario, **sin sustituir versiones que ya usan otros servicios**. Para PHP, usa la versión que ofrezca el panel para el subdominio o instala una versión adicional **en paralelo**, nunca reemplazando la existente.
4. **Respeta el panel de control:** si hay panel, crea las bases de datos, los certificados, los proxies, los PHP-FPM y las tareas programadas **desde el panel o con su CLI oficial**. Nunca edites a mano archivos de configuración que el panel regenera, porque se perderían los cambios o se rompería el panel. Si el panel ofrece "directivas adicionales de nginx" o plantillas por dominio, úsalas en lugar de tocar la configuración global.
5. **Aislamiento y límites de recursos:**
   - pool de PHP-FPM propio para el subdominio, con límites de procesos y memoria,
   - usuario de base de datos propio con permisos solo sobre su base,
   - si usas Docker: contenedores con límites de CPU y memoria, puertos publicados **solo en 127.0.0.1** y expuestos a internet únicamente a través del proxy del servidor web existente,
   - procesos de larga duración (colas, Reverb, scheduler) bajo **systemd o Supervisor** con el usuario del subdominio, reinicio automático y límite de memoria,
   - Redis protegido con contraseña, escuchando solo en local y con `maxmemory` definido. Si ya existe un Redis compartido, usa una base de datos o un prefijo propio, o una instancia separada; nunca hagas `FLUSHALL`.
6. **Antes de cada cambio en el sistema:**
   - copia de seguridad de cada archivo de configuración que vayas a tocar,
   - valida la configuración antes de aplicarla (`nginx -t`, `apachectl configtest`, `php-fpm -t`, etc.),
   - prefiere `reload` a `restart`, y hazlo solo con aprobación si el servicio es compartido.
7. **Después de cada cambio:** comprueba que **los demás sitios siguen respondiendo** (código HTTP de una muestra de dominios), que los servicios están activos y que el consumo de RAM y CPU es normal. Si algo falla, **revierte de inmediato** y avísame.
8. **Sin privilegios suficientes**, no intentes saltarte los permisos: dame los comandos exactos que debo ejecutar yo como root, explicando qué hace cada uno.
9. **Registro de cambios:** anota cada acción en el servidor en `docs/SERVIDOR-CAMBIOS.md`, con fecha y hora, comando, motivo, resultado y cómo revertirlo.
10. **Secretos:** `.env` con permisos `600`, fuera del directorio público, nunca en Git. El document root del subdominio apunta a `public/`.
11. **Ante cualquier cosa inesperada** (servicios caídos, errores en otros sitios, recursos al límite, configuración que no entiendes): **para, no improvises y pregúntame.**

### 16.4 Entornos y despliegue
- **Desarrollo local** con Docker (Sail o equivalente). Nunca se desarrolla directamente en producción.
- **Repositorio Git** (te indicaré el remoto). El despliegue en el servidor se hace desde Git con un script `deploy.sh`. Ese script:
  - activa el modo mantenimiento solo si hay migraciones que lo requieran,
  - hace `git pull`, `composer install --no-dev`, construye los assets (o los sube ya compilados si el servidor no debe tener Node) y lanza las migraciones,
  - limpia y regenera las cachés de Laravel y reinicia **solo** los procesos de la app (colas, Reverb),
  - hace un chequeo de salud al final y **vuelve a la versión anterior** si falla.
- **Entorno de pruebas (staging)** opcional en otro subdominio, si lo apruebo.
- Los subdominios o rutas necesarios (app y WebSockets de Reverb) se definen en la propuesta de la auditoría. Si hacen falta subdominios adicionales, pídemelos.
- Certificados SSL gestionados por el panel (Let's Encrypt) siempre que sea posible.
- `docs/DEPLOY.md` con:
  - arquitectura final en este servidor,
  - servicios y puertos,
  - variables de `.env`,
  - primer despliegue, actualización, vuelta atrás, backups, restauración y resolución de problemas.

---

## 17. Fases de desarrollo

> Cada fase termina con: tests en verde, seeders actualizados, `CLAUDE.md` y `docs/` actualizados y una demo funcional desplegable.

### Fase 0: Fundaciones
- **Auditoría del servidor en solo lectura y propuesta de despliegue** (sección 16.2). No se instala nada en producción en esta fase sin aprobación.
- Repositorio, Docker de desarrollo, Laravel + Inertia + React + TS + Tailwind + shadcn/ui.
- Comando de instalación inicial (primer admin y datos por defecto).
- **Tema visual de Audax Studio** (sección 3.1) en claro y oscuro, y página `/styleguide`.
- Autenticación con 2FA, roles y permisos, layout con barra lateral, **tema claro/oscuro**, PWA básica, búsqueda global (estructura).
- CI (lint, PHPStan, tests).
- **Aceptación:**
  - un usuario puede iniciar sesión, cambiar el tema y navegar,
  - los tests de permisos pasan,
  - la página `/styleguide` está aprobada por mí,
  - `docs/SERVIDOR.md` está entregado, con la propuesta de despliegue aprobada,
  - hay un primer despliegue en el subdominio, aunque sea con la app vacía, sin afectar a los demás sitios.

### Fase 1: Núcleo: clientes, proyectos, bolsas, tareas y horas
- Departamentos, usuarios con horario, clientes, proyectos (todos los tipos de facturación), **bolsas de horas con todas sus reglas**, tipos y estados de tarea, tareas (lista y kanban), subtareas, comentarios y adjuntos.
- Temporizador, entrada manual, hoja semanal, flujo de aprobación, proyecto interno por defecto.
- Alertas de bolsa (en la app y por email). Renovación de bolsa.
- **Aceptación:**
  - en una bolsa agotada con política `allow`, la imputación se registra como exceso (y se parte correctamente si cruza el límite),
  - en una bolsa con política `block`, la imputación que supera el saldo se rechaza,
  - no se puede imputar en una bolsa de otro departamento,
  - la renovación funciona y conserva el histórico,
  - la hoja semanal cuadra con las entradas,
  - hay tests de todas las reglas de la sección 8.

### Fase 2: Informes y dashboards
- Todas las métricas de la sección 10, los filtros globales, los seis dashboards, el informe detallado y la exportación a XLSX, CSV y PDF de bolsa.
- **Aceptación:**
  - las métricas coinciden con cálculos manuales en tests con datos controlados,
  - los dashboards cargan en menos de 1 s con los seeders de 12 meses.

### Fase 3: Capacidad, ausencias y carga futura
- Festivos, ausencias con aprobación, cálculo de capacidad y carga, vista "Carga" completa con reasignación desde el panel, bandeja "Sin planificar".
- **Aceptación:** tests del reparto de carga para casos con festivos, ausencias parciales, tareas vencidas, tareas sin fecha de inicio y jornada parcial.

### Fase 4: Gantt, hitos y dependencias
- Gantt por proyecto y multiproyecto, hitos, dependencias fin-inicio con detección de ciclos y aviso de conflictos, plantillas de proyecto, tareas recurrentes.
- **Aceptación:** crear, mover y enlazar desde el Gantt se refleja en la lista y en la carga. No se pueden crear ciclos.

### Fase 5: Portal de cliente
- Usuarios cliente, vista de bolsas, acceso opcional a proyecto y Gantt, PDF y notificaciones al cliente.
- **Aceptación:** tests de aislamiento. Un cliente nunca puede acceder a datos de otro cliente ni a la app interna, verificado con tests de todas las rutas.

### Fase 6: Chat
- Conversaciones de proyecto, directas y de grupo, tiempo real, emojis, reacciones, audios con transcripción automática local, adjuntos, menciones, hilos, edición, presencia, leídos, búsqueda, fijados, crear tarea desde un mensaje, Web Push.
- **Aceptación:**
  - dos usuarios en navegadores distintos chatean en tiempo real con todas las funciones (test E2E),
  - un audio de prueba en español se transcribe correctamente en el servidor, sin afectar a la carga de los demás servicios,
  - todo audio enviado acaba con su transcripción, incluso si el primer intento falla,
  - una palabra dicha en un audio aparece en la búsqueda.

### Fase 7: Pulido
- Notificaciones con preferencias, resúmenes por email, auditoría visible, backups, `DEPLOY.md` final y revisión de accesibilidad y de rendimiento.

---

## 18. Fuera de alcance (no implementar salvo que se pida)

- Multiempresa o SaaS, facturación o suscripciones.
- Facturación contable (solo exportamos datos para facturar).
- Apps nativas iOS/Android (basta con la PWA).
- Campos personalizados arbitrarios, automatizaciones tipo "si pasa X, haz Y", docs o wikis, objetivos/OKR, whiteboards.
- Dependencias distintas de fin-inicio. Reprogramación automática en cascada sin confirmación.
- Integraciones externas (Slack, Google Calendar, etc.) y API pública. Se pueden plantear más adelante.
- **Registro de jornada legal** (fichaje de entrada y salida). La imputación de horas a tareas **no** sustituye al registro obligatorio de jornada; si se quisiera, sería un módulo aparte.
- Chat con clientes (el portal de cliente es solo de consulta).
- **Importación o migración de datos desde ClickUp.** Se empieza con datos nuevos; los clientes, proyectos y usuarios se dan de alta desde la propia app.
- **Llamadas y videollamadas** de cualquier tipo (voz, vídeo, compartir pantalla). No se implementan.

---

## 19. Definición de "hecho" para cualquier funcionalidad

- Cumple la especificación y los criterios de aceptación de su fase.
- Tiene tests (unitarios y de feature; E2E si es un flujo crítico).
- Respeta los permisos, con tests de autorización.
- Funciona en tema claro y oscuro y en móvil.
- Tiene textos en español, estados vacíos y de carga, y errores comprensibles.
- No introduce consultas N+1 (verificado).
- Está documentada en `CLAUDE.md` o `docs/` si afecta a la configuración o al despliegue.
- Si se ha desplegado, los demás servicios del servidor siguen funcionando con normalidad y el cambio consta en `docs/SERVIDOR-CAMBIOS.md`.

---

**Empieza por la Fase 0.** Antes de escribir código, presenta el plan de la Fase 0 y cualquier duda que tengas sobre este documento. Recuerda: en el servidor, primero la auditoría en solo lectura.
