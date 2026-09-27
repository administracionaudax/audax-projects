# Decisiones

Registro de decisiones del proyecto. Las que cambian el SPEC llevan la etiqueta **[cambia el SPEC]**.

## 26/09/2026: Arranque de la Fase 0

### D-001 · Servidor de la app
`svr.ztudio.es` (185.33.65.98, **SSH por el puerto 5222**; el 22 está en DROP). La app va en `projects.audaxstudio.com`.

### D-002 · Desarrollo en el servidor **[cambia el SPEC §16.4 y §2 "Desarrollo local"]**
El usuario ha decidido no montar Docker en el Mac y desarrollar directamente en `projects.audaxstudio.com`.
- **Hasta que la plantilla empiece a usar la app:** `APP_DEBUG=false`, cabecera `noindex` y ningún dato real.
- **Antes de introducir datos reales:** se separan producción (`projects`) y desarrollo (otro subdominio) y se empieza a desplegar con `deploy.sh` desde GitHub.
- **Flujo:** la copia de trabajo está en el Mac; se sincroniza con `rsync` y los comandos se ejecutan por SSH con `nice`/`ionice`. Los E2E se ejecutan solo en la CI (GitHub Actions), nunca contra el servidor.

### D-003 · DNS
`audaxstudio.com` tiene el DNS en Cloudflare. El usuario crea los registros; nosotros le damos los datos exactos tras la auditoría.

### D-004 · Repositorio y CI
Repositorio privado en GitHub (cuenta `administracion@audaxstudio.com`), con CI en GitHub Actions. Motivos: copia del código fuera del servidor, historial, CI sin cargar el servidor y origen de `deploy.sh`.

### D-005 · Gestores de proyecto **[concreta el SPEC §5]**
Cada proyecto puede tener **varios gestores** (principal y co-gestores), elegidos en ese proyecto. Cada gestor puede escoger **qué alertas recibe** de ese proyecto. "Gestor" no es un rol global: es una relación por proyecto. Se implementa en la Fase 1.

### D-006 · Visibilidad del responsable de departamento **[concreta el SPEC §5]**
~~El responsable solo ve lo de su departamento.~~ **Sustituida por D-021** (26/09): los proyectos los ve todo el mundo; el responsable ve y gestiona solo las horas de su equipo.

### D-007 · Gráficas: Recharts
Motivos para elegir **Recharts** (MIT) frente a ECharts:
- se integra con los charts de shadcn/ui y hereda las variables CSS del tema (claro/oscuro sin configuración extra),
- es React declarativo y el bundle es más ligero.
El heatmap diario y la matriz de carga se hacen como componentes propios.

### D-008 · Base del proyecto
- **Starter kit oficial de Laravel con React:** Laravel 13, Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui, Fortify con 2FA y Wayfinder.
- **Pest 5** en lugar de PHPUnit.
- Sin registro público.
- `config.platform.php = 8.4` en Composer.

### D-009 · Licencias fuera de MIT/Apache/BSD
- **DM Sans:** OFL-1.1, la licencia estándar de fuentes libres, que permite uso comercial.
- **axe-core:** MPL-2.0, pero solo en tests; no llega al navegador.

### D-010 · Primer admin sin contraseña tecleada
`app:install` crea el admin e imprime un enlace de un solo uso para que fije su contraseña.

### D-011 · Azul de marca y contraste AA **[concreta el SPEC §3.1]**
`#0171FF` con texto blanco da 4,37:1 y no llega a AA (4,5:1). El SPEC permite ajustar la luminosidad en ese caso:
- **Botón principal:** `#0068EB` con texto blanco (5,02:1).
- **Enlaces y texto en azul:** `#005FD6` en claro (≥ 4,76:1 sobre todos los fondos, incluidos los tintados) y `#4A93FF` en oscuro (5,68:1).
- **`#0171FF` se mantiene** para la marca (logotipo, palabras destacadas en titulares grandes), los anillos de foco, las barras y la serie 1 de las gráficas.
- **Texto secundario:** `#56667A` en claro, en lugar del navy al 60 %, que no llega a 4,5:1 sobre los fondos tintados; blanco al 60 % en oscuro.
- **Bordes de campos de formulario:** navy al 50 % en claro (3,38:1) y blanco al 40 % en oscuro (3,71:1), por la regla de 3:1 para elementos no textuales. El resto de bordes mantiene el 20 % de marca.
- **Fondo informativo en oscuro:** `--info-soft` pasa a `#00275A`, para que el texto informativo cumpla sobre él (4,80:1).
- **Botón «sobre fondo oscuro»** (variante `onDark`): fondo blanco con texto `#005FD6` (5,82:1). El estilo «enlace» usa el token de texto azul para cumplir también en oscuro.
- Todos los pares, incluido cada color de estado sobre su fondo tintado, se verifican en `tests/js/theme-contrast.test.ts`.

### D-012 · Paleta de datos y estados
- **Categórica (tonos fríos, orden fijo, nunca se cicla):** validada con el validador de daltonismo (Machado 2009, OKLab) en los dos temas. ΔE adyacente con protanopía/deuteranopía: 17,1 en claro y 14,2 en oscuro (objetivo ≥ 8). Visión normal: 20,4 y 18,2 (mínimo 15). Todas las series ≥ 3:1 sobre la tarjeta.
  - **Claro:** azul `#0171FF` · turquesa `#179FA5` · violeta `#5E2DAD` · magenta `#E65FB3` · índigo `#3C41AE` · celeste `#0892C4`.
  - **Oscuro:** `#0260D9` · `#0D9298` · `#9D74F8` · `#B53087` · `#7685F8` · `#04729B`.
  - Con más de 6 series se agrupa en «Otros»; en gráficas de dispersión o mapas, máximo 3 series.
- **Estados:** verde, ámbar y rojo desaturados, siempre con icono y texto (texto ≥ 4,5:1 en los dos temas).
- **Semáforo de carga:** tintes neutro, azul, verde, ámbar y rojo con texto navy (≥ 4,5:1).
- **Departamentos por defecto:** Diseño `#0171FF`, Desarrollo `#179FA5`, Marketing `#5E2DAD` (editables).

### D-013 · Herramientas de frontend: Vite+ en lugar de ESLint y Prettier **[APROBADA por el propietario el 26/09; cambia el SPEC §2]**
El starter kit oficial trae **Vite+** (`vite-plus`, MIT), que incluye:
- **Oxlint** (lint),
- **Oxfmt** (formato),
- **Vitest 4** (tests).
Cumple la misma función que ESLint y Prettier, pero mucho más rápido y ya configurado. Comandos: `npx vp check` (lint + formato), `npx vp test run` y `npx vp build`.

### D-014 · Sesiones en base de datos
`SESSION_DRIVER=database` (PostgreSQL), para poder listar y cerrar las sesiones activas de cada usuario (SPEC §15). La caché y las colas van en Valkey.

### D-015 · Tipografía autoalojada
El kit cargaba la fuente desde Bunny Fonts, un CDN externo. Se sustituye por **DM Sans autoalojada** (`@fontsource/dm-sans`, OFL-1.1), subconjunto latino, pesos 400 y 500 y cursiva 400, por RGPD y rendimiento.

### D-016 · Idioma y URLs
- **Nombre de la app:** «Audax Proyectos».
- **URLs visibles en español** (`/proyectos`, `/ajustes/perfil`) y nombres de ruta en inglés. Las rutas de autenticación de Fortify mantienen las suyas (`/login`, `/forgot-password`).
- **Traducciones:** el frontend usa `lang/es.json`, importado en la compilación con `t()`; el backend usa `lang/es/*.php`.

### D-017 · Autenticación
Del kit se mantienen solo **2FA** y la **confirmación de contraseña**. Se eliminan:
- el registro público (el alta es por invitación),
- la verificación de email (la invitación ya la verifica),
- las passkeys (simplicidad).
Tampoco se puede **borrar la propia cuenta**: los usuarios se desactivan (SPEC §14).

### D-018 · Despliegue durante D-002
- **Cómo se despliega:** `scripts/desplegar-dev.sh` compila en el Mac (PHP 8.4 y Composer de Homebrew) y sincroniza con rsync.
- **Sin root** (desde el 26/09 ~13:10): el propietario añadió la clave al usuario `audaxprojects` (alias SSH `audax-projects`). El día a día (rsync, composer, migraciones, tests, Horizon) se hace con ese usuario, que solo puede tocar su webspace. Root (`audax`) se reserva para cambios de sistema aprobados (systemd, Docker, Plesk).
- **Tests en local:** en SQLite en memoria, para iterar rápido.
- **Tests completos:** en el servidor contra PostgreSQL 18 (`audax_projects_test`) y en CI, contra PostgreSQL 18.
- **Scheduler:** con `schedule:work` en systemd (no con cron), para no sumar sesiones de cron, PAM, logind y dbus.

### D-025 · Logotipo y favicon oficiales
Los ha pedido el propietario el 26/09 y se han sacado de audaxstudio.com: `Logo-01-audax-studio.svg` (500 × 83, navy) y `Favicon-audax-studio.svg` (el triángulo de la «A»). Los SVG no llevan scripts ni referencias externas.
- **En la app:** componente `AudaxWordmark` con `currentColor`, navy en claro y blanco en oscuro o sobre el degradado. `AudaxIsotype` es el triángulo, para la barra lateral contraída.
- **Ficheros:**
  - `public/brand/` (logo navy y blanco, isotipo),
  - `favicon.svg` (cambia a blanco con el tema oscuro del sistema),
  - `favicon.ico` (16/32/48),
  - `apple-touch-icon.png` y los iconos de la PWA: triángulo blanco sobre navy, con versión *maskable*.
- Si llega una versión oficial distinta (por ejemplo, con «Studio»), se sustituye en esos puntos.

### D-026 · Endurecimiento tras la revisión adversarial (26/09)
La revisión confirmó 42 hallazgos (unos 33 distintos): ningún crítico ni alto, 13 medios y el resto bajos. Todos corregidos, con tests:
- **Sesiones:**
  - `auth.session` (AuthenticateSession) en todo el grupo web,
  - cerrar una sesión rota el «Recordarme»,
  - cambiar la contraseña cierra las demás sesiones,
  - restablecerla cierra todas,
  - desactivar a un usuario cierra sus sesiones y su «Recordarme».
  Servicio `App\Auth\SessionTerminator`.
- **Registro público:** `/forgot-password` responde igual exista o no el correo, tiene límite por IP y por correo, y envía el email por la cola `mail`. El login iguala tiempos con un hash ficticio.
- **Contraseñas:** se quita `Password::uncompromised()`, que consulta el servicio externo HIBP, por la regla del SPEC «ningún dato a terceros». Se mantienen: mínimo 12 caracteres, mayúsculas y minúsculas, números y símbolos.
- **Correo del perfil:** se guarda en minúsculas, con índice único `lower(email)` en PostgreSQL, y **cambiarlo exige la contraseña actual**.
- **Accesos:** se registran los códigos 2FA fallidos. El panel de Horizon exige sesión, usuario activo y 2FA. Las rutas de Fortify pasan por `active`.
- **`app:install`** no convierte en admin a un usuario existente salvo con `--promote`.
- **Arranque y `/health`:** fuera de local y testing la app exige `SESSION_DRIVER=database`, y `/health` lo comprueba.
- **`robots.txt`** con `Disallow: /`, además del noindex. La extensión `unaccent` se crea por migración.
- **Accesibilidad:**
  - anillo de foco opaco (≥ 3:1),
  - texto sobre el degradado AA en toda su superficie (velo navy),
  - un `h1` por página,
  - etiquetas ARIA y textos de gráficas en español,
  - QR del 2FA sin invertir en oscuro.
- **Pendiente para cuando haya SMTP (Fase 7):** avisar al correo anterior cuando se cambie el correo.

## 26/09/2026: Dudas de la Fase 1 resueltas con el propietario

### D-019 · Exceso de bolsa en una sola entrada **[cambia el SPEC §4.4, §8.6 y la aceptación de la Fase 1]**
- **Qué se guarda:** una imputación que cruza el saldo de la bolsa **no se parte**. Se guarda una sola entrada con `overage_minutes`, que indica cuántos de sus minutos son exceso (de 0 a `minutes`). `is_overage` pasa a ser un valor derivado (`overage_minutes > 0`).
- **Cómo se ve:** informes, portal y facturación muestran por separado las horas en bolsa (`minutes − overage_minutes`) y las de exceso.
- **Recálculo:** al editar o borrar entradas de una bolsa, se recalcula `overage_minutes` de las entradas **no bloqueadas**, en orden cronológico (`date` y después `created_at`). Las bloqueadas no cambian nunca.
- **Aceptación de la Fase 1:** con política `allow`, una imputación que cruza el límite registra correctamente sus minutos de exceso. Con `block`, se rechaza indicando el saldo disponible (sin cambios respecto al SPEC).

### D-020 · Aprobación de horas **[concreta el SPEC §7]**
- Las horas de un **empleado** las aprueba (o **devuelve con comentario**) un responsable de su departamento. Si hay varios, cualquiera de ellos (D-024).
- Las horas de **responsables y administradores se aprueban solas** al enviar la semana.
- Un empleado **sin departamento** lo aprueba un administrador.
- Estados de la semana: abierta, enviada, **devuelta** (con comentario; vuelve a ser editable), aprobada y bloqueada.
- El ajuste global «aprobación obligatoria», si se desactiva, aprueba todo al enviar.

### D-021 · Visibilidad de proyectos y horas **[cambia el SPEC §5 y §5.1]**
- **Todos los usuarios internos ven todos los proyectos:** ficha, tareas, Gantt, chat del proyecto y consumo de las bolsas en %.
- **Imputar horas** sigue reservado a los **miembros del proyecto** (SPEC §7), con la restricción de departamento de la bolsa. Ser miembro también determina quién participa en el chat del proyecto y quién recibe notificaciones.
- **Horas, carga y productividad por persona:**
  - un empleado solo ve las **suyas**,
  - un responsable ve y gestiona (aprobar, devolver, reasignar carga) las de **las personas de su departamento**,
  - un gestor ve las horas imputadas **a sus proyectos**,
  - un administrador lo ve todo.
- El detalle por persona de las bolsas y la pestaña «Horas» de un proyecto con las entradas de todos solo lo ven gestores del proyecto, responsables (con las horas de su equipo) y administradores. El empleado ve solo sus entradas.
- Los datos económicos siguen protegidos por `view-financials`.

### D-022 · Altas de clientes y proyectos
- Crean **clientes y proyectos**: administradores y responsables de departamento.
- Los **gestores** gestionan los proyectos que tienen asignados: tareas, bolsas, planificación, miembros y sus alertas.
- Los **empleados** no crean.

### D-023 · Alertas de los gestores **[concreta D-005]**
- Cada gestor elige **sus** alertas en cada proyecto. Por defecto están todas activadas: umbrales de bolsa, exceso, etc.
- Un administrador también puede ajustarlas.
- Se guardan en el pivote `project_members`, con `is_manager` y `alert_preferences` (JSON).

### D-024 · Varios responsables por departamento **[cambia el SPEC §4.1]**
- `departments.manager_user_id` se sustituye por el pivote **`department_managers`** (`department_id`, `user_id`).
- Todos los responsables de un departamento tienen los mismos permisos: aprobar horas y ausencias de su equipo, y ver y gestionar su carga y productividad.
- El rol `department_manager` indica que el usuario puede ser responsable. Qué departamentos gestiona lo marca el pivote.

## 26/09/2026: Modo autónomo hasta el final del proyecto

### D-027 · Modo autónomo **[acordado con el propietario]**
- **Cómo se trabaja:** desde la Fase 1, fase tras fase sin esperar aprobaciones. El plan de cada fase queda en `docs/PLAN-FASE-N.md`. Las decisiones de producto se toman según el SPEC y las decisiones previas y se registran aquí, en «Decisiones tomadas en autonomía», para que el propietario las revise cuando quiera.
- **Servidor:** se permite lo necesario, con salvaguardas.
  - **Permitido:** recargas *graceful* de nginx y Apache vía Plesk solo por ajustes de `projects.audaxstudio.com`; unidades y timers systemd de la app; contenedores Docker propios con límites; directivas de Plesk del dominio.
  - **Prohibido siempre:** actualizaciones globales, reiniciar servicios compartidos, firewall, SSH, DNS, correo, desinstalar y tocar otras webs.
  - **En cada cambio:** copia previa, batería V, comparación de las webs, vuelta atrás inmediata ante cualquier desviación y registro en `SERVIDOR-CAMBIOS.md`. Si algo inesperado no se puede revertir, se avisa al propietario.
- **Solo se contacta al propietario para:**
  - los datos SMTP (al final),
  - la lista de empleados (al final),
  - revisar el texto RGPD (quedará un borrador marcado «pendiente de asesor»),
  - un imprevisto del servidor que no se pueda revertir.

### D-028 · Entorno único **[cambia D-002]**
No se separan producción y desarrollo: todo sigue en `projects.audaxstudio.com`. Cuando la plantilla empiece a usar la app:
- el despliegue pasa a `deploy.sh`, con modo mantenimiento solo si hay migraciones y vuelta atrás automática,
- los E2E y los seeders de ejemplo siguen sin ejecutarse nunca en el servidor.

### D-029 · Copias de la base de datos
Además de la copia diaria del servidor entero (que hace el propietario), se hace un **volcado nocturno local de PostgreSQL**:
- `pg_dump` en formato *custom* en `/var/backups/audax`, con rotación de 7 días,
- se verifica con `pg_restore --list`,
- así la copia del servidor siempre contiene un volcado coherente y restaurable (un volcado de ficheros de PostgreSQL en caliente no lo garantiza).

### D-030 · SMTP y empleados, al final
- **SMTP:** hasta que el propietario facilite los datos, `MAIL_MAILER=log`. Todos los emails van por la cola `mail` y funcionarán al configurar el `.env`.
- **Empleados:** la lista real llega al final. Mientras tanto se trabaja con los seeders de desarrollo (nunca en el servidor).

## 26/09/2026: Decisiones tomadas en autonomía (Fase 1)

### D-031 · Quién trabaja con las tareas **[concreta D-022]**
- **Crear, editar, mover y borrar:** los miembros del proyecto, sus gestores, los responsables y los administradores.
- **Comentar:** cualquier usuario interno (todos ven todos los proyectos, D-021).
- «Los empleados no crean» (D-022) se refiere a clientes y proyectos, no a tareas.

### D-032 · Gestor principal
- **`owner_user_id` es el gestor principal y es obligatorio.** Siempre es miembro con `is_manager`. Los co-gestores son miembros con `is_manager`.
- El filtro «responsable» del listado de proyectos usa el gestor principal.

### D-033 · Proyecto interno
En los proyectos con `billing_type = internal`, cualquier interno activo puede imputar sin ser miembro (reuniones, formación, administración).

### D-034 · Bloqueo y reapertura de horas
- **Bloquear y desbloquear:** solo un administrador, por cliente o proyecto y un rango de fechas («al facturar»). Queda en la auditoría.
- **Semana enviada:** el propio usuario puede **retirarla** mientras no esté revisada.
- **Semana aprobada:** la reabre quien puede aprobarla (o un administrador).
- **Semana bloqueada:** solo un administrador puede reabrirla.
- **Semana devuelta:** las entradas vuelven a borrador. El comentario se guarda en `timesheet_periods.review_comment` y el historial en la auditoría.
- **Aprobación automática:** se aprueba sola la semana de quien tiene el **rol** de responsable o de administrador. Si el departamento no tiene responsables, aprueba un administrador.
- **Instantáneas:** la tarifa y el coste se congelan cada vez que una entrada pasa a aprobada (también en la aprobación automática). Reabrir las borra y las entradas bloqueadas no cambian nunca.

### D-035 · Cálculo de las bolsas **[concreta D-019]**
- **Recálculo cronológico:** una entrada con fecha anterior puede desplazar el exceso a entradas posteriores no bloqueadas.
- **Exceso de la bolsa:** es la suma de `overage_minutes` de sus entradas. Cambiar el total o la política recalcula.
- **Política `block`:** solo impide imputaciones **nuevas** que superen el saldo; el exceso existente se conserva.
- **Temporizador sobre una bolsa `block` sin saldo:** no se puede iniciar. Si al pararlo supera el saldo, no se guarda: se ofrece ajustar la duración o cambiar de tarea, sin perder lo medido.
- **Alertas:**
  - cada umbral avisa **una sola vez** por bolsa; el exceso, como máximo una vez al día,
  - una bolsa sin departamento avisa a sus gestores y a los administradores.
- **Estados:**
  - una bolsa agotada vuelve a activa si baja el consumo,
  - un administrador puede reabrir una bolsa cerrada si no está renovada,
  - «próxima a agotarse» significa a partir del primer umbral configurado (75 % por defecto).
- **Color de la barra de consumo:** verde por debajo del primer umbral, ámbar hasta el 100 % y rojo desde el 100 %.
- **Vista global de bolsas:** los gestores ven las de sus proyectos; responsables y administradores, todas.

### D-036 · Imputación de horas
- **Imputar por otra persona:**
  - un gestor, en sus proyectos,
  - un responsable, para las personas de su departamento,
  - un administrador, para cualquiera.
  La persona destino debe cumplir las reglas (ser miembro y el departamento de la bolsa).
- **Capacidad en la F1:** solo `WorkSchedule`. Nuevo ajuste `default_work_minutes` (lunes a viernes 8 h; sábado y domingo 0) que recibe cada usuario nuevo. Festivos y ausencias llegan en la F3.
- **Formatos de duración:** `1:30`, `1.5`, `1,5`, `90m`, `1h30`, `2h`, y un número suelto se lee como horas. Máximo 24 h, con una vista previa en vivo («= 1:30»).
- **Temporizador:**
  - redondeo al múltiplo más cercano de `timer_rounding_minutes`; si queda en 0, se descarta con aviso,
  - si cruza la medianoche de Madrid, se parte en entradas por día,
  - iniciar otro temporizador para el anterior y lo imputa.
- **Hoja semanal:** «Copiar la semana anterior» copia las filas (tareas), no las horas.

### D-037 · Tareas, proyectos y adjuntos
- **Mis tareas** (solo las abiertas asignadas a mí):
  - vencidas: `due_date` anterior a hoy,
  - hoy: `due_date` o `start_date` hoy,
  - esta semana: `due_date` hasta el domingo,
  - próximas: el resto con fecha,
  - sin fecha.
- **Subtareas:** mismo proyecto y misma bolsa que la tarea padre. La estimación del padre es la suma de las de sus subtareas.
- **Estados «En revisión» y «Bloqueada»:** tienen la categoría `in_progress`.
- **Nada con horas se borra:**
  - las tareas con horas no se pueden borrar,
  - los proyectos se archivan,
  - los clientes se desactivan.
- **Adjuntos:**
  - formatos permitidos: imágenes, PDF, ofimática (Office y ODF), texto, CSV y ZIP,
  - miniatura solo de imágenes rasterizadas,
  - los SVG siempre se descargan, nunca se muestran en la página.
- **Fuera de la F1:**
  - calendario de tareas y próximos hitos, a la F4,
  - exportación XLSX y CSV, a la F2,
  - campana en tiempo real, a la F6 (en la F1 se consulta cada 60 s).

## 26/09/2026: Decisiones tomadas en autonomía durante la implementación de la Fase 1

### D-038 · Administración
- **Rol de admin:** solo un admin puede darlo o gestionar a otro admin. Nadie se quita su propio rol de admin, y no se puede degradar ni desactivar al último admin activo.
- **Baja de una persona** (asistente):
  - reasigna sus tareas abiertas, en bloque o una a una (si la nueva persona no es miembro del proyecto, pasa a serlo),
  - traspasa la gestión principal de sus proyectos no archivados (D-032),
  - para su temporizador (si no se puede imputar, se descarta y se avisa),
  - la quita de responsable de sus departamentos, para que las aprobaciones no esperen a alguien de baja.
- **Invitaciones:** el enlace dura 7 días y es de un solo uso. Tienen su propio *broker* (`invitations`) con su propia tabla (`invitation_tokens`) y se aceptan en `/invitacion/{token}`. El restablecimiento de contraseña sigue en 60 minutos: un token de restablecimiento no vale como invitación, y pedir un restablecimiento no invalida una invitación.
- **Jornadas:** una versión nueva empieza como pronto mañana; la primera de una persona puede empezar hoy. No se reescriben versiones ya iniciadas. Los días se editan con un campo propio que admite 0:00.
- **Departamentos:** solo se borran si no tienen personas (activas ni de baja) ni bolsas abiertas.
- **Estados:** el estado por defecto nunca es «done». Al borrar un estado, sus tareas pasan al final de la columna del estado de reemplazo. Los cambios masivos dejan una sola entrada en la auditoría.
- **`app:install`:** solo siembra catálogos vacíos, así que no recrea lo que el admin haya renombrado.

### D-039 · Proyectos y bolsas
- **Código de proyecto:** opcional al crear. Se genera a partir del cliente y del nombre (`-2`, `-3`… si ya existe) y es único aunque el proyecto esté borrado.
- **Cliente:** obligatorio salvo en proyectos internos. Solo se eligen clientes activos, aunque al editar se conserva el actual si se ha desactivado.
- **Pasar un proyecto con tareas a «bolsas de horas»:** se pide su primera bolsa en el mismo paso.
- **Desarchivar:** el proyecto recupera el estado anterior, que se toma de la auditoría.
- **Renovar una bolsa:**
  - solo una bolsa agotada o «próxima a agotarse» (desde el primer umbral, SPEC §8.7). Lo comprueban la interfaz y el servidor, con lo que va dentro de la bolsa (D-054). Para acabar antes un contrato, se cierra la bolsa (§8.9) y se crea otra,
  - las tareas abiertas se mueven con todas sus subtareas; las horas nunca.
- **Borrar una bolsa:** solo un admin, sin horas, sin tareas y sin renovación. Borrar una renovación devuelve la anterior a su estado.
- **Destinatarios de las alertas de bolsa:** gestores con la alerta activada, responsables del departamento de la bolsa y todos los admins, sin duplicados. Las preferencias de los admins llegan en la F7.
- **Horas comprometidas:** cuentan las de las subtareas, no las del padre que se calcula a partir de ellas. Las completadas y los hitos cuentan 0.
- **Gráfica semanal de una bolsa:** lo que va dentro de la bolsa en `--chart-1`; el exceso, siempre en el rojo de estado (SPEC §8.6).

### D-040 · Tareas y adjuntos **[cambia el plan de la Fase 1]**
- **Miniaturas:** se generan con **GD en un job de Horizon**, no en la petición y no con Imagick (el plan decía «Imagick con límites»).
  - Se descartan las imágenes enormes leyendo solo su cabecera.
  - Se guardan en WebP y se corrige la orientación EXIF.
  - Hasta que termina el job, se ve el icono del tipo.
- **Subidas:** como mucho 10 ficheros por vez. Subir a una tarea exige poder editarla; a un comentario, poder comentar.
- **Mover tareas:** solo se mueven las tareas raíz, con sus subtareas. Sus adjuntos cambian de proyecto en la pestaña Archivos.
- **Temporizador en marcha:** mientras hay uno, la tarea no se puede mover ni cambiar de bolsa.
- **Borrar tareas:** se bloquea si la tarea o sus subtareas tienen horas, o si hay un temporizador en marcha.
- **Acciones masivas:** son todo o nada.
- **Aviso de vencimientos:** un solo resumen al día por persona (vencen mañana y vencidas), a las 08:00 de Madrid.
- **Adjuntos de una tarea o un comentario borrados:** dejan de descargarse aunque la URL firmada siga vigente.

### D-041 · Horas
- **Aprobación automática** (responsables, admins o aprobación desactivada): la semana queda aprobada sin revisor y la interfaz la muestra como «aprobada automáticamente», sin notificación.
- **Reabrir una semana bloqueada:** la deja abierta, pero sus entradas bloqueadas no cambian. Para cambiarlas hay que deshacer el bloqueo.
- **Desbloquear:** cada entrada vuelve al estado de su semana (aprobada, enviada o borrador; en los dos últimos casos pierde las instantáneas de tarifa y coste) y se recalculan sus bolsas. Así nunca queda una entrada aprobada en una semana editable.
- **Filas sin horas de la hoja semanal** (añadidas a mano o copiadas de la semana anterior): no se guardan en la base; el navegador las recuerda hasta que se imputa en ellas.
- **Hojas de otras personas:**
  - un gestor abre la hoja de los miembros de sus proyectos, solo con las entradas de esos proyectos,
  - un responsable, la de su equipo,
  - un admin, la de cualquiera.
- **Temporizador y entrada manual:** no devuelven 403 a quien no puede imputar en una tarea; explican el motivo con los errores de las reglas.

### D-042 · Contrato técnico
- **Resources:** se envían a Inertia **sin envoltorio `{data}`** (`JsonResource::withoutWrapping()`), también los anidados; las colecciones paginadas mantienen `{data, links, meta}`. Algunas áreas pasan además sus props por un ayudante propio (`Plain::of`, `ResourceData::of`, `ResourceProps`), que sigue siendo válido.
- **Tests:** necesitan 512 MB de memoria en un solo proceso (`phpunit.xml`).

## 26/09/2026: Decisiones tomadas en autonomía en la revisión global de la Fase 1

_Numeradas D-053 a D-055 porque la Fase 2 ya había reservado D-043 a D-048 y la Fase 3, D-049 a D-052._

### D-053 · Bolsas cerradas y renovadas: sus horas quedan fijas **[concreta el SPEC §7, §8.4, §8.7 y §8.9]**
- **Qué se puede hacer con una entrada de una bolsa cerrada o renovada:**
  - cualquiera que pueda editarla corrige su **descripción** (o si es facturable): no cambia el consumo,
  - cambiar sus **minutos, su fecha o su tarea**, o **borrarla**, solo lo hace un **administrador** (queda en la auditoría). A los demás se les explica que se lo pidan.
- **Por qué:** el saldo registrado al cerrar (SPEC §8.9) y el histórico de la renovación (las horas nunca se mueven, SPEC §8.7) no deben cambiar sin control. Antes se bloqueaba incluso corregir la descripción, pero se podía borrar la entrada o pasarla a otra tarea.
- **Saldo registrado:** si un admin corrige las horas de una bolsa cerrada, `closed_remaining_minutes` se recalcula con su consumo.

### D-054 · Cifras de las bolsas **[concreta D-019 y D-035]**
- **Una sola fuente para el saldo:** saldo = total − lo que va dentro (consumo − exceso). La usan `HourBankLedger::available()`, `remaining_minutes`, la regla `block`, el paso a agotada (agotada cuando lo que va dentro llega al total), el filtro «próximas», la renovación y las barras. Sin exceso de bloqueadas es lo mismo que total − consumo; con una bloqueada en exceso y el total ampliado después, el saldo es el real.
- **El admin edita una entrada bloqueada** (minutos, fecha o bolsa): su exceso se recalcula una vez, como si no estuviera bloqueada, y queda entre 0 y sus minutos. Las demás bloqueadas no cambian.
- **Proyecto archivado:** no admite bolsas nuevas ni renovaciones (como no admite tareas), y sus bolsas no salen como abiertas en la vista global (sí en «todas», como histórico).
- **Subtareas:** no se crean bajo una tarea que se ha quedado en una bolsa cerrada o renovada; antes hay que mover la tarea a una bolsa abierta.
- **Barras:** ámbar desde el primer umbral configurado en todas las pantallas; el color, el saldo y el exceso salen de las cifras del servidor.

### D-055 · Otros ajustes de la revisión global de la Fase 1
- **Descripción obligatoria:** el temporizador la pide al pararlo (o al iniciar otro, si el que está en marcha no la tiene) y la hoja semanal abre el diálogo de la entrada; nunca se pierde lo medido.
- **Enlaces de las notificaciones de tareas:** `/tareas/{id}`, que abre la tarea en el proyecto que tenga al pulsar.
- **Baja de una persona:** quien recibe sus tareas pasa a seguirlas y recibe el aviso de asignación.
- **Imputar por otra persona:** un gestor, solo por los miembros de su proyecto (también en proyectos internos).
- **Aprobaciones:** la página carga los totales de cada semana; las entradas se piden al desplegar su detalle.
- **Páginas de error:** 403, 404, 419, 429, 500 y 503 con una página propia en español y con el tema de la app en las visitas de página completa. Las acciones dentro de una página tratan sus errores sin salir de ella.

## 26/09/2026: Decisiones tomadas en autonomía (Fase 2)

_Detalle y contexto en `docs/PLAN-FASE-2.md`. Las que surjan al implementar se añaden al cerrar la fase._

### D-043 · Ingreso estimado **[concreta el SPEC §10]**
- **Qué cuenta:** solo las horas facturables (`is_billable`). Los proyectos internos dan 0.
- **Tarifa de cada entrada:** la instantánea si está aprobada o bloqueada. Si no, la vigente según la prioridad bolsa > proyecto > cliente > persona (la misma de la aprobación, D-034).
- **Bolsas con `price_amount`:** los minutos dentro de la bolsa valen `price_amount × minutos dentro / total de la bolsa`; el exceso se valora a la tarifa de la bolsa (o la siguiente en la prioridad). Una bolsa sin `price_amount` se valora entera a tarifa.
- **Precio cerrado:** el importe se reparte según el avance.
  - La base es el mayor de: el presupuesto de horas, la suma de las estimaciones de las tareas raíz y lo imputado hasta hoy.
  - El ingreso de un periodo es `importe × minutos facturables del periodo / base`, y lo acumulado nunca supera el importe.
- **Por horas sin bolsa:** minutos facturables × tarifa.
- **Coste:** minutos × la instantánea de coste si existe; si no, el coste por hora actual de la persona.
- **Rentabilidad:** ingreso − coste, y el margen en %. Solo con `view-financials`.

### D-044 · Quién ve cada informe **[concreta D-021 para la F2]**
| Informe | Admin | Responsable | Gestor de proyecto | Empleado |
|---|---|---|---|---|
| Dirección | ✅ todo | ✅ limitado a su departamento | ❌ | ❌ |
| Departamento | ✅ | ✅ los suyos | ❌ | ❌ |
| Cliente | ✅ | ✅ | ✅ solo sus proyectos | ❌ |
| Proyecto | ✅ | ✅ | ✅ los suyos | ❌ |
| Persona | ✅ | ✅ su equipo | ❌ | ✅ solo la suya |
| Detallado | ✅ | ✅ con `TimeEntry::visibleTo` | ✅ con `visibleTo` | ✅ solo lo suyo |

- **Datos económicos** (ingreso, coste, margen, tarifas): solo con `view-financials`.
- **Agregados sin datos por persona** (horas por proyecto, bolsa o tipo): se ven en las fichas a las que ya se tiene acceso.

### D-045 · Exportación
- **XLSX y CSV:** con **OpenSpout 5.12** (MIT), en streaming y sin cola hasta 20.000 filas. La exportación respeta los filtros y los permisos del informe.
- **PDF de consumo de bolsa:** con **FPDF 1.9** (MIT). Usa las fuentes estándar del PDF (Helvetica), porque incrustar DM Sans exigiría convertirla con herramientas externas; la marca va en el logotipo (dibujado en vectorial o como PNG) y en los colores. Incluye:
  - logo y nombre de la empresa,
  - cliente, proyecto y bolsa,
  - barra de consumo, dentro y exceso por separado,
  - consumo por mes y listado de entradas aprobadas con fecha, persona, tarea, horas y descripción.
  Pensado para enviárselo al cliente: solo lleva las horas aprobadas o bloqueadas, como hará el portal de la F5.
- **Exportación para facturar:** por cliente y periodo, con el detalle de cada entrada (dentro de bolsa y exceso por separado) y sus tarifas si hay `view-financials`.

### D-046 · Rendimiento
- **Cálculo:** agregados SQL sobre `time_entries` con sus índices (fecha y persona; proyecto y fecha; bolsa y fecha), sin cargar modelos, y la capacidad con `Capacity::forRanges`.
- **Caché:** por combinación de filtros y persona que consulta, con una versión global que se incrementa al escribir entradas, tareas, bolsas o jornadas. Así se invalida al momento sin etiquetas.
- **Presupuesto:**
  - menos de 1 s en el servidor con los datos de 12 meses (medido en el despliegue),
  - un test de presupuesto de consultas por dashboard, como `Phase1PagesPerformanceTest`.

### D-047 · Resumen semanal de productividad
- **Cuándo y a quién:** los lunes a las 08:00 de Madrid, por email (cola `mail`) a cada responsable, sobre su equipo y la semana anterior. Los admins lo reciben de toda la agencia.
- **Contenido:**
  - días sin imputar por persona,
  - ocupación por encima o por debajo de los umbrales,
  - bolsas en riesgo (≥ primer umbral),
  - tareas vencidas.
- **Nuevos ajustes:** `occupancy_low_threshold` (70 %), `occupancy_high_threshold` (110 %) y `weekly_digest_enabled` (sí).

### D-048 · Capacidad en la F2
En la F2 la capacidad sale de `Capacity`, que solo usa `WorkSchedule`. Cuando la F3 añada festivos y ausencias a `Capacity`, todos los informes los tendrán en cuenta sin más cambios.

### D-078 · «Dentro de bolsa» **[concreta D-019 y D-044 para los informes]**
- **Qué cuenta:** en todas las cifras (resúmenes, desgloses, tabla dinámica, exportaciones, PDF y la pestaña Horas del proyecto), solo los minutos de las entradas **con bolsa**, menos su exceso (`PivotReport::IN_BANK_SQL`). Las horas de proyectos sin bolsa nunca cuentan como «dentro de bolsa».
- **Qué se corrige:** el contrato de la F2 lo calculaba como imputadas − exceso, incluyendo horas sin bolsa; se alinearon `Metrics::summary()` y `breakdown()` y el total de la pestaña Horas de la F1.

### D-079 · Ocupación y comparación de un periodo en curso **[concreta el SPEC §10]**
- **Ocupación y productividad facturable:** siempre contra la capacidad del **periodo completo**, como define el SPEC. Los dashboards muestran además la capacidad transcurrida hasta hoy, para leer el ritmo a mitad de periodo.
- **Comparación con el periodo anterior (`comparar=1`) en un periodo en curso:** «al mismo punto», con los mismos días transcurridos del periodo anterior (`App\Domain\Reports\ComparisonPeriod`, común a todos los dashboards). La barra de filtros enseña el tramo comparado.
- **Variación:** por debajo de medio punto se muestra «Igual que en el periodo anterior» (no «0 % más»).

## 27/09/2026: Decisiones tomadas en autonomía (Fase 3)

_Detalle y contexto en `docs/PLAN-FASE-3.md`._

### D-049 · Ausencias
- **Solicitar:** cada persona solicita las suyas (tipo, fechas, día completo o parte del día con `partial_minutes`, y notas).
- **Aprobar o rechazar:** un responsable de su departamento o un admin (como las horas, D-020). El rechazo lleva un comentario.
  - Las de responsables y admins **se aprueban solas**.
  - Un admin o un responsable puede registrar una ausencia **ya aprobada** para alguien de su ámbito (una baja, por ejemplo).
- **Cancelar:**
  - la persona cancela las suyas solicitadas, o las aprobadas que aún no han empezado,
  - quien aprueba puede anular una aprobada; queda en la auditoría.
- **Validaciones:** sin solaparse con otra ausencia solicitada o aprobada de la misma persona. `partial_minutes` solo en ausencias de un día. Máximo un año por ausencia.
- **Notificaciones** (SPEC §13), en la app y por email por la cola `mail`:
  - «solicitada», a quien puede aprobar,
  - «aprobada» y «rechazada», a la persona.
- **Al imputar un día con ausencia aprobada** sale un aviso sin bloqueo (SPEC §7): nuevo aviso en `TimeEntryRules`.

### D-050 · Festivos
- **Administración:** en `/admin/festivos` (`manage-settings`) se crean, editan y borran por año.
- **Importación:**
  - **festivos nacionales de España del año**, calculados en local sin servicios externos (SPEC §15: nada a terceros): 1 y 6 de enero, Viernes Santo, 1 de mayo, 15 de agosto, 12 de octubre, 1 de noviembre y 6, 8 y 25 de diciembre;
  - los autonómicos y locales se añaden a mano o importando un fichero `.ics` o un CSV (`AAAA-MM-DD;Nombre`).
- **Alcance:** afectan a todas las personas; `scope` queda en `company`.

### D-051 · Reparto de la carga (lo que hace `WorkloadPlanner`, ya probado)
- **Qué se reparte:** el restante (estimación − imputado), a partes iguales entre los días con capacidad > 0 desde max(hoy, inicio) hasta la entrega; los minutos que sobran van a los primeros días.
- **Casos especiales:**
  - una tarea vencida lleva todo su restante a hoy y se marca,
  - sin ningún día con capacidad en el rango, todo va al primer día,
  - como mucho se calcula un año hacia delante.
- **Qué no cuenta:** los hitos, las tareas completadas y los proyectos archivados. Con subtareas, cuentan las subtareas y no el padre.
- **Bandejas:**
  - «Sin planificar»: tareas con responsable pero sin estimación o sin entrega,
  - «Sin asignar»: tareas por departamento, el de la bolsa o, si no, el del tipo.

### D-052 · Vista «Carga» y quién la ve (D-021)
- **Quién ve qué:**
  - admin: todo,
  - responsable: su departamento (y él mismo), y puede reasignar carga,
  - el resto: solo su propia fila.
  - Los gestores ven y reasignan las tareas de sus proyectos desde el panel, pero no ven la carga de personas de otros departamentos.
- **Matriz personas × días (o semanas en el horizonte de 3 meses):**
  - horizontes: semana actual, **semana que viene (por defecto)**, próximas 4 semanas y próximos 3 meses,
  - agrupada por departamento, con totales,
  - filtros: departamento, persona, cliente y proyecto.
- **Semáforo de cada celda** (horas planificadas / capacidad), el de `components/charts/thresholds.ts`, siempre con icono y texto:
  - gris: sin capacidad, con su motivo (festivo o ausencia),
  - azul: menos del 70 %,
  - verde: del 70 % al 100 %,
  - ámbar: del 100 % al 120 %,
  - rojo: más del 120 %.
- **Panel de una celda:** las tareas que forman esa carga, con los minutos de ese día, y se reasignan ahí mismo (responsable y fechas) con las reglas de Tareas (`TaskPolicy::update`, `TaskWriter`). La matriz se recalcula al momento.
- **Bandejas «Sin planificar» y «Sin asignar»:** en la misma página, con acciones rápidas para poner la estimación, las fechas o el responsable.

## 27/09/2026: Decisiones tomadas en autonomía (Fase 4)

_Detalle y contexto en `docs/PLAN-FASE-4.md`._

### D-056 · Dependencias **[concreta el SPEC §4.3 y §6.1]**
- **Tipo:** solo fin-inicio (`finish_to_start`), y solo entre tareas **del mismo proyecto**. Los hitos participan como cualquier tarea.
- **Reglas:**
  - una tarea no puede depender de sí misma,
  - no se pueden crear ciclos, ni directos ni indirectos (búsqueda en anchura sobre las dependencias del proyecto),
  - enlazar dos veces lo mismo no duplica.
- **Quién:** quien puede editar **las dos** tareas (`TaskPolicy::update`).
- **Tareas borradas:** una tarea en la papelera no cuenta en las dependencias. Al borrarla del todo, sus dependencias se borran en cascada.
- **Contrato:** `App\Domain\Schedule\DependencyService` y las rutas `schedule.dependencies.*`.

### D-057 · Conflictos al mover **[concreta el SPEC §6.1]**
- **Cuándo hay conflicto:** una sucesora está en conflicto si empieza (o, si no tiene inicio, vence) el mismo día o antes de que acabe su predecesora.
- **Propuesta:** llevar cada sucesora en conflicto al día siguiente del fin de su predecesora.
  - Conserva su duración en **días naturales**, que es sencillo y predecible.
  - Sigue **en cascada** por las sucesoras de las sucesoras.
  - Mover una tarea **antes** no propone nada: las sucesoras nunca se adelantan solas.
- **Nunca se aplica sola:** `POST /tareas/{task}/reprogramar/propuesta` devuelve la propuesta sin cambiar nada. `POST /tareas/{task}/reprogramar` guarda las fechas nuevas y solo desplaza las sucesoras si se confirma (`shift_successors`).
  - La propuesta se **recalcula en el servidor** al confirmar: nunca se aceptan fechas del cliente para las sucesoras.
  - Cada sucesora exige `TaskPolicy::update`. O se aplica todo o nada.
- **Las dependencias son una ayuda:** se puede guardar una fecha en conflicto sin desplazar nada. El Gantt marca el conflicto (enlace en rojo, con icono y texto).

### D-058 · Plantillas de proyecto **[concreta el SPEC §4.3, §6 y §14]**
- **Estructura** (JSON en `project_templates.structure`):
  - tareas con `ref`, `parent_ref` (un nivel), título, tipo, prioridad, estimación, hito, `start_offset_days` y `duration_days`,
  - dependencias `from_ref → to_ref`.
  - Todo se valida con `ProjectTemplateService::normalize`: referencias únicas, subtareas de un solo nivel, dependencias existentes, sin ciclos y un máximo de 500 tareas.
- **Aplicar:**
  - al **crear un proyecto** («desde plantilla», SPEC §6) lo puede hacer quien crea proyectos: admin y responsables (D-022),
  - también en un proyecto existente, desde **Ajustes**, quien lo gestiona: se añaden las tareas y no se toca nada de lo que ya hay.
  - Las fechas se calculan desde el inicio que se elija (por defecto, el del proyecto). Si el proyecto es de bolsas, todas las tareas van a la bolsa que se elija.
- **Guardar como plantilla:** desde Ajustes del proyecto, quien lo gestiona. Solo se guardan títulos, tipos, estimaciones, fechas relativas y dependencias; nunca personas, horas ni estados.
- **Gestión** en `/admin/plantillas`: el admin crea, edita la estructura, desactiva y borra (papelera). Los responsables las ven y las aplican.

### D-059 · Tareas recurrentes **[concreta el SPEC §4.3 y §14]**
- **Regla:**
  - **semanal:** cada N semanas, en un día (lunes = 1),
  - **mensual:** un día del mes; si el mes no lo tiene, el último día (31 → 28/29 en febrero, 30 en abril),
  - desde `starts_on` y, opcionalmente, hasta `ends_on`,
  - con la plantilla de la tarea: título, descripción, tipo, responsable, bolsa, estimación, prioridad y vencimiento `due_offset_days` días después de la fecha de cada instancia.
- **Generación:**
  - el comando `tasks:generate-recurring`, diario a las 06:00 de Madrid (`withoutOverlapping`),
  - cada instancia se crea con `TaskWriter`; no se duplica gracias a la clave única (regla, fecha),
  - recupera como máximo 31 días atrasados,
  - se salta los proyectos archivados, las bolsas cerradas o renovadas y a los responsables desactivados (la tarea queda sin responsable).
  - Al crear o reactivar una regla, se genera ya la instancia de hoy si toca.
- **Quién:** las reglas de un proyecto las gestiona quien gestiona el proyecto, desde su pestaña **Ajustes**. El admin tiene la vista global en `/admin/tareas-recurrentes`.

### D-060 · Gantt: componente propio **[concreta el SPEC §2 y §6.1]**
- **Por qué propio:** se evaluaron SVAR React Gantt (MIT, pero parte de sus funciones son de pago) y frappe-gantt (MIT, sin React ni accesibilidad de teclado). Se hace un **componente propio** (React y SVG/HTML con pointer events, sin librerías nuevas) porque:
  - pedimos control total del teclado y de la accesibilidad AA,
  - usa los tokens del tema claro y oscuro,
  - está en español,
  - sin reprogramación automática.
  - Sirve también para el Gantt de solo lectura del portal (F5), con `readOnly`.
- **Funciones:**
  - **Marcas:** barras para las tareas y rombos para los hitos. Subtareas sangradas bajo su padre, con la barra del padre como resumen.
  - **Escalas:** día, semana y mes, con una marca de **hoy**.
  - **Arrastrar para mover y redimensionar** (con el ratón y con el teclado: flechas mueven un día; Mayús + flechas cambian la entrega). Al soltar, se pide la propuesta (D-057) y, si hay conflicto, un diálogo ofrece «Mover también las sucesoras», «Solo esta tarea» o «Cancelar».
  - **Crear dependencias** arrastrando desde el conector del final de una barra hasta otra. Alternativa con teclado: «Añadir dependencia» en el menú de la barra. Las flechas de las dependencias van en rojo, con icono, si hay conflicto.
  - **Colores:**
    - por **estado:** color de su categoría,
    - o por **responsable:** `--chart-1..6` en orden fijo de aparición; el resto en gris «Otros». Siempre con leyenda, y el nombre en la barra o el tooltip.
  - **Tareas sin fechas:** en una lista aparte, con «Asignar fechas».
  - **Crear una tarea** desde el Gantt con sus fechas (el alta rápida de la F1 con fechas).
  - **Tabla accesible alternativa:** título, responsable, inicio, entrega, dependencias.
- **Multiproyecto:** en `/gantt`, con filtros por cliente, departamento, responsable y estado del proyecto. Proyectos agrupados y plegables, con un máximo de 60 proyectos o 1.500 tareas por vista; si hay más, se avisa y se pide filtrar.
- **Quién:** lo ve cualquier interno (D-021). Mueve y enlaza quien puede editar las tareas; al resto, en solo lectura.

### D-061 · Calendario de tareas **[concreta el SPEC §6 y D-037]**
- **Dónde:** tercera vista de la pestaña **Tareas**, junto a Lista y Kanban.
- **Qué muestra:**
  - un mes, con la semana empezando en lunes,
  - cada tarea el día de su **entrega**; las tareas con inicio también como franja del inicio a la entrega,
  - los hitos con su rombo.
- **Cambiar fechas:** arrastrando o con el teclado, pasando por reprogramar (D-057). Pulsar una tarea abre su panel.
- **Tareas sin fecha:** en una lista lateral.

### D-062 · Hitos **[concreta el SPEC §5.1 y §6]**
- **En el panel de la tarea:**
  - una casilla «Hito»: sin horas y con la entrega como única fecha, como hasta ahora,
  - la sección **Dependencias**, con las predecesoras y sucesoras, para añadir y quitar (D-056).
- **Resumen del proyecto:** «Próximos hitos», los 5 siguientes sin completar, más los vencidos destacados con icono y texto.
- **Inicio, «Mis próximos hitos»:** los de los proyectos donde soy miembro, vencidos y de los próximos 30 días, máximo 8, con enlace a su tarea.
- **Chat del proyecto:** el mensaje de sistema «hito completado» llega con el chat (F6).

## 27/09/2026: Decisiones tomadas en autonomía durante la implementación de la Fase 5 (P2 · acceso, proyectos e identidad)

_Concretan D-063, D-064 y D-067 (`docs/PLAN-FASE-5.md`). Sin número: se numeran al integrar la fase._

### P2-a · Quién gestiona el portal **[concreta D-063 y D-064]**
- **Usuarios del portal** (invitar, reenviar, revocar y reactivar): `ClientPolicy::managePortal`, es decir, admin, responsables (rol) y gestores de algún proyecto sin borrar del cliente.
- **Ajustes del portal del cliente** (personas, horas visibles y avisos por email): `ClientPolicy::update` (admin y responsables). Afectan a todas las bolsas y proyectos del cliente, así que no los cambia el gestor de uno solo, que los ve en solo lectura.
- **Portal de cada proyecto** (vista, horas por tarea y Gantt): quien gestiona el proyecto (`ProjectPolicy::update`), desde sus ajustes. El SPEC §11 decía «el admin».
- Quien no gestiona el portal no recibe la lista de usuarios: la ficha le dice quién lo gestiona.

### P2-b · Invitaciones y estado de los usuarios del portal **[concreta D-063]**
- **Alta:** rol `client` y `client_id`, sin departamento ni jornada (no es plantilla). La invitación reutiliza `UserInviter::send` (broker `invitations`, 7 días, cola `mail`) con el asunto y la presentación del portal.
- **Correo único**, sin distinguir mayúsculas. Si es de una persona del equipo, se explica; si es de otro cliente, el mensaje genérico (no se revela de quién es).
- **Estado en la ficha:**
  - aceptada, si fijó su contraseña con la invitación (correo verificado) o ha entrado alguna vez,
  - pendiente, mientras el enlace esté vigente (con su fecha de caducidad),
  - caducada, en otro caso.
  - Último acceso = último inicio de sesión correcto.
- **Reenviar:** solo a personas activas de un cliente activo; la interfaz lo ofrece a quien todavía no ha entrado (el resto tiene «¿Has olvidado la contraseña?»).
- **Revocar:** desactiva (User::booted cierra todas sus sesiones y su «Recordarme» con `SessionTerminator`) y anula la invitación pendiente, para que un enlace viejo no sirva si se reactiva. **Reactivar** no reenvía la invitación.
- Con el cliente desactivado no se invita, no se reenvía ni se reactiva; revocar, sí.
- **Auditoría:** `activity_log` del cliente (`clients`): `portal_user_invited`, `portal_invitation_resent`, `portal_user_revoked` y `portal_user_reactivated`, con la persona.

### P2-c · Proyectos en el portal **[concreta D-064]**
- **Abrir:** las horas por tarea solo cuentan con la vista abierta (al cerrarla, se apagan). El Gantt se abre aparte y no exige la vista. Un proyecto sin cliente no se abre.
- **Qué se ve de cada tarea:** título, estado, inicio y entrega, si es hito y sus subtareas. Nunca descripciones (pueden llevar notas internas), comentarios, adjuntos, responsables, estimaciones, prioridad ni importes.
- **Horas por tarea:** solo las visibles para el cliente (`PortalScope::entries`). La de una tarea con subtareas suma las suyas; el total no cuenta dos veces.
- **Páginas:** `/portal/proyectos` (los abiertos, con su avance), `/portal/proyectos/{proyecto}` y `…/gantt`. Un proyecto archivado que siga abierto se ve (histórico); uno en la papelera, no.
- **Gantt:** el componente de la Fase 4 con `readOnly` y la nueva prop aditiva `hideAssignees` (sin columna de responsable, sin colores por responsable ni su nombre en las barras). Los datos ya llegan sin responsables, estimaciones ni horas y con `can.update = false`. Colores siempre por estado. Una tarea se abre en un diálogo de solo lectura.
- **Navegación:** la cabecera del portal lleva «Inicio» y, si hay algún proyecto abierto, «Proyectos». Salen de la prop compartida `portal` (identidad y proyectos abiertos), que solo reciben los usuarios del portal. Para el Inicio queda la tarjeta `PortalProjectsCard`.

### P2-d · Identidad de la empresa **[concreta D-067]**
- **Logo:** se comprueba el tipo real con fileinfo y se vuelve a codificar con GD en un PNG con transparencia que cabe en 960 × 240 px (sin ampliar). No queda nada del fichero original. El original admite hasta 3.000 px por lado. Se procesa en la petición: es un fichero de 1 MB como mucho y solo lo sube el admin.
- **Dónde se guarda y cómo se sirve:** se guarda en el disco privado y se sirve en `/marca/logo/{versión}`, una ruta pública y fuera del grupo web (sin sesión ni cookies), porque la cargan los emails. La versión actual se guarda en caché un año. `ClientIsolationTest` admite esa ruta.
- **Emails:** se sobrescribe solo `resources/views/vendor/mail/html/header.blade.php`: el logo (como mucho 240 × 56 px) si lo hay; si no, el texto de siempre.
- **PDF:** `AudaxPdf::logo` dibuja el PNG en una caja de 45 × 8 mm; sin logo, el vectorial.
- **Cabecera del portal:** el logo va sobre una placa blanca, porque la cabecera va sobre el degradado.
- `company_name` es el mismo ajuste que ya se editaba en `/admin/ajustes`: queda en los dos sitios hasta que se decida quitarlo de allí.
