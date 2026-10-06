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
  - **Desde la Fase 9, Gotenberg (D-140):** el PDF de bolsa se maqueta en HTML con DM Sans y la hoja de documentos de Audax y lo convierte Gotenberg, con la misma información. `setasign/fpdf` se retiró de `composer.json` y `composer.lock` en la entrega 9.5.
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

### D-080 · Nivel de ocupación de un periodo en curso **[concreta D-047 y D-079]**
- **Qué se enseña:** la ocupación de cada miembro sigue siendo la del SPEC §10 (imputadas / capacidad del periodo completo, D-079).
- **Nivel (baja, en rango, alta; umbrales 70 % y 110 % de D-047):** en un periodo en curso (le quedan días con jornada) se mide con el **ritmo**: imputadas / capacidad transcurrida hasta ayer (`pace`). Contra el periodo entero, a mitad de mes todo el mundo salía «baja». Si esa capacidad es 0 (el primer día o un periodo futuro), sin nivel: «Aún sin datos». En un periodo cerrado, el nivel es el de la ocupación.
- **Dónde:** tabla de miembros del departamento (el ritmo va debajo de la ocupación) y su exportación («Ritmo (%)»).

### D-081 · Minutos en las exportaciones con totales **[concreta D-045]**
- Las horas en decimal (redondeadas a 2) no siempre suman su total: tres entradas de 20 min son 0,33 + 0,33 + 0,33 = 0,99 h frente a 1,00 h.
- Las exportaciones con fila de totales (horas para facturar, resumen por proyecto del cliente, estimado frente a real del proyecto y tabla dinámica del detallado) llevan **al final** una columna de **minutos enteros** por cada columna de horas que se suma; en la tabla dinámica, una columna y una fila «Total (minutos)». Las horas decimales siguen para leer; los minutos suman exacto. Al final, para no mover las columnas de siempre.

### D-082 · Tope del precio de una bolsa **[concreta D-043]**
- La parte de lo que va dentro de una bolsa con precio vale `precio × minutos dentro / máx(total de la bolsa, lo que va dentro de la bolsa entera)`.
- Normalmente es `precio × dentro / total`. Pero si lo de dentro supera el total (entradas bloqueadas que no cambian al reducir el total, D-019 y D-054), el precio se reparte entre todo lo de dentro: la bolsa nunca vale más que su precio, sume el periodo que se sume. «Lo que va dentro de la bolsa entera» es el de `HourBankLedger` (consumo − exceso).
- El exceso de una bolsa con precio, como dice D-043: a la instantánea de tarifa de cada entrada si está aprobada o bloqueada y, si no, a la tarifa vigente (antes se valoraba siempre a la vigente).

### D-083 · Un solo total de ingreso y coste, con los mismos céntimos en todas partes **[concreta D-043]**
- **Total:** el de un conjunto de entradas es el de sus unidades (proyecto · bolsa · persona · facturable; el precio cerrado, por proyecto), redondeado **una sola vez**, se agrupe como se agrupe (`RevenueCalculator`, con las fórmulas en `App\Domain\Reports\Valuation`).
- **Repartos y series** (por cliente, proyecto, persona, semana…): cada fila recibe su parte de ese total en céntimos por resto mayor (`Cents::largestRemainder`): las filas suman exactamente el resumen. Las tablas enseñan los totales del servidor, nunca sumados en el navegador.
- **Exportaciones por entrada** (horas y horas para facturar): cada entrada suma su parte exacta del total de su unidad (`EntryValuation::next`) y los céntimos se reparten en orden (`RunningCents`): la columna suma el ingreso del informe y ninguna línea se aleja más de un céntimo de su importe. El total del fichero sale de las mismas filas que exporta (nunca del resumen en caché).

### D-084 · Subtareas en los informes **[concreta el SPEC §6 y §10]**
- **Precisión de estimación:** las unidades son las hojas con estimación (subtareas y tareas sin subtareas) y las tareas raíz cuyas subtareas no están estimadas (con su propia estimación y las horas de sus subtareas), completadas en el periodo. Una tarea con alguna subtarea estimada cuenta por sus subtareas estimadas (su estimación es su suma); sus horas propias y las de sus subtareas sin estimar no entran en la precisión. Es la regla de la tabla «Estimado frente a real» y de la base del precio cerrado.
- **Detallado por tarea:** las horas de una subtarea suman en su tarea padre (se agrupa por la tarea raíz).

### D-085 · Límite de las exportaciones de los informes
- 30 exportaciones por minuto y usuario, comunes a todos los dashboards y al detallado (limitador `report-exports`). Solo cuentan las peticiones con `formato`: ver las páginas no gasta el cupo. La exportación de horas y el PDF de bolsa mantienen su límite propio.

### D-086 · Caché de los informes **[concreta D-046]**
- **Cuándo se invalida:** siempre **tras el commit** de la transacción (y nunca si se deshace), con un incremento atómico de la versión. Además de entradas, tareas, bolsas, proyectos, clientes, jornadas, ajustes y personas: estados y tipos de tarea, departamentos, semanas de horas, bloqueos, miembros y gestores de proyecto y responsables de departamento, y los servicios con actualizaciones masivas (bloquear y desbloquear horas, pasar un estado a «done», mover tareas y reordenar catálogos).
- **Clave:** además de quien mira, su permiso económico y los filtros, su **alcance**: roles, departamentos que dirige y proyectos que gestiona. Quien deja de dirigir un departamento no ve nunca lo que vio cacheado, aunque nada haya invalidado la caché.

### D-087 · Resumen semanal con el alcance de dirección **[concreta D-047]**
- Las bolsas en riesgo y las tareas vencidas del resumen semanal son las del dashboard de dirección de quien lo recibe (`HourBanksAtRisk` y `OverdueTasks`): a un responsable, también las bolsas de los proyectos donde imputa su equipo y las tareas de las personas de su equipo que imputaron esa semana aunque ya estén de baja. El email cuenta lo mismo que el dashboard.

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
  - la capacidad día a día se calcula, y se pinta, como mucho un año hacia delante. Una entrega posterior reparte igualmente entre **todos** sus días laborables: los que pasan del año se cuentan con la jornada semanal vigente al final de ese año, sin festivos ni ausencias, para no amontonar el restante en el primer año (revisión global).
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
  - El nivel se decide con el porcentaje redondeado que se enseña, para que la cifra y el color no se contradigan: 481 de 480 min se lee «100 %» y es verde; 335 de 480, «70 %», también verde (revisión global).
- **Panel de una celda:** las tareas que forman esa carga, con los minutos de ese día, y se reasignan ahí mismo (responsable y fechas) con las reglas de Tareas (`TaskPolicy::update`, `TaskWriter`). La matriz se recalcula al momento.
  - La celda de alguien fuera de su alcance en la URL (`?celda=`) se ignora en una visita completa, como cualquier otro filtro que no vale; solo la recarga parcial que abre el panel responde 403 (revisión global).
- **Bandejas «Sin planificar» y «Sin asignar»:** en la misma página, con acciones rápidas para poner la estimación, las fechas o el responsable.

### D-088 · Ausencias de otra persona al imputar por ella **[concreta D-036 y D-049; RGPD]**
- **Quién ve el tipo de una ausencia** (vacaciones, baja, permiso…; una baja es un dato de salud): la propia persona, un admin o quien la supervisa (`User::canSeeAbsencesOf`, que es `supervises` para los responsables).
- **Aviso al imputar por otra persona en un día con ausencia aprobada** (SPEC §7):
  - un admin o su responsable ven el tipo, con el nombre de la persona («Ese día Pedro Pérez tiene una ausencia aprobada (Baja)…»),
  - cualquier otro que pueda imputar por ella (un gestor, en su proyecto) solo ve «Ese día Pedro Pérez no está disponible», sin tipo ni horas (o «no está disponible una parte de la jornada»).
- **Por qué:** antes el aviso decía el tipo («Baja») a cualquiera que pudiera imputar por la persona, y probando fechas se podía reconstruir su calendario de bajas. Ahora solo sabe que ese día no está disponible, que es lo que necesita para imputar bien.
- **Avisos de jornada:** al imputar por otra persona hablan de ella y la nombran («Ese día Pedro Pérez no tiene jornada y suma 1:00», «… más de un 25 % por encima de su jornada»), no de quien imputa.

### D-091 · Navegación de ausencias y festivos **[cambia el SPEC §3]**
- **Barra lateral:** «Ausencias» para todos los internos, tras «Carga». Quien aprueba ausencias (responsables y admins, `auth.can.viewTeamAbsences`) tiene dentro «Ausencias del equipo». Las pestañas «Mis ausencias» y «Ausencias del equipo» de la página siguen igual.
- **Búsqueda global:** «Ausencias», «Ausencias del equipo» (quien las aprueba) y «Festivos» (`manage-settings`) son secciones propias; «ausencias» deja de ser una palabra clave de «Carga».
- **Administración:** la tarjeta «Festivos y ausencias» lleva a los festivos y a las ausencias del equipo.

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

### D-089 · Fechas de las tareas recurrentes **[concreta D-059]**
- **Desde y hasta:** entre el 01/01/2000 y el 31/12/2100, como el calendario de tareas (`CalendarPeriod`). El formulario lo valida con su mensaje y no se calcula ninguna tarea fuera de ese rango (`RecurringTaskRule::MIN_DATE` y `MAX_DATE`).
- **Cálculo:** las fechas de una regla saltan directamente a la ventana pedida, sin recorrer la serie desde su inicio (una regla antigua conserva su ritmo). La vista previa del formulario y el servidor comparten los casos de prueba (`tests/fixtures/recurrence-cases.json`).

### D-090 · Dependencias: enlaces de uno en uno y propuesta a prueba de ciclos **[concreta D-056 y D-057]**
- **Enlazar:** los enlaces de un mismo proyecto se hacen de uno en uno (la fila del proyecto queda bloqueada mientras se busca el ciclo y se guarda el enlace): dos enlaces a la vez no pueden cerrar un ciclo.
- **Proponer:** mover una tarea antes **o sin cambiar su entrega** no propone nada, aunque ya hubiera un conflicto: no lo causa ese cambio y el Gantt lo sigue marcando.
  - La cascada solo sigue desde lo que se desplaza, y cada sucesora se revisa una vez, con sus fechas definitivas.
  - Si la base tuviera un ciclo (datos antiguos o dañados), nunca se propone mover la tarea movida ni se vuelve a una tarea del propio camino.

## 27/09/2026: Decisiones tomadas en autonomía (Fase 5)

_Concretan D-063 a D-067 (`docs/PLAN-FASE-5.md`): las de P1 (bolsas), las de P2 (acceso, proyectos e identidad, antes «P2-a» a «P2-d») y las de la revisión global. Numeradas desde D-092 porque las fases en curso ya habían reservado hasta D-091._

### D-092 · Dentro y exceso en el portal **[concreta D-019, D-053 y D-064]**
- **Problema:** el exceso guardado en cada entrada (`overage_minutes`) lo reparte `HourBankLedger` entre **todas** las horas de la bolsa, también los borradores y las enviadas. Con horas ocultas de fecha anterior que llenan la bolsa, horas que el cliente ve salían como exceso aunque le quedara saldo (bolsa de 600, borrador de 480 el 01/09 y aprobada de 300 el 15/09: el portal enseñaba 120 dentro, 180 de exceso, 480 restantes y «Activa»; y el email del 100 % podía llegar con la bolsa activa).
- **Regla:** en el portal, dentro y exceso se reparten **solo entre las horas que ve el cliente**, contra el total de la bolsa y con la regla del libro:
  - las **bloqueadas** conservan su exceso fijo (D-019, D-053) y reservan lo que llevan dentro,
  - el resto del total se reparte entre las demás en orden cronológico (fecha, `created_at`, id); la que cruza el límite queda con la parte que no cabe como exceso.
- **Dónde:** `PortalBankFigures` (`allocation`, `many`, `byMonth`, `between` y `entries`), que usan las cifras y el estado de las bolsas, el consumo por mes, el listado de horas, las «horas de este mes» del inicio, los avisos al cliente y el PDF. Así todo cuadra siempre. Por dentro la bolsa sigue con sus cifras de siempre (la nota del portal lo explica).
- **Cómo:** en SQL, con una suma acumulada (`SUM(...) OVER (PARTITION BY bolsa ORDER BY fecha, created_at, id)`), que funciona en PostgreSQL y en SQLite ≥ 3.25, en una consulta por pantalla y sin N+1.

### D-093 · Estado de la bolsa para el cliente **[concreta D-064]**
- Sale de `PortalBankFigures::status`: las bolsas **cerradas y renovadas**, tal cual; una **abierta** sale **agotada** cuando lo que el cliente ve dentro de la bolsa llega al total (la regla de `HourBankLedger` con sus horas visibles, D-092) y, si no, activa.
- Así el estado cuadra con la barra, el listado y el PDF, aunque por dentro la bolsa vaya más avanzada (borradores y semanas sin aprobar).

### D-094 · Inicio del portal **[concreta D-064]**
- **Bolsas activas:** las activas o agotadas de un proyecto **no archivado** (como la vista global de bolsas, D-054). El resto (cerradas, renovadas y las de proyectos archivados) va a **«Bolsas anteriores»**, de la más reciente a la más antigua.
- **«Horas de este mes»:** solo las horas **visibles** de sus bolsas (nunca las de proyectos sin bolsa), en el mes en curso de `Europe/Madrid`, con su exceso tal como lo ve el cliente (D-092).
- **«Cerca del límite»:** las bolsas activas desde el primer umbral configurado (D-035).

### D-095 · PDF del portal **[concreta D-066]**
- Sus cifras, su consumo por mes y su listado son los de `PortalBankFigures` (D-092), así que **no lleva el bloque «sin aprobar»** del PDF interno (D-045): lo que el cliente no ve no aparece, ni siquiera como total aparte. La nota del PDF explica qué horas ve.
- **Desde la Fase 9, Gotenberg (D-140):** sale del mismo motor HTML que el PDF interno, con las mismas cifras del portal y sin «sin aprobar». FPDF ya no está en el proyecto.

### D-096 · Personas y acceso en el portal **[concreta D-063 y D-064]**
- **Con «Equipo»**, los nombres de las personas **ni se cargan ni viajan** al navegador ni al PDF (no se pide la relación con la persona); con las iniciales, solo viajan las iniciales.
- **Internos en `/portal/*`:** reciben **403** (middleware `portal`) antes de buscar nada. Un cliente con un id de otro cliente recibe **404**, como con uno que no existe (`PortalRoutesIsolationTest` recorre todas las rutas del portal).

### D-097 · Quién gestiona el portal **[concreta D-063 y D-064]**
- **Usuarios del portal** (invitar, reenviar, revocar y reactivar): `ClientPolicy::managePortal`, es decir, admin, responsables (rol) y gestores de algún proyecto sin borrar del cliente.
- **Ajustes del portal del cliente** (personas, horas visibles y avisos por email): `ClientPolicy::update` (admin y responsables). Afectan a todas las bolsas y proyectos del cliente, así que no los cambia el gestor de uno solo, que los ve en solo lectura.
- **Portal de cada proyecto** (vista, horas por tarea y Gantt): quien gestiona el proyecto (`ProjectPolicy::update`), desde sus ajustes. El SPEC §11 decía «el admin».
- Quien no gestiona el portal no recibe la lista de usuarios: la ficha le dice quién lo gestiona.
- En la ficha, la sección es una **prop diferida** (`portal`, como las secciones de la F4 en los ajustes del proyecto): no pesa en la carga ni en el presupuesto de consultas de la F1, y sus acciones recargan solo esa prop.

### D-098 · Invitaciones y estado de los usuarios del portal **[concreta D-063]**
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

### D-099 · Proyectos en el portal **[concreta D-064]**
- **Abrir:** las horas por tarea solo cuentan con la vista abierta (al cerrarla, se apagan). El Gantt se abre aparte y no exige la vista. Un proyecto sin cliente no se abre.
- **Qué se ve de cada tarea:** título, estado, inicio y entrega, si es hito y sus subtareas. Nunca descripciones (pueden llevar notas internas), comentarios, adjuntos, responsables, estimaciones, prioridad ni importes.
- **Horas por tarea:** solo las visibles para el cliente (`PortalScope::entries`). La de una tarea con subtareas suma las suyas; el total no cuenta dos veces.
- **Páginas:** `/portal/proyectos` (los abiertos, con su avance), `/portal/proyectos/{proyecto}` y `…/gantt`. Un proyecto archivado que siga abierto se ve (histórico); uno en la papelera, no.
- **Gantt:** el componente de la Fase 4 con `readOnly` y la nueva prop aditiva `hideAssignees` (sin columna de responsable, sin colores por responsable ni su nombre en las barras). Los datos ya llegan sin responsables, estimaciones ni horas y con `can.update = false`. Colores siempre por estado. Una tarea se abre en un diálogo de solo lectura.
- **Navegación:** la cabecera del portal lleva «Inicio» y, si hay algún proyecto abierto, «Proyectos». Salen de la prop compartida `portal` (identidad y proyectos abiertos), que solo reciben los usuarios del portal. Para el Inicio queda la tarjeta `PortalProjectsCard`.

### D-100 · Identidad de la empresa **[concreta D-067]**
- **Logo:** se comprueba el tipo real con fileinfo y se vuelve a codificar con GD en un PNG con transparencia que cabe en 960 × 240 px (sin ampliar). No queda nada del fichero original. El original admite hasta 3.000 px por lado. Se procesa en la petición: es un fichero de 1 MB como mucho y solo lo sube el admin.
- **Dónde se guarda y cómo se sirve:** se guarda en el disco privado y se sirve en `/marca/logo/{versión}`, una ruta pública y fuera del grupo web (sin sesión ni cookies), porque la cargan los emails. La versión actual se guarda en caché un año. `ClientIsolationTest` admite esa ruta.
- **Emails:** se sobrescribe solo `resources/views/vendor/mail/html/header.blade.php`: el logo (como mucho 240 × 56 px) si lo hay; si no, el texto de siempre.
- **PDF:** `AudaxPdf::logo` dibuja el PNG en una caja de 45 × 8 mm; sin logo, el vectorial.
- **Cabecera del portal:** el logo va sobre una placa blanca, porque la cabecera va sobre el degradado.
- `company_name` es el mismo ajuste que ya se editaba en `/admin/ajustes`: queda en los dos sitios hasta que se decida quitarlo de allí.

### D-101 · Avisos al cliente tras la revisión global **[concreta D-065]**
- Solo de un cliente **activo** y de una bolsa **abierta** (activa o agotada): nunca de una cerrada o renovada, aunque se aprueben sus horas (D-053).
- Un cambio que cruza el 90 % y el 100 % a la vez manda **un solo email**, el del umbral más alto; los dos quedan registrados y no se repiten (como los avisos internos).
- Se comprueban con la escritura interna ya confirmada y **nunca la rompen**: el alcance sale del cliente (`PortalScope::forClient`), cada bolsa se comprueba por separado y un fallo solo se registra (`report()`). Una transacción que se deshace no deja nada pendiente.
## 27/09/2026: Decisiones tomadas en autonomía (Fase 6)

### D-070 · Transcripción de audios: motor y modelo medidos en el servidor **[concreta el SPEC §12]**
Detalle completo en `docs/PLAN-FASE-6.md`.
- **Motor:** whisper.cpp v1.9.4 (MIT) en el contenedor `audax-whisper`, en `127.0.0.1:18091`, con límites: 2 CPU en los núcleos 6-7, `cpu_shares` 64, 1,5 GB sin swap.
- **Worker:** `audax-transcriber.service`, un único proceso con Nice 19.
- **La CPU del servidor no tiene AVX:** la imagen se compila en GitHub Actions para x86-64 básico y se prueba con QEMU emulando esa CPU.

Medido el 27/09/2026 en el servidor real (VM KVM, CPU genérica **solo SSE2/SSE3**), con `scripts/server/whisper-bench.sh`.
- **Condiciones:** contenedores efímeros con 2 CPU fijadas a los núcleos 6-7, `cpu-shares` 64 y memoria acotada; whisper.cpp v1.9.4 compilado para x86-64 básico.
- **Audio:** 59 s de voz en español con un texto conocido, sintetizado en el Mac; limpio y con ruido rosa y filtro de teléfono.
- **WER:** error por palabras frente al texto, normalizado (mayúsculas, tildes, signos y cifras).

| Modelo | Variante | Tiempo (59 s de audio) | × duración | Pico de RAM | WER limpio | WER con ruido |
|---|---|---|---|---|---|---|
| tiny | sin BLAS | 67 s | 1,1 | 302 MB | 6,5 % | — |
| tiny | OpenBLAS | 47 s | 0,8 | 313 MB | 5,9 % | 6,5 % |
| base | sin BLAS | 149 s | 2,5 | 478 MB | 1,6 % | — |
| base | OpenBLAS | 99 s | 1,7 | 438 MB | 1,6 % | 1,6 % |
| small | sin BLAS | 526 s | 8,9 | 1.203 MB | 1,1 % | — |
| small | OpenBLAS | 317 s | 5,3 | 1.012 MB | 1,1 % | 1,1 % |
| medium | OpenBLAS | 808 s | 13,6 | 3.072 MB (en el límite de 3 GB) | 1,1 % | — |
| **small (whisper-server, webm/opus del navegador)** | OpenBLAS | **211 s** | **3,6** | **906 MB** | — | **1,1 %** |

**Elección: `small` con OpenBLAS**, servido por `whisper-server --convert`.
- **Por qué no `base`:** con voz sintética `base` ya acierta mucho, pero en voz real (micrófono de móvil, acentos, ruido) `small` comete aproximadamente la mitad de errores. Además, la búsqueda por palabras dichas en un audio (aceptación de la F6) depende de esa precisión.
- **No sobrecarga el servidor:**
  - usa 2 núcleos fijos (6-7) con la prioridad más baja y menos de 1 GB,
  - la carga media del servidor pasó de ~2,5 a ~3,8 durante la medición, sin afectar a las demás webs.
- **Tiempo de espera:** un audio de 1 minuto tarda unos 3,5 minutos; uno de 5 minutos (el máximo), unos 18. El audio se escucha desde el primer momento y el texto llega después («Transcribiendo…»).
  - Límites ajustados: job de 2.400 s, `retry_after` de 2.700 s y petición de 2.340 s.
- **`medium`, descartado:** triplica el tiempo de `small` y necesita 3 GB, sin mejora medible.
- **Si con audios reales la cola se acumula**, se puede cambiar a `base` con `WHISPER_MODEL` y el contenedor, sin tocar el código.
- **Recomendación al propietario:** con el tipo de CPU «host» en el hipervisor (AVX2), el tiempo bajaría varias veces.

## 02/10/2026: Decisiones tomadas en autonomía durante la implementación y la integración de la Fase 6
Resumen de lo que decidieron C1 (chat), C2 (tiempo real y avisos) y C3 (audios, adjuntos y búsqueda) y de lo que se decidió al integrarlas. Concretan D-068 a D-072.

### D-110 · Chat: interfaz y escritura (C1) **[concreta D-069]**
- **Markdown ligero:** se pinta con elementos de React, nunca con HTML; los enlaces, solo `http(s)` y `mailto`.
- **Emojis:** selector `frimousse` (MIT) con los datos de Emojibase 16 (MIT) autoalojados en `public/emojibase` (la CSP solo permite `'self'`).
- **Editor:** envío optimista («Enviando…», con «Reintentar» y «Descartar»), borrador por conversación en el navegador, ↑ con el cuadro vacío edita el último mensaje propio, menciones con autocompletado y `@todos`.
- **Mensajes:** páginas por cursor (antes, después y alrededor de un mensaje, para `?mensaje=`); sin tiempo real, consulta cada 10 s con la pestaña visible (60 s con él, como red de seguridad); si llegan demasiados de golpe, se avisa para cargar los últimos.
- **Previsualización de enlaces:** job en la cola `default`, solo los puertos 80 y 443, IP pública comprobada antes de conectar y en cada redirección (IPv4 e IPv6) y conexión fijada a esa IP, 3 redirecciones, 3 s, 512 KB y solo `text/html`. **Se guarda sin imagen** (D-069 permitía «servida por la app o sin imagen»). Apagada en los tests.
- **Mensajes de sistema** en el chat del proyecto: bolsa al 90 % y agotada, e hito completado.
- **Tarea desde un mensaje:** el mensaje queda enlazado a la tarea y el panel de la tarea enlaza al mensaje solo para quien ve la conversación.
- **Límites:** cada `throttle` lleva su prefijo (igual en C2 y C3): sin él, todas las rutas comparten el contador de la persona.

### D-111 · Tiempo real (C2) **[concreta D-068]**
- **Eventos:** salen por la cola, tras el commit y sin romper nada si la cola falla (`ShouldRescue`), con **solo ids** (y la pista de qué cambió), nunca el texto: el navegador pide los datos a las rutas, que comprueban los permisos.
- **Sin Reverb** (`BROADCAST_CONNECTION` distinto de `reverb`) no se emite ni se encola nada; la interfaz consulta.
- **Canal personal:** actividad para los contadores de no leídos, lecturas en otras pestañas y la campana en vivo (sin Reverb, la campana consulta cada 60 s).
- **Presencia:** canal `online`, «Ausente» a los 5 minutos sin actividad; sin Reverb, latidos cada 60 s. Con forma y texto, nunca solo color.
- **«Escribiendo…»:** por *whisper*, como mucho uno cada 3 s; caduca a los 6 s.
- **Conversación abierta:** latido cada 30 s mientras la pestaña está visible; a quien la tiene abierta no le sube el contador ni le llegan avisos.
- **Contadores:** un almacén compartido por toda la interfaz; sin Reverb se consultan cada 30 s; con él, +1 en vivo y repaso cada 5 minutos.
- **«Leído por»:** participantes activos y hasta dónde han leído; sin Reverb se consulta cada 30 s.

### D-112 · Avisos del chat y Web Push (C2) **[concreta D-072]**
- **Qué avisa:** mención personal, `@todos` (salvo a quien la tiene silenciada) y mensajes directos (salvo silenciados); nunca al autor ni a quien tiene la conversación abierta. Una mención personal en una conversación silenciada llega a la campana, pero no al navegador.
- **Agrupación:** como mucho un aviso por conversación cada 5 minutos por persona; se reinicia al leerla o abrirla.
- **Cola:** solo se encola si el mensaje puede avisar (menciones, `@todos` o directa).
- **Web Push:** `minishlink/web-push` 11 (MIT); suscripciones solo de servicios de push conocidos por https y con claves bien formadas (SSRF); `Topic` por conversación; se borran las caducadas (404/410) y las que fallan 5 veces seguidas, y la de ese navegador al cerrar sesión.
- **Claves VAPID:** `php artisan push:vapid-keys` las imprime (no las guarda; `--check` las valida). Sin claves válidas el canal queda apagado sin errores.
- **Service worker:** solo abre rutas de la propia app; reutiliza una pestaña abierta.
- **Enlace estable de los avisos:** `/tiempo-real/conversaciones/{id}/abrir?mensaje={id}`, que comprueba que aún puedes ver la conversación y lleva a `chat.show`.

### D-113 · Audios, adjuntos y búsqueda (C3) **[concreta D-069 y D-070]**
- **Grabar:** `MediaRecorder` (webm/opus o mp4 según el navegador, 64 kbit/s), forma de onda y contador; los audios de menos de 1 s no se envían. Duración máxima en `/admin/ajustes` (sección «Chat»), entre 30 s y 10 min (el techo lo pone el tiempo máximo del transcriptor).
- **Servir:** los audios por `chat.media.audio` con `Range` (206); imágenes y archivos por `attachments.show` y `attachments.thumbnail`. URL firmada relativa (1 h) más `AttachmentPolicy`; el tipo de audio se normaliza (fileinfo toma a veces un webm por vídeo).
- **Permisos de los adjuntos del chat:** solo quien ve la conversación; los de un mensaje borrado u ocultado, solo quien modera; nunca se borran sueltos (se borra el mensaje); los SVG, siempre como descarga.
- **Subir:** con `XMLHttpRequest` (progreso y cancelar); un audio que falla se reintenta sin volver a grabarlo. Soltar, pegar y el clip.
- **Transcripción en el navegador:** «Transcribiendo…», «Transcripción pendiente», «Sin voz» o el texto plegable con «Copiar»; sin Reverb, una consulta cada 15 s para todos los audios pendientes a la vista (60 s con él); deja de preguntar por un audio que ya no se devuelve.
- **Búsqueda:** `MessageSource` busca en el texto, los nombres de archivo y las transcripciones, solo de las conversaciones que se pueden ver y sin borrados, ocultos ni mensajes de sistema, sin tildes en PostgreSQL; `/chat/buscar` con filtros por conversación y tipo y «Ver más» por cursor; también en Ctrl+K.
- **`/admin/transcripciones`:** estado, intentos, último error y «Relanzar» (una o todas las fallidas); de las directas no se enseña ni quién ni dónde (D-071), y nunca el texto ni el audio.

### D-114 · Integración de C1, C2 y C3
- **Una sola API por área:** las pantallas del chat usan las de C2 (`hooks/use-realtime.ts`, `components/realtime`) y C3 (`components/chat/media`); `realtime-bridge` y `media-bridge` solo las reexportan.
- **Una sola fuente de lo multimedia:** cada mensaje lleva `MediaPayload::of` (adjuntos, audio y transcripción), con sus relaciones cargadas a la vez; desaparece `ChatAttachments`.
- **Una sola regla de no leídos:** la del chat (`ConversationDirectory::unreadCounts`), que usa también `UnreadCounts` (C2). Desaparece `/chat/no-leidos`: la navegación parte de la prop compartida `chat.unread` y, en cuanto llega, usa el contador de C2 (el chat llama a `markRead` tras leer y a `refresh` tras silenciar).
- **Eventos duplicados:** llegan también a la pestaña que hizo el cambio; el chat mezcla por id y no pide nada por el aviso de un mensaje que ya tiene o que se está enviando.
- **`audio.transcribed`** lleva solo ids, como los demás eventos de C2; el reproductor pide el estado a `chat.media.transcriptions` y comparte la suscripción al canal de la conversación.
- **Tipos de aviso** hasta el catálogo de la Fase 7: `chat.direct`, `chat.mention` (también `@todos`) y `system.transcriptions_failing`.
- **Inicio:** la tarjeta «Menciones» enseña las 5 menciones más recientes (personales y `@todos`, 14 días) y las 5 conversaciones con más mensajes sin leer, en la prop diferida `chat_summary` (`chat` es la compartida).
- **Enviar con archivos:** «Enviar» publica el texto con los archivos pendientes en un solo mensaje; si falla, el texto no se borra.
- **Desde el chat:** «Buscar en el chat» y «Activar avisos en este navegador» en la cabecera de la lista.
- **Datos de ejemplo:** chat en tres proyectos, dos directas y un grupo, sin audios ni adjuntos.
- **CI de los E2E:** Reverb local y el transcriptor falso con la cola síncrona.

## 02/10/2026: Decisiones tomadas en autonomía en la revisión global de la Fase 6
Correcciones de la revisión adversarial de la Fase 6. Concretan D-068 a D-072 y D-110 a D-114.

### D-115 · Moderación y solo lectura **[concreta D-069 y D-071]**
- **Proyecto archivado:** su chat es de solo lectura para todo: tampoco se borra lo propio (`MessagePolicy::delete` exige poder escribir), ni se edita, reacciona, fija o crea una tarea.
- **Mensaje ocultado:** su autor ya no lo puede borrar (el moderador conserva su contenido) y la auditoría de la moderación (`activity('chat')`) guarda el texto que tenía.
- **Campana:** cada aviso del chat guarda su mensaje (`notifications.chat_message_id`, con índice) y, al ocultarlo o borrarlo, se vacía su extracto (`ChatNotificationExcerpts`); no se restaura al volver a mostrarlo. Un aviso que la cola aún no ha enviado no se envía si el mensaje ya está borrado u ocultado. Lo ya enviado por Web Push no se puede retirar.
- **Volver a un proyecto:** lo publicado mientras no se estaba cuenta como leído (solo avisa de lo nuevo).

### D-116 · Transcripción acotada **[concreta D-070]**
- **Tamaño:** el audio no puede pesar más de 16 KB por segundo declarado (el navegador graba a 64 kbit/s, unos 8 KB/s; el margen es para mp4 y ogg), más 64 KB de cabeceras.
- **Duración procesada:** `whisper-server` 1.9.4 admite el campo `duration` (milisegundos) en `/inference` (`examples/server/server.cpp`, `get_req_parameters` → `wparams.duration_ms`): se procesa como mucho el máximo de los audios (`max_audio_seconds` más 2 s). La respuesta trae la duración real, así que un fichero más largo queda transcrito hasta el máximo y `/admin/transcripciones` lo señala con «Supera el máximo».
- **Sin reintentos infinitos:** tras 9 intentos se avisa al admin una vez (D-070) y se reintenta como mucho cada hora hasta 18 intentos; a partir de ahí ya no se relanza sola y solo el admin la relanza. Si el worker corta el job por tiempo, la transcripción queda fallida con su error. Antes de volver a encolar una fallida o interrumpida se suelta su candado de job único (si el worker se cayó, seguiría puesto 2 h).

### D-117 · Reacciones con los emojis del selector **[concreta D-110]**
- Se admite como reacción cualquier emoji de los datos del selector (Emojibase 16 de `public/emojibase/es/data.json`, con tonos de piel), comparados sin el selector de variación U+FE0F; así entran 0️⃣ o ℹ️ y nunca texto. La lista va en caché, renovada con la fecha del fichero.

### D-118 · Pestaña Archivos con los adjuntos del chat **[concreta SPEC §6 y D-113]**
- Incluye los adjuntos del chat del proyecto solo para quien ve esa conversación (D-071), sin las notas de voz (se escuchan en el chat) y sin los de mensajes borrados u ocultados salvo para el admin que modera (como `AttachmentPolicy`). Cada uno enlaza a su mensaje y nunca se borra suelto. El filtro por tarea los deja fuera; el filtro por tipo, no.

### D-119 · Grupos y moderación del admin **[concreta D-071]**
- **Moderar:** el admin tiene «Moderar conversaciones» en la lista del chat (`/chat/moderar`): los chats de proyecto y los grupos en los que no participa (nunca las directas); los abre en modo moderación.
- **Gestión del grupo:** lo renombran y añaden o quitan personas quien lo creó (mientras siga en él) o el admin; cualquiera de sus participantes puede salir. Todo pasa por `ConversationDirectory` (con `ConversationPolicy::manage` y `::leave`) y cada cambio deja un mensaje de sistema (`group.renamed`, `group.added`, `group.removed`, `group.left`). Quien entra ve como leído lo anterior.

### D-120 · Seguridad del tiempo real **[concreta D-068, D-111 y D-112]**
- **`/broadcasting/auth`** exige también el 2FA obligatorio (`2fa`), como el resto de rutas internas.
- **Orígenes de Reverb:** `REVERB_ALLOWED_ORIGINS` (hosts separados por comas); por defecto, el host de `APP_URL`; nunca `*`. Al desplegar hay que reiniciar `audax-reverb.service` para que lo lea.
- **Peticiones del chat:** se autorizan antes de validar (un admin no distingue una directa ajena por los errores de validación).
- **Service worker:** la URL de un aviso se resuelve como lo haría el navegador y se exige el mismo origen (tabuladores, saltos de línea o barras invertidas no llevan a otra web).
- **Whispers:** «escribiendo…» solo cuenta a los participantes de la conversación según el servidor que estén conectados según el canal de presencia, y enseña el nombre del servidor; la presencia solo atiende a los miembros del canal (los firma Reverb) y, si Reverb indica quién envía el whisper, solo si coincide.

### D-121 · Interfaz del chat tras la revisión **[concreta D-110, D-111 y D-113]**
- **Foco (WCAG 2.4.3):** los diálogos y popovers abiertos desde un menú devuelven el foco a su botón (o al mensaje) al cerrarse; tras borrar o editar, al mensaje (o al editor si se editó con ↑); al saltar a un fijado o a una cita, al mensaje; al abrir una conversación en el móvil, a su título.
- **Editor:** combobox de menciones con `aria-expanded`, `aria-haspopup`, `aria-controls` (la lista existe siempre) y `aria-activedescendant`. «Responder» le pide el foco sin volver a montarlo: no se pierden el borrador ni una grabación.
- **Markdown:** el análisis tiene un presupuesto de pasos proporcional a la longitud (las marcas que quedan sin analizar se pintan como texto) y cada mensaje se analiza una vez (`MessageItem` memorizado, con manejadores estables).
- **Contadores:** `/tiempo-real/no-leidos` dice hasta qué mensaje ha contado (`latest_message_id`) y el navegador vuelve a sumar los avisos en vivo posteriores: un recuento que llega tarde no pisa un +1 en vivo.
- **Multimedia:** el reproductor solo adopta una URL firmada nueva si el audio no se ha empezado a escuchar o si la actual falla; un audio grabado durante otra subida espera su turno; un «Ver más» de una búsqueda anterior se descarta.
- **Estados con icono y texto visible** («Silenciada», «Solo lectura»; el error de los avisos del navegador, con el token AA) y «Nuevo» con un nombre accesible que empieza por su texto visible.
- **E2E:** el chat de dos navegadores demuestra el tiempo real (el segundo no recarga; cada paso espera menos que cualquier consulta periódica) con «escribiendo…», hilo, reacción y adjunto; en la CI Reverb es obligatorio y su ausencia hace fallar los tests.
## 27/09/2026: Decisiones tomadas en autonomía (Fase 7)

_Detalle y contexto en `docs/PLAN-FASE-7.md`._

### D-073 · Preferencias de notificación **[concreta el SPEC §13]**
- **Catálogo único:** `NotificationCatalog` tiene un evento por cada `kind()` de `AppNotification`, con su grupo, sus canales posibles (en la app, email y Web Push), los que llegan por defecto, a quién se ofrece y si es obligatorio.
- **Una sola decisión de canal:** `AppNotification::via()` pregunta a `NotificationPreferences`. En `users.notification_preferences` solo se guarda lo que cambia cada persona.
- **Por defecto:** los canales de siempre, más el email para horas devueltas y vencimientos y Web Push para menciones y directos. Los avisos de sistema del admin son obligatorios.
- **Resumen diario opcional** a las 08:00: sustituye a los emails sueltos, que quedan en la campana.
- **Recordatorio de los viernes** a las 13:00 para enviar la semana.

### D-074 · Auditoría visible **[concreta el SPEC §14 y §15]**
- `/admin/auditoria`, solo para el admin, sobre `activity_log`.
- Filtros por entidad, persona, acción y fechas; detalle con el antes y el después; exportación a CSV.

### D-075 · RGPD **[concreta el SPEC §15; texto pendiente de asesor]**
- **Texto informativo:** configurable y con versión. El borrador está marcado «pendiente de asesor». Cada persona interna lo lee y lo acepta, y la lectura queda registrada.
- **Retención configurable:** registros de acceso 12 meses, notificaciones leídas 6, auditoría 60 (mínimo 12) y chat sin límite. Nunca se borran horas.
- **Exportación de los datos personales:** ZIP en cola, con URL firmada durante 7 días.

### D-076 · Copias y almacenamiento **[concreta el SPEC §15 y D-029]**
- **Copia externa:** `restic`, lista para activarse con el destino del propietario.
- **Prueba de restauración:** una vez al mes.
- **Avisos al admin:** disco, adjuntos, copia no hecha y restauración fallida.
- **ClamAV:** fuera, sin aprobación.

### D-077 · Observabilidad y cierre
- **Logs:** en JSON.
- **Documentación:** `DEPLOY.md` final.
- **Revisión final:** accesibilidad AA y rendimiento de todas las páginas.

## 03/10/2026: Decisiones tomadas en autonomía durante la implementación y la integración de la Fase 7

Lo que decidieron N (notificaciones) y A (auditoría y RGPD) sin numerar, en sus commits, y lo que se decidió al integrar la Fase 7 con el chat de la Fase 6. Concretan D-073 a D-077.

### D-122 · Preferencias de notificación: página y enlaces (N) **[concreta D-073]**
- **`/ajustes/notificaciones`**, solo para internos: una sección por grupo (`fieldset` y `legend`) con una fila por evento y un interruptor por canal, con nombre accesible «evento: canal». En el móvil los canales van debajo de cada evento, sin scroll horizontal.
- **Obligatorios:** se ven desactivados, con candado y «Obligatorio». Lo que llega con la petición para un obligatorio o un evento que no se ofrece se ignora y no se guarda.
- **Enlaces:** «Preferencias» en el desplegable de la campana y en la cabecera de `/notificaciones`, y la página en la búsqueda global para los internos.

### D-123 · Resumen diario y recordatorio de los viernes (N) **[concreta D-073]**
- **Resumen diario:** no es un evento del catálogo. Va solo por email, por la cola `mail` y con la identidad de la empresa, una vez por persona y día de Madrid (`Cache::add`). Hasta 10 avisos por grupo, con su título y su enlace, y «y N más». Se calcula con dos consultas sea cual sea el número de personas.
- **Recordatorio de los viernes** (`time.week_reminder`, `WeekSubmissionReminder`):
  - a cada persona interna y activa con capacidad esa semana (jornada menos festivos y ausencias aprobadas, `Capacity`) que no la ha enviado (sin semana, abierta o devuelta),
  - con sus horas imputadas frente a la capacidad y el enlace a `/horas?semana=AAAA-Www`,
  - en la app por defecto y por email si la persona lo activa,
  - una vez por persona y semana (`Cache::add` y, si se vaciara la caché, el aviso ya guardado).
- **Interruptor:** `week_reminder_enabled` se cambia en `/admin/ajustes` (sección de Horas). Si la petición no lo trae, se conserva el valor guardado.

### D-124 · Auditoría visible: detalle y rendimiento (A) **[concreta D-074]**
- **Índices** de `activity_log` para fechas, persona, entidad y acción. Consulta por cursor sin N+1: una consulta por tipo de elemento citado, con la papelera incluida para nombrar también lo borrado.
- **Detalle:** nombres legibles de campos, valores (personas, proyectos, enumerados, fechas de Madrid, duraciones h:mm e importes) y elementos. Enlace a la entidad solo si sigue existiendo; lo borrado de verdad se nombra con lo que guardó la entrada.
- **CSV** en streaming, con el límite de exportaciones de los informes (D-085).
- **Ajustes:** los cambios de `/admin/ajustes` quedan en la auditoría (`activity('settings')`) con el antes y el después.

### D-125 · Privacidad: texto, lectura y exportación (A) **[concreta D-075]**
- **Texto:** markdown pintado saneado en el navegador (sin HTML crudo), con versión. Cambiarlo sube la versión y queda en la auditoría. Los plazos de retención y los umbrales de los avisos de almacenamiento también se editan en `/admin/privacidad` y quedan en la auditoría.
- **Exportación:** un ZIP con un JSON y un CSV por sección y un `LEEME.txt` (`BuildPersonalDataExport`), con URL firmada y política. Las secciones se registran en `config('privacy.export_sections')`. Solo datos de la propia persona: nunca contraseñas, secretos del doble factor, tokens ni datos económicos de la empresa.
- **Búsqueda global:** «Auditoría» y «Privacidad» (admin) para el admin; «Privacidad» y «Mis datos» para toda la plantilla.

### D-126 · Retención por lotes y avisos de almacenamiento (A) **[concreta D-075 y D-076]**
- **`app:prune-data`:** borra por lotes cortos (`BatchDelete`), caduca las exportaciones vencidas (borra el fichero) y da por fallidas las que llevan más de 24 h sin terminar. Deja un resumen en el log.
- **`app:check-storage`:**
  - el disco se mide tras la interfaz `DiskUsage`; los adjuntos, frente a `attachments_warning_gb`,
  - el estado de las copias sale del JSON de `config('backups.status_path')`, que escriben los scripts del servidor: copia fallida o de más de 36 h, restauración fallida y copia externa fallida,
  - como mucho un aviso al día por motivo (`StorageWarningNotification` y `BackupWarningNotification`, obligatorios).

### D-127 · Copias: estado para la app, restauración de prueba y copia externa preparada **[concreta D-076]**
- `audax-backup.sh` deja el resultado y los recuentos de las tablas clave en el fichero de estado.
- `audax-restore-check` restaura la última copia en una base temporal de su propio contenedor, compara los recuentos y la borra.
- `audax-offsite` (restic) queda lista y sin activar hasta que el propietario indique el destino.

### D-128 · Avisos del chat y Web Push con las preferencias **[concreta D-072 y D-073]**
- **Canal de Web Push:** `config('notifications.channels.push')` es `WebPushChannel` cuando las claves VAPID del `.env` son válidas (las mismas comprobaciones que `WebPushConfig`) y `null` si no. Se decide al cargar la configuración (con `config:cache`, al desplegar). Con el canal, la columna «Avisos del navegador» de `/ajustes/notificaciones` se activa y la página ofrece «Activar avisos en este navegador» (`PushNotificationsToggle`).
- **Toda `AppNotification` puede ir por Web Push** (`toWebPush` genérico, por la cola `default`). Llega por defecto en los eventos que lo tienen (menciones y directos); el resto, si la persona lo activa.
- **Avisos del chat:** ya no deciden sus canales. Usan `NotificationPreferences` (`chat.direct` y `chat.mention`) y solo pueden **quitar** el navegador cuando `ChatNotices` no lo permite: conversación silenciada o sin navegadores suscritos. Las reglas de D-072 siguen en `ChatNotices`: no avisar con la conversación abierta, silenciadas y agrupación de 5 minutos.
- **Silenciar** quita el navegador, no el email: quien pide el email de las menciones lo recibe (y entra en su resumen diario) aunque haya silenciado la conversación.
- **`TranscriptionsFailing`** (`system.transcriptions_failing`) es obligatorio para el admin: campana y email.
- **Contrato:** un test comprueba que toda `AppNotification`, también las del chat, tiene su evento, y que ninguna sobrescribe `via()` salvo la base del chat.

### D-129 · El chat en la auditoría **[concreta D-074, D-115 y D-119]**
- Entidad «Chat» (log `chat`) con tres acciones: mensajes ocultados, mensajes visibles de nuevo y cambios en los grupos.
- `ConversationDirectory` deja en la auditoría la creación del grupo (con sus personas), el cambio de nombre (antes y después), las personas añadidas o quitadas y quien sale.
- El mensaje moderado se nombra «Mensaje de Ana en Chat de WEB · Web» y enlaza al mensaje (el admin lo abre en modo moderación); el grupo enlaza a la conversación.
- Las conversaciones directas no se auditan ni se nombran por sus personas.

### D-130 · Retención de los mensajes del chat **[concreta D-075]**
- **Sin límite por defecto.** Con un plazo (`retention_chat_messages_months`, mínimo 1 mes), `ChatMessagesPruner` borra **de verdad**, por lotes, cada mensaje más antiguo con todo lo que solo existe por él:
  - sus adjuntos y notas de voz (filas y ficheros, también las miniaturas) y la transcripción de cada audio,
  - sus reacciones y menciones,
  - los avisos de la campana que hablan de él (`notifications.chat_message_id`).
- **Se conservan:** las conversaciones y sus participantes, las tareas creadas desde un mensaje y, como siempre, horas, bolsas, tareas y proyectos. Las respuestas más recientes pierden la cita (`parent_id` a null).
- La auditoría de una moderación guarda el texto del mensaje ocultado (D-115) y sigue el plazo de la auditoría, no el del chat.
- Los ficheros se borran después de las filas: un fallo del disco deja como mucho un fichero huérfano, nunca un adjunto sin fichero.

### D-131 · Los mensajes del chat en la exportación de datos personales **[concreta D-075]**
- Sección `mensajes-chat` (JSON y CSV): conversación (proyecto, grupo o «Directa con» la otra persona), tipo, texto con las menciones como @Nombre, adjuntos, transcripción de los audios propios, respuesta y fechas de escritura, edición, borrado y ocultación.
- Nunca los mensajes de otras personas ni los de sistema. Incluye los borrados que siguen guardados y los ocultados por el admin.

### D-132 · Colas tras la integración
- **`app:notify-due-tasks`** envía por la cola (`notify`): la campana por `default` y el email de `task.due` por `mail`, sin esperar al SMTP dentro del programador. `Cache::add` sigue evitando el doble aviso aunque la cola aún no se haya procesado; si además se vaciara la caché antes de procesarla, podría repetirse uno (riesgo aceptado).
- **`REDIS_QUEUE_RETRY_AFTER` = 660 s** (valor por defecto y `.env.example`), por encima de la exportación de datos personales (600 s): Horizon ya no puede darla por perdida y lanzarla otra vez. La conexión `redis-transcriptions` no cambia (2700 s). Un test compara `retry_after` con los timeouts.

### D-133 · Aviso de privacidad, E2E y repaso de la interfaz **[concreta D-075]**
- **Aviso de lectura:**
  - va en el flujo de la página (no flota ni tapa nada) y a 375 px apila el texto y el botón,
  - deja su alto en `--privacy-banner-space` y las páginas de altura fija, como el chat, lo restan: el editor del chat sigue a la vista.
- **E2E:** el `DemoDataSeeder` deja leído el texto vigente a toda la plantilla de ejemplo salvo a Daniel Ortega (`PRIVACY_PENDING`). Así ningún E2E depende de que el aviso esté o no, sin añadir pasos al login de `tests/e2e/support.ts` (el login está limitado por minuto). `privacy.spec.ts` usa a Daniel (`PRIVACY_PENDING_USER`) para ver el aviso, también a 375 px, y para aceptarlo.
- **Calendario:** mientras se pide la propuesta, se pregunta por las sucesoras o se guarda un movimiento, el selector Lista, Kanban y Calendario se desactiva con su motivo visible y anunciado. Antes, el cambio de vista se ignoraba sin aviso.
- **Búsqueda global:** el campo se anuncia «Buscar en la aplicación». cmdk nombra el campo con la etiqueta del `Command` (`aria-labelledby`), que tapaba el `aria-label`.
- **Limpieza:** se borra `pages/placeholder.tsx` y sus textos: ninguna ruta lo usa desde la Fase 6.

### D-134 · Colaboradores externos **[amplía SPEC §5 y D-021]**
Pedido por el propietario el 03/10, para quienes trabajan con la agencia sin ser plantilla (Amparo y los colaboradores de AgenciaSEO y Melodía).
- **Rol nuevo `collaborator` («Colaborador externo»):**
  - es interno, porque usa la app y no el portal,
  - está **restringido a los proyectos de los que es miembro**, que el admin o el gestor le asignan como a cualquier miembro.
- **Qué puede hacer:**
  - **Proyectos:** ver los suyos (resumen sin datos económicos, tareas, archivos y chat del proyecto),
  - **Tareas:** las de esos proyectos, con los mismos permisos que un miembro (crear, editar, comentar, adjuntar),
  - **Mis tareas e Inicio:** solo las tarjetas que le afectan,
  - **Horas:** solo las suyas y solo en tareas de sus proyectos; nunca en el proyecto interno,
  - **Chat:** solo las conversaciones de sus proyectos, con las menciones limitadas a sus miembros; ni directos ni grupos,
  - **Otros:** notificaciones, sus ajustes y su privacidad.
- **Qué no ve:**
  - clientes, bolsas, informes, carga, planificación general, Gantt global, ausencias del equipo, plantillas, auditoría y administración,
  - personas fuera de sus proyectos,
  - ningún dato económico,
  - nunca es gestor de proyecto.
- **Seguridad:**
  - las rutas internas están **cerradas por defecto** para el colaborador y solo se abren las de una lista explícita,
  - en esas rutas, las políticas y las consultas usan `User::visibleProjectIds()` / `canSeeProject()`,
  - un test recorre **todas** las rutas internas con un colaborador.
- **Aprobación de horas (D-020):** las aprueba el responsable de su departamento si tiene uno (Amparo, en Diseño, por Aga) y, si no, un admin.
- **Concreción al implementarlo (agente A, 03/10):**
  - **Rutas:** la lista está en `config/collaborators.php` y la aplica el middleware `collaborator` (`RestrictCollaborators`) antes de buscar los modelos de la URL. Abiertas: Inicio, Mis tareas, Proyectos (listado y pestañas Resumen, Tareas, Gantt del proyecto, Chat y Archivos), tareas y sus subrecursos, horas propias y temporizador, notificaciones, chat de proyecto, tiempo real, búsqueda, privacidad propia y ajustes personales. También quedan cerradas sus **propias ausencias** (`/ausencias`): no es plantilla.
  - **Pestañas del proyecto:** Bolsas, Horas (las de todos) y Ajustes, ocultas y cerradas; el resumen no enseña las bolsas y la actividad omite la de las bolsas.
  - **Inicio:** sin «Mis indicadores» (son informes), «Mi carga» ni «Mis ausencias».
  - **Consumo de bolsas:** en los selectores de bolsa le llega `consumed_pct: null` y solo ve el nombre; en el listado de proyectos, `hour_banks: null`.
  - **Permisos globales:** `Gate::before` le niega siempre `view-financials`, `view-hour-banks`, `approve-time`, `lock-time`, `manage-users`, `manage-settings` y Horizon, aunque se le diera el permiso por error.
  - **Personas:** solo es responsable de una tarea o mencionado en los proyectos de los que es miembro (lo valida el servidor para todos); él solo asigna y menciona a miembros. Nadie le abre directas ni le añade a grupos, y no sale en la lista de personas del chat.
  - **Presencia:** no entra en el canal «online» de la plantilla; con latidos solo ve a las personas de sus proyectos.
  - **Proyecto interno:** aunque alguien le añada como miembro, no imputa en él (`time.errors.collaborator_internal`).
  - **Cambio de rol:** al pasar a colaborador deja de ser responsable de departamento y co-gestor de sus proyectos; si es gestor principal de alguno, el cambio se rechaza hasta elegir otro.
  - **Listado de proyectos:** los filtros solo ofrecen los clientes y gestores de sus proyectos, sin departamentos.
- **Revisión de seguridad (03/10):**
  - **Menciones en tareas:** las de la descripción (alta y edición) pasan por el mismo filtro que las de los comentarios (`TaskMentions`). Además, `TaskNotifier` descarta a quien no ve el proyecto de la tarea: ningún aviso de una tarea llega a un colaborador que no es miembro, aunque siga como seguidor o mencionado.
  - **Al dejar de ver un proyecto** (sacarlo de él, mover una tarea a un proyecto del que no es miembro o pasar a colaborador), `CollaboratorOffboarding` le quita de los seguidores de sus tareas, las deja sin responsable y descarta sin imputar su temporizador en ellas.
  - **Al pasar a colaborador** (administración e importación de ClickUp), además, deja de ser co-gestor y sale (`left_at`) de sus directas y grupos; el histórico se conserva.
  - **Tareas que vencen:** el aviso diario solo incluye las de sus proyectos, como Mis tareas.
  - **Menciones `<@ID>` del chat, para todos:** solo resuelven nombre y avatar de quien participa o ha participado en la conversación o tiene su fila en `message_mentions` del mensaje (`ChatUsers::mentionable`); el resto se pinta como «Persona desconocida». Se aplica en los mensajes, los fijados, la lista de conversaciones, Inicio, la búsqueda del chat, los avisos y la tarea creada desde un mensaje. Quién ocultó un mensaje solo se envía a quien modera.
  - **Red de seguridad del chat:** los avisos (`ChatNotices`), las menciones de Inicio y los destinatarios del tiempo real (`ChatRealtimeRelay`) aplican la regla de `ConversationPolicy` (`User::withinConversationScope`), aunque siga como participante.
  - **Sin cantidades:** al imputar, el rechazo de una bolsa sin exceso y el aviso de exceso parcial no le dicen el saldo («La bolsa no admite más horas», «Parte de esta entrada se registrará como exceso»).
  - **Horas de todos y presupuesto:** `logged_minutes` del proyecto, de sus tareas (lista, kanban, panel y Mis tareas) y del Gantt del proyecto, y `budget_minutes`, le llegan a `null`, y la interfaz no enseña nada; en el panel ve solo sus horas.
  - **Comentarios:** `TaskCommentPolicy` comprueba `canSeeProject`: tras salir del proyecto ya no edita ni borra los suyos de allí. Las personas que aparecen en el panel de la tarea por su histórico (seguidores, autores y creador) se mantienen: es una decisión de producto.

### D-135 · Importación de ClickUp: alcance y correspondencias
- **Alcance:** todo el espacio «Audax Studio» y su historial (desde marzo de 2024).
  - Fuera quedan los espacios personales «Alfredo» y «CHELE» (indicado por el propietario) y «Recursos» (pruebas y plantillas).
- **Clientes:** cada carpeta es un cliente, con el nombre sin el emoji. Las carpetas archivadas dan clientes inactivos.
- **Proyectos y bolsas:** cada lista se lee con el patrón `TIPO+N - Hh - descripción - Fxxxxxx [Cliente]`.
  - **BH (bolsas):** las de un cliente se agrupan en un proyecto «Bolsa de horas» (`hour_bank`), con una bolsa por lista. Las horas salen del nombre y el código F va a `invoice_reference`. La última sigue activa y las anteriores pasan a «renovada» (`renewed_from_id`).
  - **FE (fees mensuales):** proyecto `time_and_materials`. Las horas del fee van en la descripción.
  - **Resto de códigos** (WE, EC, PR, AD, AM, BR, BD, GE…): proyecto `fixed_price` con `budget_minutes` igual a las horas del nombre.
  - **Listas sin código:** proyecto `time_and_materials` sin presupuesto.
  - **Archivadas:** las listas o carpetas archivadas dan proyectos archivados y bolsas cerradas.
- **Audax Interno:** cada una de sus listas (General, Marketing, Presupuestos…) es un proyecto interno (`internal`) sin cliente.
- **Tareas:**
  - **Estados:**
    - terminada, finalizada y archivada pasan a la categoría `done`,
    - backlog y por hacer, a `todo`,
    - el resto (en progreso, gestión, estrategia, cm…), a `in_progress`.
  - **Personas:** el primer asignado es el responsable y los demás pasan a seguidores.
  - **Subtareas:** de un solo nivel. Las más profundas cuelgan de su tarea raíz.
  - **«Área» = tipo de tarea, con su departamento:**
    - UI, UX, Maquetación, Branding, Design System e Investigación → Diseño,
    - Desarrollo → Desarrollo,
    - Contenidos, Estrategia y SEO → Marketing,
    - Gestión y Definición, sin departamento.
  - **Facturable:** el campo «Facturable» o «Factor facturable» = 1. «Naturaleza: No productiva» no es facturable.
- **Miembros de cada proyecto:** quien tiene tareas asignadas o horas en él. El gestor principal es el admin o responsable con más horas en el proyecto o, si no hay, Alfredo.
- **No se importan, de momento:** comentarios, adjuntos y etiquetas (pocas). Se puede añadir más adelante.
- **Concreciones del importador** (`app:import-clickup`):
  - **Listas archivadas:** la API no las devuelve dentro de las carpetas archivadas. Se recuperan de los registros de horas (`task_location`) y dan proyectos archivados, o bolsas cerradas, con las tareas de esos registros (título y estado). En el export real son 76 listas y unas 3.500 entradas.
  - **Clientes:** dos carpetas con el mismo nombre (una activa y otra archivada) son el mismo cliente, activo si alguna lo está.
  - **Nombres:** sin emoji, sin el cliente entre corchetes y sin el código F. «??h» y «0h» no dan presupuesto ni aparecen en el nombre. Un código F en una lista que no es de bolsas va a la descripción.
  - **Códigos:** con `ProjectCodeSuggester`, a partir del cliente y del código de la lista (`CLIENTE-FE1`); `CLIENTE-BH` para el proyecto de bolsas e `INTERNO-…` para los internos, con sufijo si se repite.
  - **Listas sin código:** por horas, aunque el estado de la lista en ClickUp diga «bolsa de horas» (tres archivadas).
  - **Estados:** además de los nombres de D-135, los de tipo `done` o `closed` van a hecha. Dentro de la categoría se usa el estado local del mismo nombre («En revisión») o el primero.
  - **Tipos:** «UI» es el «Diseño UI» que ya existe y la errata «Definción» es «Definición». Un tipo que ya existe con otro departamento pasa al de D-135: Maquetación deja Desarrollo y pasa a Diseño.
  - **Prioridad:** la de ClickUp tal cual; sin prioridad, normal.
  - **Seguidores:** solo los demás asignados, no los seguidores de ClickUp (incluyen al creador y darían avisos de más).
  - **Subtareas en ciclo** (dos en el export): quedan como tareas sueltas.
  - **Fechas:** si el inicio es posterior al vencimiento, la tarea se queda sin inicio. `completed_at` sale de `date_done`, `date_closed` o `date_updated`.
  - **Descripción:** el Markdown si lo hay (CommonMark sin HTML) o el texto plano en párrafos, siempre saneado con `RichText`.
  - **Miembros:** personas activas con tareas asignadas u horas. Una colaboradora no entra en un proyecto interno aunque tenga horas históricas en él (sus horas sí se importan, D-134).
  - **Gestor principal:** se recalcula en cada ejecución y el anterior sigue como gestor. El gestor por defecto es el `default_manager` del fichero de personas (Alfredo) o, si falta, el primer admin activo.
  - **Bolsas:** empiezan en la fecha de la lista o, si no tiene, en su primera actividad. Las cerradas llevan la fecha de la importación y al gestor por defecto como quien cierra. La cadena de renovaciones se rehace en cada ejecución.
  - **Tareas de las listas recuperadas:** facturables salvo en los internos, porque sus campos no llegan.

### D-136 · Importación de ClickUp: horas e idempotencia
- **Horas:**
  - **Valores:** cada registro da una entrada con la fecha de inicio en Europe/Madrid y la duración redondeada al minuto; las de 0 min no entran.
  - **Escritura:** siempre con `TimeEntryWriter`, en su modo de importación, que no aplica las validaciones de quien imputa a mano (semana enviada, fecha futura, saldo `block`) pero mantiene las invariantes. Al final, `HourBankLedger::recalculate` una vez por bolsa.
  - **Registros sin tarea:** van a una tarea «Horas sin tarea (ClickUp)» de su lista o, si no tiene lista, del proyecto interno General.
- **Estado de las horas:**
  - **anteriores a la semana en curso:** aprobadas y bloqueadas, con su semana aprobada, porque ya están facturadas,
  - **de la semana en curso:** borrador.
  - **Tarifas y costes:** sin instantáneas, porque ClickUp no tiene tarifas.
- **Idempotencia:**
  - **Tabla `import_refs`** (`source`, `kind`, `external_id` → modelo local, única por las tres primeras): una segunda ejecución actualiza lo que ya importó y añade lo nuevo, sin duplicar nada.
  - **Entradas bloqueadas:** nunca se modifican.
  - **Día del cambio:** se vuelve a ejecutar para traer lo último de ClickUp.
- **Personas:**
  - **Origen:** salen de `personas.json` (fuera de Git): correo, nombre, rol, departamento y si es responsable.
  - **Cuentas existentes:** si ya hay una cuenta con ese correo, se le actualizan el rol y el departamento.
  - **Invitaciones:** el importador **no** envía invitaciones. Se envían después, con el visto bueno del propietario, desde la administración o con `--invitar`.
- **Simulación:** `--dry-run` hace todo dentro de una transacción que se deshace y muestra el informe de recuentos.
- **Antiguos empleados (propietario, 03/10):**
  - se crean como cuentas **desactivadas** (`"active": false` en `personas.json`) para saber quién hizo qué,
  - siguen como responsables de las tareas hechas; las abiertas quedan sin ellos, para repartirlas,
  - nunca son miembros, seguidores ni gestores.
  - Sus correos en la app son marcadores `…@antiguos.audaxstudio.invalid`: varios usaban buzones compartidos (copy@, marketing@, ux@) que pueden volver a asignarse.
- **Acceso a listas:** el campo `"lists"` de `personas.json` (ids de listas de ClickUp) hace a la persona miembro de los proyectos de esas listas, aunque no tenga tareas ni horas.
  - Así entran los colaboradores invitados en las listas a las que tenían acceso en ClickUp (consultadas con `GET /list/{id}/member`): Daniel (Abordo Congelados EC1 y BEOS GE1), Raúl (Mvoca WE1) y Pablo (Melodía WE1 y BH2).
- **Concreciones del importador** (`app:import-clickup`):
  - **Exceso antes del bloqueo:** las horas de semanas anteriores se escriben aprobadas y se bloquean después de recalcular las bolsas, para que `HourBankLedger` reparta el exceso (las bloqueadas conservan el suyo, D-019). No se crea un `TimeEntryLock`: el admin las corrige como cualquier bloqueada.
  - **Registros de más de 24 h** (cinco en el export, temporizadores olvidados): se parten en los cambios de día de Madrid, como el temporizador, sin cambiar el total. En el modo de importación no se valida el total del día.
  - **Registros de menos de 30 s:** dan 0 min y se descartan (314 en el export).
  - **Facturable:** el de la tarea, salvo que ClickUp marque el registro como no facturable.
  - **Orden:** `created_at` es el `at` de ClickUp, para que el reparto del exceso no dependa del orden de importación.
  - **Personas sin mapear:** se quitan de las asignaciones y sus horas se descartan, con aviso. Nunca se quita el rol de admin a la última cuenta de administración. Solo admins y responsables pueden ser responsables de un departamento.
  - **Avisos de bolsa:** los umbrales ya cruzados quedan registrados como enviados sin avisar (`HourBankLedger::recordAlertsSilently`), para que la primera imputación en la app no dispare avisos antiguos.
  - **Sin efectos secundarios:** la importación corre con los eventos de los modelos desactivados (`Model::withoutEvents`) y el registro de actividad apagado (`activity()->disableLogging()`, se reactiva en un `finally`). Así no hay avisos, chat, tiempo real ni una fila de auditoría por registro (serían unas 43.000).
  - **Auditoría:** cada ejecución deja una sola entrada, «Importación de ClickUp» (`log_name` `import`, evento `clickup_import`, autor «Sistema»), con los recuentos, los minutos y los descartes en `properties`. `--dry-run` no la deja.
  - **Al terminar**, ya con los eventos activos: el chat de los proyectos con miembros nuevos se sincroniza y `MembershipsChanged` se lanza una vez (caché de informes).
  - **`--invitar`:** invita a las personas importadas y activas que nunca han entrado. Con `--dry-run` no envía nada.
  - **Rendimiento:** lectura en streaming (`JsonArrayStream`) y bloques de 500 en transacción. Una ejecución interrumpida se completa repitiéndola.
  - **Semanas:** las semanas ya aprobadas o bloqueadas no se tocan.

### D-137 · Hoja de estilos de Audax v1.2 como referencia del tema **[modifica SPEC §3.1, D-011 y la convención del radio]**
Pedido por el propietario el 03/10: el tema sigue el kit de maquetación de Audax (`audax-deck.css` y `audax-doc.css`, v1.2, el que se usa para presupuestos y presentaciones). Lo que coincidía ya no se toca: DM Sans, el azul #0171FF y el navy #001B39.
- **Colores:**
  - tinta #0B1B33 (antes #001B39) y texto secundario #5B6B82 (antes #56667A),
  - **fondo gris #F5F6F7** con tarjetas, paneles y barra lateral blancos,
  - bordes #E2E6EC; los de los campos de formulario siguen en #808D9C por el 3:1 de WCAG 1.4.11,
  - superficies suaves #EEF1F5, cabecera de tabla #E9ECEF y azul claro #E6F0FF,
  - ámbar de estado #9A5A1A sobre #FBEEE2 y verde suave #E9F5F0.
- **Excepciones por accesibilidad (AA verificado por test):**
  - **Azul:** #0171FF con texto blanco da 4,37:1. Botones, enlaces y texto azul siguen en #0068EB / #005FD6, casi idénticos; #0171FF queda para marca, foco, barras, marcadores y gráficas.
  - **Verde:** #1E9C6B no llega a 4,5:1 como texto, así que el texto verde es #1E7A4C.
  - **Rojo suave:** la hoja no tiene rojo; el suave pasa a #FDECEB para que el texto secundario cumpla encima.
- **Estilo plano:** sin esquinas redondeadas (radio 0; los círculos de avatares y puntos se mantienen) y sin sombras. Los radios escritos a mano (`rounded-[3px]`…) pasan a los tokens del tema.
- **Tipografía:**
  - DM Sans en 400, 500 y **600 para negritas y cifras**, nunca 700,
  - interletrado de -0,02 em en textos de 20 px o más y de 0,12 em en etiquetas en mayúsculas,
  - cabeceras de tabla en mayúsculas pequeñas, sobre gris y con texto secundario, en todas las tablas.
- **Logo:** el negro oficial #1D1D1B en tema claro y blanco en oscuro.
- **Fondo de marca:** la imagen original de la portada del kit (`public/brand/fondo-marca.jpg`, 33 KB) sustituye al degradado CSS en el login, la cabecera del portal y los estados vacíos grandes, siempre con el velo navy. El contraste se comprueba sobre la propia imagen, en los recortes de `cover` (apaisado, 16:9 y vertical), con `tests/fixtures/brand-cover-grid.json`.
- **Tema oscuro (revisado el 03/10 a petición del propietario):** sigue las pantallas oscuras de la hoja: base **#0B1526** (la de la imagen de portada), superficies #121C30, texto #F5F6F7, secundario blanco al 70 %, separadores blanco al 14 % y azul de selección #0171FF al 20 %. Antes era un navy saturado al 100 % (#000F20 y #001B39), más «azul eléctrico» que la hoja. Ajustes para mantener AA: el texto azul en oscuro pasa a #519AFF y la serie 1 de las gráficas a #0868E0.
- **Se mantiene:**
  - la paleta de gráficas de D-012: varios tonos para distinguir series; la hoja tiene un único azul porque no tiene gráficas de varias series,

### D-138 · Reordenar las tarjetas de Inicio **[amplía SPEC §5.1]**
Pedido por el propietario el 03/10: cada persona puede ordenar a su gusto las tarjetas de Inicio arrastrándolas.
- **Interfaz:**
  - `@dnd-kit/sortable` con `rectSortingStrategy` sobre la rejilla de siempre: cada tarjeta conserva su ancho (las anchas ocupan dos columnas),
  - se coge por un **asa** (`GripVertical`) en la cabecera, un botón «Mover la tarjeta «X»» que se ve al pasar el ratón o con el foco y siempre en pantallas táctiles,
  - **teclado:** espacio o Intro para cogerla, flechas para moverla, espacio o Intro para soltarla y Escape para cancelar, con anuncios en español en la región viva,
  - **ratón y lápiz:** el arrastre empieza al mover el asa 6 px; **táctil:** hay que mantener pulsada el asa 250 ms, para que deslizar el dedo siga desplazando la página,
  - con «reducir movimiento» las tarjetas cambian de sitio sin animación,
  - estilo plano (D-137): mientras se arrastra solo cambia el borde, al azul de marca (`--ring`),
  - «Restablecer orden», junto al subtítulo, solo si hay un orden guardado; al pulsarlo el foco pasa al título.
- **Persistencia:**
  - `users.home_layout` (JSON): lista ordenada de ids de tarjeta; null es el orden por defecto,
  - `PUT /inicio/orden` (`home.layout.update`) y `DELETE /inicio/orden` (`home.layout.destroy`), con un límite de 60 por minuto. Solo ids de la lista blanca (`App\Domain\Home\HomeLayout::CARDS`, la misma que `HOME_CARD_IDS` en TypeScript; `tests/fixtures/home-cards.json` las compara), sin repetir y como mucho tantas como tarjetas,
  - guardado **optimista** al soltar, con una petición JSON que responde 204: con Inertia, la respuesta volvería a calcular todas las props de Inicio. Si falla, las tarjetas vuelven al último orden guardado con un aviso. Soltar en el orden por defecto borra el guardado.
- **Al pintar:** primero las guardadas, en su orden; detrás, en su orden por defecto, las que falten (tarjetas nuevas o que dependen del rol). Se ignoran las guardadas que ya no existen o que no le corresponden: el servidor las quita de la prop `home_layout` y la interfaz solo ordena las que pinta.
- **Colaboradores (D-134):** pueden reordenar sus tarjetas. Las dos rutas están en `config/collaborators.php` y las tarjetas que no ven (carga, indicadores y ausencias) nunca les llegan en la prop.

### D-139 · Exportación unificada de informes (Fase 9)
- Los 10 informes exportables (`ReportKind`) se generan con un único servicio, `ReportFileGenerator`, siempre **con los permisos de quien lo pide**: el mismo contenido que vería en pantalla con esos filtros.
- Lo usan la descarga, el envío por correo, los envíos programados y Google Sheets.
- Cada informe tiene un menú **«Exportar ▾»** con estas opciones:
  - Excel, CSV y PDF,
  - Google Sheets,
  - Imprimir,
  - «Enviar por correo…» y «Programar envío…».
- Todas las descargas, envíos y subidas quedan en la auditoría (`report-delivery`).
- **Implementación (entrega 9.2):**
  - `ReportGenerator` delega en un documento por informe (`app/Domain/Reports/Delivery/Documents`), que comprueba las **mismas políticas que la página** antes de cada título, tabla o PDF. Si el informe ya no existe (un cliente o proyecto borrado), también da `AuthorizationException`.
  - Excel y CSV son **los ficheros de siempre**: el código de los controladores pasó a los documentos y los nombres y columnas no cambian. Los controladores llaman al generador en `?formato=xlsx|csv|pdf`.
  - Cada página de informe recibe `report_request`, y la pestaña Horas del proyecto también.
  - `GeneratedReportFile::mime()` da el tipo real del fichero, también el HTML del motor `html`.
- **Menú:** en la cabecera de cada informe va el menú completo. En cada tabla va uno corto, con Excel, CSV y Google Sheets de esa tabla, porque el PDF, Imprimir y los envíos son del informe entero.
- **Auditoría (9.2):**
  - cada fichero que genera el generador queda como `generated`, con el informe, el formato, los parámetros de ruta, los filtros, el nombre y el título,
  - cada impresión queda como `printed`,
  - en *Auditoría* salen en la entidad «Informes exportados», con el título del informe,
  - el envío y Google Sheets (9.3 y 9.4) añaden sus propios eventos con el destino,
  - el PDF de bolsa que descarga un cliente desde el portal no pasa por el generador ni se audita.

### D-140 · PDF e impresión con el estilo de Audax
- **Maquetación:** el PDF se maqueta en HTML con la hoja de documentos A4 de Audax (`audax-doc.css`, D-137) y DM Sans incrustada:
  - portada con el título, el periodo y los filtros,
  - cifras clave,
  - tablas con cabecera gris y totales,
  - cabecera y pie con «Audax Studio», `audaxstudio.com` y el número de página.
- **Conversión:** con **Gotenberg** (Chromium en Docker, licencia MIT), solo en `127.0.0.1`, con límite de memoria, núcleos 6-7 y tiempo máximo por documento.
- **Imprimir:** abre el mismo HTML en una pestaña y lanza el diálogo de impresión del navegador.
- **El PDF de bolsa (FPDF, D-045 y D-095)** pasa al nuevo motor si el resultado es equivalente. Si no, se mantiene y queda anotado.
- **Hecho en la 9.2:**
  - **Todo dentro del HTML:** el CSS (copia literal de `audax-doc.css` más `report.css`), DM Sans 400/500/600 (woff2 latin de `@fontsource/dm-sans`, OFL, en `resources/fonts`) y el logo van incrustados. Gotenberg recibe solo `index.html` y no carga nada, y la pestaña de imprimir es exactamente el mismo documento.
  - **Motor configurable** (`REPORTS_PDF_DRIVER`): `gotenberg` en el servidor y `html` en los tests, la CI y en local sin Docker, donde el «PDF» que se descarga es el HTML.
  - **Si Gotenberg falla,** la descarga responde 503 con un mensaje claro y el error queda en el registro. Excel, CSV e Imprimir siguen funcionando.
  - **Qué lleva cada PDF:** portada, cifras clave, las tablas de la página con su fila de totales y «Cómo se calculan las cifras».
  - **Listados largos acotados** (el resto, en Excel):
    - 1.500 entradas en las horas y en las horas para facturar,
    - las 60 tareas con más horas en el estimado frente a real,
    - el detalle diario de una persona, hasta 62 días.
  - **En A4 apaisado:** el detallado (la tabla dinámica, partida en tablas de 10 columnas), las horas y las horas para facturar.
  - **El exceso, en rojo** (#B43A36, el de la app), aunque la hoja de Audax no tenga rojo: en el PDF de FPDF también lo estaba.
  - **PDF de bolsa:** pasa al nuevo motor con la misma información, tanto el interno como el del portal (este sin «sin aprobar», D-095). Se borran `AudaxPdf` y `HourBankStatementPdf`; la dependencia `setasign/fpdf` sigue en `composer.json` hasta la 9.5, para no tocar `composer.lock` en paralelo con las otras entregas.
    - **Retirada en la 9.5:** nada usaba ya FPDF (comprobado con grep) y se quitó con `composer remove setasign/fpdf`. No había fuentes ni recursos propios de FPDF en `resources/` ni en `storage/`.
  - **Imprimir:** `?formato=imprimir` en la URL de cada informe. Abre el HTML del PDF sin la app y llama a `window.print()` con el nonce de la CSP; no descarga nada y queda en la auditoría.

### D-141 · Envío por correo y envíos programados
- **Quién puede enviar:** quien puede ver el informe.
- **Destinatarios:** personas activas de la app y **correos externos**, por ejemplo un cliente. Los externos quedan en la auditoría y la interfaz avisa de que el informe sale de la empresa.
- **Cómo sale:**
  - con el tema de correo de Audax, desde «Audax Proyectos <administracion@audaxstudio.com>» y con el informe adjunto,
  - si el adjunto pasa de 10 MB, lleva un enlace firmado a la descarga, que caduca a los 7 días.
- **Envíos programados (`/informes/envios`):**
  - **Qué guardan:** el informe y sus filtros, el formato (PDF o Excel, uno o los dos), los destinatarios, un asunto y un mensaje opcionales, y el periodo relativo: fijo, en curso o anterior.
  - **Frecuencia:** una vez (fecha y hora), semanal (día y hora) o mensual (día 1-28 o último día, y hora), en Europe/Madrid.
  - **Ejecución:** cada 5 minutos, desde el programador.
- **Seguridad al generar:**
  - cada envío se genera con los permisos **actuales** de quien lo programó,
  - si ya no puede ver el informe, o su cuenta está desactivada, el envío se pausa y se le avisa a él y a los admins,
  - las personas de la app que hayan dejado de estar activas se quitan de los destinatarios.
- **Historial por envío:** fecha, destinatarios, estado y error. Desde el historial se puede «Enviar ahora», pausar o reanudar.
- **Implementación (entrega 9.3):**
  - **Correo:** uno por destinatario (nadie ve las direcciones de los demás), con «Responder» a quien lo envía. Se envían PDF y Excel; CSV no.
  - **Adjuntos grandes:** caben adjuntos hasta 10 MB en total, de menor a mayor; el resto va como enlace firmado a `/informes/descargas/{uuid}`. Es una ruta sin sesión, porque la abre también un externo, y solo funciona con la firma, que caduca a los 7 días. `reports:prune-downloads` borra los ficheros caducados a las 03:20, y cada descarga queda en la auditoría.
  - **Destinatarios de la app:** personas activas de la plantilla, sin clientes ni colaboradores externos (D-134). Como mucho 20 en total, contando los correos externos.
  - **Acceso:** `ReportAccess` repite las políticas de la ruta de cada informe. Un parámetro que no existe cuenta como «sin acceso» (403), y el generador vuelve a comprobarlo al generar.
  - **Estados del envío:**
    - «omitido» si, al generarlo, quien lo envía ya no puede verlo o no quedan destinatarios,
    - «fallido» con su error si falla el generador o el correo. La cola no lo repite, para no duplicar correos; se reintenta con «Enviar ahora».
  - **Pausa automática:** también si no quedan destinatarios. El aviso `reports.schedule_paused` llega por la app y por email, se ofrece a cualquiera y no es obligatorio. Lo recibe el propietario, si sigue activo, y los admins.
  - **«Una vez»:** tras enviarse queda como «Enviado», inactivo y sin motivo de pausa. No se puede reanudar si su fecha ya ha pasado: hay que editarlo.
  - **Programador:** cada ejecución se reclama de forma atómica sobre `next_run_at`, así que dos ejecuciones no envían dos veces.
  - **Borrar una programación:** su historial se conserva sin la programación (`schedule_id` nulo), y queda en la auditoría.
  - **Programar desde `/informes/envios`:** se pueden programar el informe personal, el detallado y el de dirección (si lo ves). El resto, desde «Exportar ▾» de cada informe.
  - **Editar:** cambia la frecuencia, el periodo relativo, los destinatarios, el formato, el asunto y el mensaje, pero no el informe ni sus filtros. Se comprueba que el propietario puede verlo.
  - **Títulos:** el de la lista es el que se veía al programar; el correo usa `ReportFileGenerator::title()` del día del envío.
  - **Memoria de la cola `mail` (9.5):** como ahí se generan el PDF y el Excel de los envíos, la cola `mail` tiene su propio supervisor de Horizon (`supervisor-mail`): un proceso con `memory` 256 y `timeout` 300, y `SendReportDelivery` sube el `memory_limit` de la CLI (128M en el servidor) hasta esos 256 MB. `default` sigue con 2 procesos de 128 MB. Los workers suman 512 MB, el límite de `audax-horizon.service`; con el maestro, el transcriptor y Reverb, 1088 MB dentro de los 1280 MiB de `system-audax.slice`. Lo comprueba `QueueConfigTest` leyendo `deploy/systemd/`, y el reparto está en `docs/DEPLOY.md`.

### D-142 · Google Sheets
- **Conexión:** cada persona conecta su cuenta de Google de Workspace en *Ajustes → Integraciones* (OAuth, tipo «Interno»), con el alcance mínimo **`drive.file`**: la app solo puede tocar los archivos que ella misma crea.
- **Exportar:** sube el XLSX del informe a su Drive convertido a hoja de cálculo nativa y abre el enlace.
- **Seguridad del token:** el token de refresco se guarda cifrado (`encrypted`) y se borra al desconectar o si Google lo revoca.
- **Credenciales:** el ID y el secreto del cliente OAuth los pone el propietario en el `.env` del servidor, nunca en Git. Sin ellos, la opción no se ofrece.
- **Concreción al implementarlo (agente C, 04/10):**
  - **Sin dependencias nuevas:** OAuth y Drive con el cliente HTTP de Laravel (`App\Domain\Integrations\Google`: `GoogleOAuth` y `GoogleSheetsUploader`).
  - **Flujo OAuth:**
    - «Conectar con Google» es un `POST /integraciones/google/conectar` que guarda en la sesión el `state` (40 caracteres) y el verificador **PKCE** (S256), de un solo uso y con 10 minutos de vida, y manda a Google con `access_type=offline`, `hd=audaxstudio.com` y los alcances `openid email drive.file`,
    - `prompt=consent` si la persona no tiene conexión; si ya la tiene, `select_account` (si Google no repite el token de refresco y es la misma cuenta, se conserva el guardado),
    - el callback (`GET /integraciones/google/callback`, `integrations.google.callback`) comprueba el `state`, cambia el código y valida el `id_token` recibido del endpoint de tokens (emisor, audiencia, caducidad, `email_verified`, `hd` y correo del dominio). La firma no se comprueba porque el token llega directamente de Google por TLS (OpenID Connect Core §3.1.3.7),
    - si la cuenta es de otro dominio o la persona desmarca el permiso de Drive (consentimiento parcial), no se conecta y se revoca lo recibido.
  - **Tabla `google_connections`:** una fila por persona, con `refresh_token` y `access_token` cifrados (`encrypted`), `expires_at` y `scopes`. Los tokens nunca salen en JSON.
  - **Renovación:** con un minuto de margen antes de caducar. Si Drive responde 401, se renueva una vez y se reintenta; si vuelve a fallar, o Google responde `invalid_grant`, se borra la conexión y se responde 409 pidiendo reconectar.
  - **Desconectar:** revoca el token de refresco en `oauth2.googleapis.com/revoke` y borra la fila aunque Google no lo confirme (y entonces se avisa de que se puede quitar el acceso desde la cuenta de Google).
  - **Exportar (`POST /informes/sheets`, `reports.sheets.store`):**
    - límite propio de 10 por minuto y persona (`throttle:10,1,google-sheets`),
    - comprueba la conexión antes de generar el XLSX, lo sube en multipart con `fields=id,webViewLink` (Drive v3 no devuelve el enlace si no se pide) y borra el temporal siempre,
    - errores: sin conexión o con el acceso retirado, 409; Google caído, sin red o cualquier otra respuesta de Drive, 502; sin credenciales, 404.
  - **Interfaz:**
    - prop compartida `integrations: {google_sheets, google_connected}`, solo para internos; para un colaborador externo, `google_sheets` es false,
    - el item abre la pestaña en el clic, escribe en ella «Creando la hoja…», le quita el `opener` y le asigna la URL al terminar; si el navegador la bloquea, el enlace queda en el aviso («Abrir»),
    - la entrada «Integraciones» de Ajustes no aparece en el portal ni a los colaboradores externos.
  - **Auditoría:** entidad `report_delivery` (log `report-delivery`) y acción `sheets_exported` (evento `sheets`) en `AuditCatalog`, con el informe, sus parámetros, sus filtros y el título, nunca tokens.
  - **Privacidad (D-075):** la exportación de datos personales lleva `integraciones.json/.csv` con el servicio, la cuenta y la fecha de conexión, nunca los tokens.
- **Cabos cerrados en la 9.5:**
  - **Ficheros grandes:** la subida multipart de Drive admite hasta 5 MB. Por encima, **subida reanudable**:
    - un POST de inicio (`uploadType=resumable`) con los metadatos, incluido el `mimeType` de conversión a hoja de cálculo, y el tipo y el tamaño del contenido,
    - el contenido en PUT a la URI de la sesión, por trozos de 8 MB (múltiplos de 256 KiB) leídos del disco uno a uno, así que nunca está entero en memoria,
    - si un trozo falla por la red o con un 5xx, se pregunta a Drive cuánto ha guardado (`Content-Range: bytes */total`) y se sigue desde ahí, 3 veces como mucho; cualquier otro error, o la sesión caducada, responde 502,
    - un 401 a mitad renueva el token una vez y repite el trozo,
    - solo se acepta una URI de sesión de `www.googleapis.com/upload/drive/v3/files`, porque cada trozo lleva el token.
  - **Desconexión automática:** al desactivar a una persona (la baja de `/admin/usuarios/{user}/baja` y cualquier `is_active = false`, desde `User::booted`) o al pasarla a colaborador externo (`CollaboratorOffboarding::becameCollaborator`, desde la administración o la importación), `GoogleDisconnector`:
    - borra su fila de `google_connections` en el momento, dentro de la misma transacción,
    - y revoca el token en Google desde la cola (`RevokeGoogleToken`), después del commit y sin bloquear. El job va cifrado (`ShouldBeEncrypted`, el token nunca queda en claro en Valkey ni en `failed_jobs`) y tiene un solo intento: si Google no lo confirma, queda un aviso en el registro, sin el token, y no se reintenta.
  - **Auditoría:** entidad «Integraciones» (log `integrations`) y acción «Cuentas de Google conectadas y desconectadas» en `AuditCatalog`, sobre la persona dueña de la cuenta y con su correo de Google, nunca tokens. Eventos:
    - `google_connected`,
    - `google_disconnected`, cuando la persona la desconecta, con si Google confirmó la revocación,
    - `google_auto_disconnected`, con el motivo: baja, paso a colaborador o acceso retirado por Google (`invalid_grant` o un token renovado que Drive rechaza).

### D-143 · Mis tareas: orden por imputación, filtros y paginación **[cambia D-037 y SPEC §6]**
Pedido por el propietario el 03/10: filtros para reordenar y encontrar tareas rápido y, por defecto, las últimas tareas por orden de imputación.
- **Qué entra:** las tareas (y subtareas) asignadas a mí y, aunque no lo estén, aquellas en las que he imputado en los **últimos 30 días** (con la etiqueta «No asignada a ti» y, en su nombre accesible, de quién es). Abiertas salvo «Incluir hechas»; de proyectos sin archivar; un colaborador, solo las de sus proyectos (D-134).
- **Orden** (`?orden=`), con un selector:
  - **«Imputadas recientemente», por defecto:** primero aquellas en las que he imputado más recientemente (la última fecha de mis horas y, a igualdad, la última que registré); después el resto, por vencimiento. Cada fila dice «Imputaste el …»,
  - **«Vencimiento»:** conserva las secciones Vencidas, Hoy, Esta semana, Próximas y Sin fecha (D-037),
  - «Prioridad», «Proyecto», «Creación» y «Actualización».
- **Filtros en la URL:** `?q=&proyecto=&cliente=&estado=&hechas=1&prioridad=&tipo=&vence=vencidas|hoy|semana|sin_fecha|rango&desde=&hasta=`.
  - La búsqueda mira el título, el código y el nombre del proyecto y el cliente (sin acentos en PostgreSQL); se aplica al pulsar Intro o al dejar de escribir.
  - Proyecto, cliente, estado y tipo admiten varios. Las opciones de proyecto y cliente son las de mis tareas. Elegir un estado «hecho» ya incluye las completadas.
  - «Entre fechas» filtra por la fecha de entrega.
  - Lo que no vale en la URL se ignora; nunca da un error.
- **Persistencia:** el último orden y los filtros se guardan por persona en `localStorage` (con try/catch) y se recuperan al entrar sin filtros en la URL. «Limpiar filtros» conserva el orden.
- **Paginación de servidor por cursor, de 50 en 50** («Cargar más»). Las claves de orden son columnas de una tabla derivada y nunca nulas, para que el cursor de Laravel funcione igual en PostgreSQL y en SQLite; un cursor manipulado o de otro orden se ignora.
- **Rendimiento:** una consulta para la página (con mis horas por tarea en una subconsulta, índice `time_entries(user_id, task_id, date)`) y sin N+1; presupuesto de 15 consultas (antes 10).

### D-144 · Calendario del equipo **[amplía SPEC §6 y D-061]**
Pedido por el propietario el 03/10: un calendario donde ver de forma fácil, sencilla y rápida las tareas con fecha y las que cada persona tiene cada día, con filtros completos.
- **Dónde:** `/calendario` (`calendar.index`), en la barra lateral tras Mis tareas, para toda la plantilla y para los colaboradores.
- **Vistas:** **Mes**, **Semana** (por defecto) y **Día**, con «Hoy», anterior y siguiente; vista y fecha en la URL (`?vista=mes|semana|dia&fecha=`). La semana empieza en lunes.
  - Entran las tareas con inicio, entrega o las dos que tocan el rango: vencen en él, empiezan en él o lo cruzan. Una tarea de rango se pinta como **franja** de su inicio a su entrega y su tarjeta va el día de la entrega (o el de inicio si no tiene entrega); los hitos llevan su rombo.
  - **Tarjeta:** el color del proyecto (borde izquierdo), el título, el código del proyecto, el estado con icono y texto y el avatar o las iniciales del responsable. Pulsarla abre el **panel de la tarea** (`?tarea=`) sin salir del calendario: llegan el panel y lo que necesita de su proyecto (`TaskPanelContext`, ahora común con la pestaña Tareas).
  - Como mucho **1.500 tareas** por vista: si hay más, se avisa y se pide filtrar.
- **Vista «Personas»** (`?personas=1`, en la semana y el día): una fila por persona (avatar, nombre y departamento) y otra «Sin asignar» si tiene tareas; en cada celda, sus tareas de ese día (en el día, también las que siguen «En curso»).
  - **Carga:** minutos planificados del día frente a la capacidad, con los colores, el icono y el texto de la Carga (D-052). La planificación es la de `WorkloadPlanner` y la capacidad la de `Capacity` (jornadas, festivos y ausencias). Solo se enseña de quien se puede ver la carga, como en /carga: la propia, la del equipo de un responsable y la de todos para un admin; y solo de hoy en adelante, porque el reparto empieza hoy.
  - **Ausencias:** un día de ausencia aprobada sale como «Ausente» (o «Ausente parte del día») a todos; el tipo, solo a quien puede verlo (`canSeeAbsencesOf`, D-088). Los festivos, a todos.
- **Filtros** en la URL y guardados por persona en `localStorage` (vista y filtros; la fecha no, se vuelve a hoy): persona (varias), departamento, proyecto y cliente (varios), tipo (varios), prioridad, «Solo las mías», «Sin asignar», «Solo hitos», «Incluir hechas» y búsqueda. «Solo las mías» manda; con personas y «Sin asignar» a la vez salen las dos cosas.
- **Interacción:**
  - **Mover** una tarea a otro día arrastrándola (`@dnd-kit`) o con el teclado (flechas e Intro): mueve inicio y entrega lo mismo, conservando la duración (con una sola fecha, esa). Solo con `TaskPolicy::update` y siempre por `schedule.reschedule.preview` y `store`, con la propuesta de sucesoras (D-057). Una tarea que solo tiene inicio se reprograma moviendo el inicio.
  - **Crear** con un clic en el hueco de un día (o su «+»): abre «Nueva tarea» del Gantt con esa entrega, con los proyectos donde se puede crear (prop opcional `creatable`).
- **Permisos:** la plantilla ve todo (D-021). Un colaborador solo ve las tareas de sus proyectos y las personas de ellos, sin departamentos, cargas ni ausencias (D-134); la ruta está en `config/collaborators.php`.
- **Móvil (375 px):** el mes es una lista agrupada por día; la semana y las personas se desplazan dentro de su caja, nunca la página.
- **Rendimiento:** una consulta acotada por el rango (índices nuevos `tasks(due_date, start_date)` y `tasks(start_date)`) leída sin modelos, más padres y responsables en una consulta cada uno; un mes de todo el equipo con 18.000 tareas en la base se sirve en unos 0,2 s en local (antes de leer sin modelos, 1,7 s). Presupuestos de consultas por vista en `tests/Feature/Calendar/TeamCalendarPerformanceTest.php`.

## 05/10/2026: La Weekly dentro de Audax Proyectos (Fase 10)
Respuestas del propietario del 05/10 a las preguntas de `docs/WEEKLY-INVENTARIO.md` §G. Plan en `docs/PLAN-FASE-10.md`.

### D-145 · Fusión de WeeklySync **[amplía SPEC §1 y §6]**
- WeeklySync, la app de las weeklies, **se fusiona** en Audax Proyectos: se reutilizan sus pantallas y su lógica sobre los datos de Audax (usuarios, clientes, proyectos, bolsas, horas y ausencias), con un solo inicio de sesión. Después se apagan Supabase, Vercel, la GitHub Action de recordatorios y Resend.
- **No se pierde ninguna funcionalidad:** la lista F-001 a F-181 de `WEEKLY-INVENTARIO.md` §A.4 es la definición de hecho. La usa solo Audax, así que **se descarta la consola multi-tenant** (F-175 a F-181). De ella se conservan los módulos activos, el aviso global y la página «Uso de IA».
- **Entrar con Google** (F-022) se sustituye por el inicio de sesión de Audax (contraseña y 2FA); el alta, el perfil y la fusión de identidades, por la gestión de personas de Audax.
- **No se copian estos fallos de WeeklySync:**
  - «Iniciar ciclo» que no guarda la semana,
  - borrar a una persona que borra sus weeklies (en Audax se desactiva y su historial se conserva),
  - recordatorios web que no respetan las exenciones,
  - análisis de IA sin control de permisos.

### D-146 · IA: el texto a Gemini, el audio en casa **[cambia SPEC §2 y §12]**
- **El dictado** se transcribe con el **Whisper del servidor**, como en el chat (D-070): el audio no sale del servidor.
- **Solo el texto** va a **Google Gemini**, con una clave de pago de AI Studio (en el plan de pago, Google no entrena con esos datos). Se usa para:
  - el informe semanal,
  - el delta de satisfacción (con la regla determinista portada a PHP),
  - el guion del audio,
  - las tareas sugeridas,
  - los resúmenes de cliente, de equipo y de persona,
  - el asistente,
  - y, opcionalmente, para limpiar la transcripción y corregir nombres.
- **La locución** del informe, con Google Cloud TTS (voz `es-ES-Journey-F` o la vigente).
- **Cómo:**
  - siempre en Jobs de Horizon, en la cola `ai`, de uno en uno; nunca dentro de una petición web,
  - la clave y el modelo, en `.env` (`GEMINI_API_KEY` y `GEMINI_MODEL`), para cambiar de modelo sin desplegar cuando Google retire uno,
  - el uso, los tokens y el coste, en `ai_usage`, visibles en «Uso de IA»,
  - en los tests, `FakeLlm`.
- **El asistente** solo recibe los datos que puede ver quien pregunta.

### D-147 · Quién gestiona la Weekly
- **Escriben la weekly** los internos activos: admin, responsables y empleados. **Los colaboradores externos no** (D-134).
- **La gestionan los admins y los responsables de departamento:** permiso nuevo `manage-weeklies`, que cubre:
  - generar, editar y regenerar el informe y el audio,
  - ampliar el plazo, cerrar y borrar semanas,
  - las exenciones,
  - las reglas y plantillas de recordatorio,
  - el contenido de ayuda y los estados de las sugerencias.
- **Los resúmenes de desempeño y de actividad por persona hechos con IA** (F-144 y F-145) los ven solo el admin y los responsables de esa persona (`canSeeAbsencesOf`, D-088), nunca un compañero. Hay que mencionarlos en el texto RGPD, pendiente de asesor.

### D-148 · Estado de proyectos con datos reales
La pestaña «Estado de proyectos» de las weeklies deja de alimentarse con capturas pasadas por OCR (F-111 a F-118 y F-122 sustituidas). La misma vista (presupuesto, consumido, esperado y desviación por proyecto, con los códigos BH, FE, WE…) se calcula con los proyectos, las bolsas (`HourBankLedger`) y las horas de Audax. Lo esperado de un fee usa los días laborables del mes con los festivos de Audax.

### D-149 · Migración de WeeklySync
- **Se migra todo:**
  - semanas, envíos y apuntes por cliente,
  - exenciones,
  - satisfacción actual e histórica,
  - audios de los informes,
  - contenido de ayuda (FAQ, novedades, tutoriales y manual),
  - sugerencias con votos y estados,
  - reglas de recordatorio y plantillas.
- **Personas y clientes:**
  - se casan por email y por nombre normalizado, apoyados en los códigos de proyecto importados de ClickUp,
  - con ficheros de correspondencias fuera de Git, como en el importador de ClickUp (D-135),
  - los que no casan se crean **inactivos**, para conservar quién escribió qué.
- **Lo que no se migra:**
  - las bolsas de WeeklySync, porque mandan las de Audax,
  - sus tareas, que no tienen proyecto (salvo que casen con uno).
- **Cómo:**
  - `app:import-weeklysync`, idempotente con `import_refs` (fuente `weeklysync`) y con `--dry-run`,
  - lee Supabase en solo lectura,
  - en el servidor, con `heavy.sh` y tras una copia,
  - la última pasada se hace con WeeklySync congelado.

### D-150 · Reglas de la semana
- **Ciclo:**
  - una sola semana activa, de lunes a viernes, con número `Wnn-aa`,
  - la abre el planificador el lunes, y también al cerrar la anterior,
  - el plazo es el viernes y quien gestiona puede ampliarlo,
  - se cierra a mano, con el informe y el audio generados; las personas pendientes no lo impiden, solo se avisa.
- **Envío:**
  - un apunte por cliente, con proyecto opcional,
  - se proponen los clientes en los que la persona es miembro o ha imputado horas esa semana,
  - borrador autoguardado,
  - se puede editar hasta el cierre, también fuera de plazo.
- **Exentos:**
  - quien tiene una ausencia aprobada que cubre el plazo, o una exención manual,
  - quien se da de alta después del final de la semana no cuenta,
  - al cerrar se congelan.
- **Racha:** semanas seguidas enviadas a tiempo; las exentas no la rompen.
- **Recordatorio de los viernes:** uno solo, con las horas (D-123) y la weekly, para no avisar dos veces.

### D-151 · Contrato de datos de la Weekly (entrega 10.1)
- **Exenciones:** `weekly_exemptions` con tres motivos:
  - `manual`: la pone quien gestiona (F-038),
  - `waived`: la propia persona renuncia a la exención que le da su ausencia para poder enviar (F-053),
  - `absence`: solo existe con la semana cerrada; es la foto de la ausencia que eximía (F-092).
  - Mientras la semana está activa, la exención por ausencia se calcula al vuelo (`WeeklyEligibility`): un cambio en las ausencias se nota al momento (F-098). Cuenta una ausencia **aprobada, de día completo**, cuyo rango incluye el día del plazo; con el plazo ampliado, el nuevo día.
  - Ponerla: `manage-weeklies`. Quitarla: quien gestiona o la propia persona. Las dos cosas, solo con la semana activa.
- **Participación congelada:** al cerrar, `WeeklyEligibility::freeze()` guarda quién debía enviar en `weekly_cycles.expected_user_ids` y las exenciones por ausencia. El histórico no cambia aunque luego se desactive a alguien o se cancele una ausencia.
- **Borrador y envío, una sola fila:** `submitted_at` es el **primer** envío y no cambia al reenviar (decide la puntualidad, F-052); `resubmitted_at` guarda el último reenvío.
- **«General / Interno»** (F-044): un apunte con `client_id` nulo.
- **Tareas de «Mi espacio»** (F-055 a F-063): son las `tasks` de Audax (con proyecto), no una tabla aparte. Lo único nuevo es el **archivado personal** (`task_archives`): ocultar una tarea compartida solo de mi lista. Las notas con dictado van a la descripción o a un comentario, como ya se previó.
- **Lo que cabe en `settings`** (sin tablas nuevas):
  - las plantillas de aviso (`weekly_email_templates`; null = las de `lang/es/weeklies.php`),
  - el manual en PDF y el enlace de soporte (`help_manual` y `help_support_url`),
  - los módulos activos (`modules`, F-177) y el aviso global (`global_banner`, F-178), ambos en `PUT /admin/ajustes`.
- **Módulos activos:** weeklies, project_status, help, suggestions y assistant. Apagado, sus rutas dan 404 (middleware `module:`) y la navegación lo oculta (`config.modules`). Las tareas, las bolsas y los presupuestos de WeeklySync son núcleo de Audax y no se apagan.
- **Se reutiliza:**
  - `attachments` (polimórfico) para los vídeos de los tutoriales y los adjuntos de las sugerencias y sus comentarios,
  - `import_refs` (fuente `weeklysync`) para la migración,
  - `notifications` para los avisos,
  - `TranscriptionService` (Whisper) para el dictado (D-152).
- **Estado de proyectos** (D-148): lo ve toda la plantilla, como en WeeklySync y como el consumo de las bolsas en % (D-021). Son minutos, sin importes. Los colaboradores externos no lo ven.
- **Resúmenes IA de una persona** (F-144 y F-145, gate `view-person-ai-summary`): el admin y los responsables de esa persona, **ni siquiera la propia persona**, como dice D-147. Si el asesor de RGPD pide que la persona vea los suyos, basta con cambiar la gate.
- **«Uso de IA»** (F-180): solo admins (gate `view-ai-usage`).
- **Permisos** (D-147): gates `use-weeklies` (internos de plantilla), `manage-weeklies` (admins y responsables), `manage-help` (= `manage-weeklies`), `view-ai-usage` y `view-person-ai-summary`. Todas se niegan a los colaboradores externos aunque tengan el permiso (`COLLABORATOR_DENIED`).

### D-152 · El dictado de la weekly, en su propia tabla
- El dictado por cliente (F-049) y el de las notas de una tarea (F-060) van a `dictations`, no a `audio_transcriptions`.
  - `audio_transcriptions` es 1:1 con un mensaje del chat y tiene la garantía de SPEC §12: todo audio del chat acaba con su texto, se guarda y lo revisa el admin.
  - El dictado es un borrador de texto: se transcribe con el mismo motor (`TranscriptionService`, Whisper del servidor, D-146) en un Job de la cola `transcriptions` y **el audio se borra al acabar**, como en WeeklySync, que nunca lo guardaba.
- Guarda la transcripción literal (`raw_text`), el texto limpio (`text`, F-172) y un aviso (`warning`: sin voz, demasiado corto…, F-050 y F-171). La interfaz muestra «Transcribiendo…» hasta que el estado es `done`.

### D-153 · Reglas portadas de WeeklySync
- **Número de semana:** «Wnn-aa» con la semana ISO del viernes y su **año ISO**. WeeklySync usaba el año natural del viernes: solo cambia cuando el viernes cae en enero y la semana es aún del año anterior (del 28/12/2026 al 01/01/2027: aquí W53-26; allí habría sido W53-27). Las semanas importadas conservan su número.
- **Plazo:** es un día de Madrid. «A tiempo» es antes del final de ese día (F-100). «Próximamente», antes del día laborable anterior al plazo. «Con retraso», pasado el plazo con la semana activa.
- **Satisfacción:** `SatisfactionStabilizer` es un port **exacto** de `satisfaction.js`, con la semántica de JavaScript (`Number()`, veracidad, `Math.round` y espacios Unicode). Lo garantizan 325 casos generados ejecutando el original con node (`tests/fixtures/weeklies/`).

### D-154 · La cola `ai` y las claves de la IA
- **Supervisor `supervisor-ai` en Horizon:**
  - un proceso de 128 MB, `nice` 10 y 600 s por Job (`AiQueue::TIMEOUT`, por debajo del `retry_after` de 660 s),
  - los workers suman 640 MB: hay que subir el `MemoryLimit` de `audax-horizon.service` de 512M a 640M al desplegar la Fase 10 (anotarlo en `SERVIDOR-CAMBIOS.md`),
  - con el maestro, el transcriptor y Reverb suman 1216 MB, dentro de los 1280 del slice.
- **`GeminiClient`:** la API de AI Studio con la clave en la cabecera `x-goog-api-key`, nunca en la URL ni en los registros.
  - Reintenta los 429 (5, 10 y 15 s) y los 5xx o fallos de red (2, 4 y 6 s); nunca los 4xx.
  - Cada llamada, con éxito o error, va a `ai_usage`, con el coste en USD calculado con BCMath (6 decimales); nunca el texto.
- **Google TTS:** con su propia clave (`GOOGLE_TTS_API_KEY`).
- **Variables de entorno:**
  - `GEMINI_DRIVER` y `GOOGLE_TTS_DRIVER` valen `fake` en los tests (`phpunit.xml`) y en local sin claves,
  - las claves reales las pone el propietario en el `.env` del servidor.

## 05/10/2026: Semana y envío de la Weekly, parte de servidor (entrega 10.2a)
Las pantallas son la 10.2b. Detalle de las props de cada página en `docs/PLAN-FASE-10.md` («10.2a (hecho)»).

### D-155 · Abrir y borrar semanas
- **El planificador** lanza `weeklies:open-week` **cada día a las 00:05 de Madrid** (no solo el lunes): el lunes abre la semana nueva y, si el servidor estuvo parado, la abre en cuanto vuelve. Con una activa no hace nada; con el módulo apagado, tampoco.
- **La semana que se abre** (`WeeklyCycleOpener::target()`) es la más tardía de:
  - la semana en curso,
  - la siguiente a la última que existe (una semana cerrada no se reabre),
  - la siguiente a la que se acaba de cerrar o borrar.
  - Si el servidor estuvo parado semanas, se abre la en curso, sin rellenar las perdidas.
- **«Iniciar la semana»** (F-040): quien gestiona, si no hay ninguna activa. Corrige el fallo de WeeklySync, que no la guardaba.
- **Borrar** (F-069): se lleva en cascada envíos, apuntes, exenciones, dictados, audio y satisfacción, y borra los MP3 del disco. Si era la más reciente, se abre la siguiente (+7 días), como en WeeklySync, también al borrar la activa.
- **Cierre (10.3):** `WeeklyCycleOpener::afterClose($cerrada)` es el punto de enganche: tras marcarla cerrada, abre la siguiente.

### D-156 · «Unirme a proyectos» desde la Weekly **[sustituida por D-221]**
- **Sustituida el 06/10 por D-221:** ser miembro daba el chat del proyecto con su histórico, imputar en la bolsa y editar tareas. «Unirme» ya no toca la membresía.
- WeeklySync tenía «colaborador de un cliente». En Audax, eso es ser **miembro de un proyecto del cliente** (F-034 y F-133).
- Cualquier interno de plantilla se apunta como **miembro, nunca gestor**, a varios proyectos abiertos de clientes activos a la vez, y deja los que no gestiona.
- Pasa por `ProjectMembership`: queda en la auditoría del proyecto. Los colaboradores externos no (D-134).

### D-157 · Escribir la weekly
- **Una sola fila** por persona y semana (`WeeklySubmissionWriter`): el borrador autoguardado y el envío son la misma.
- **Autoguardar una weekly ya enviada** cambia sus apuntes al momento, sin tocar `submitted_at` ni `resubmitted_at`. WeeklySync guardaba aparte el borrador. Aquí no hay dos versiones: el informe (10.3) lee siempre lo último guardado. «Actualizar» solo apunta el reenvío.
- **Apuntes:** uno por cliente (si llegan dos, gana el último, en la posición del primero), sin los vacíos y con el texto recortado. Un proyecto de otro cliente se descarta.
- **Clientes propuestos** (D-150): los de los proyectos no archivados de los que soy miembro o gestor, los de los proyectos en los que he imputado horas **de lunes a domingo** de esa semana y los que ya tienen un apunte. El catálogo para añadir otro: los clientes activos con sus proyectos abiertos.
- **«Autocompletar»** (F-048): por cliente, las tareas en las que he imputado esa semana (con el tiempo) y mis tareas terminadas o que vencen esa semana.
- Con la semana cerrada, **solo lectura**. Exento (sin renuncia), tampoco se escribe (F-054).

### D-158 · El dictado, paso a paso
1. El navegador sube el audio a `dictations.store`, con las reglas de los audios del chat: tipo real, tamaño y duración máxima.
2. **Menos de 0,7 s o de 1,5 KB** (F-050): no se transcribe. Queda hecho al momento, sin texto y con el aviso `too_short`.
3. **`TranscribeDictation`**, en la cola `transcriptions`: el Whisper del servidor (D-070). El audio se borra al acabar, con éxito o sin él.
4. **Sin voz útil** (F-171): Whisper no dice «no hay voz», sino que inventa frases de subtítulos sobre el silencio. Se quitan las anotaciones («[Música]») y esas frases. Si queda vacío, solo muletillas o una palabra suelta corta, el dictado queda hecho sin texto y con el aviso `no_speech`.
5. **Limpieza con IA** (F-172): detrás del ajuste `weekly_dictation_cleanup`, **apagado por defecto** (encendido desde D-227, solo en la weekly). Solo va el texto a Gemini (`CleanDictation`, cola `ai`, un intento), con los nombres de los clientes activos y de la plantilla para corregirlos. Si falla o no devuelve nada útil, se queda el texto de Whisper. Con 4 palabras o menos no se llama.
6. **Al terminar** se emite `dictation.updated` por el canal privado de su autor (`App.Models.User.{id}`). Sin Reverb, la interfaz sondea `dictations.show`. Solo su autor ve un dictado, ni siquiera el admin.

### D-159 · Exenciones: poner, renunciar y quitar
- **Poner** (F-038): quien gestiona, a alguien que participa esa semana, con una nota opcional; sustituye una renuncia anterior.
- **Renunciar** (F-053): la propia persona, para poder escribir:
  - si es por una ausencia, queda una fila `waived`,
  - si es manual, se borra; y si además le eximía una ausencia, queda también la renuncia (un solo clic).
- **Quitar:** la manual, quien gestiona o la propia persona. Deshacer la renuncia, la propia persona o quien gestiona.
- La foto de una ausencia de una semana cerrada no se toca.
- Todo, solo con la semana activa.

### D-160 · El contador de «Mi espacio» sin consultas
La prop compartida `weeklies.pending` (F-003) va en todas las páginas. Para no gastar consultas:
- **La semana activa** (id, plazo y última modificación) se guarda en caché 10 minutos y se olvida al guardar o borrar una semana.
- **El contador** se guarda 5 minutos por persona. La clave lleva el plazo y el `updated_at` de la semana, así que ampliar el plazo lo renueva para todos.
- **Se olvida** al enviar, al cambiar una exención y al guardar o borrar una ausencia de esa persona.
- Un alta, una baja o un cambio de rol se notan, como mucho, a los 5 minutos.
- Con la caché caliente no hace ninguna consulta, y las páginas siguen dentro de sus presupuestos (D-046).

### D-161 · Piezas menores de la 10.2
- **Puesto** (`job_title`, F-026 y F-027): opcional, en el perfil y en el alta y edición de personas (120 caracteres).
- **Estadísticas de envío del perfil** (F-028: enviadas, a tiempo y racha): se adelantan de la 10.4. Llegan diferidas y solo a quien escribe la weekly.
- **Bienvenida** (F-012): un aviso al entrar con el formulario, no al volver con «Recordarme».
- **Versión de la interfaz** (F-013): `GET /version` devuelve la versión de Inertia, sin caché, para que la pestaña abierta detecte un despliegue nuevo. La pueden pedir también los colaboradores externos: no tiene datos.
- **Tarjeta «Weekly» de Inicio** (D-138): una tarjeta más, diferida, que no llega a los colaboradores externos.

## 05/10/2026: Semana y envío de la Weekly, pantallas (entrega 10.2b)
Las pantallas de la 10.2 sobre el servidor de la 10.2a. D-162 a D-179 están reservadas para otras ramas: la Fase 10 sigue en D-180.

### D-180 · La Weekly en la barra lateral
- «Mi espacio» y «Weeklies» van **tras «Mis tareas»** (F-001): son de cada semana y de cada persona. Salen con `auth.can.useWeeklies` y el módulo `weeklies` encendido; nunca a un colaborador externo ni a un cliente.
- El contador de «Mi espacio» (F-003, `weeklies.pending`) usa el mismo estilo que el del chat. En WeeklySync era rojo, pero en el tema de Audax (D-137) el rojo es de error y no de aviso.
- La ficha de persona de la tira del equipo (F-067) llega con «Equipo» (10.4). Hasta entonces, cada avatar dice el nombre y el estado, sin enlace.

### D-181 · «Mi weekly» en el navegador
- **Cajas** (F-044): primero los clientes propuestos (D-157), luego los añadidos a mano y, al final y siempre, «General / Interno». Un cliente añadido se puede quitar mientras no tenga texto. El proyecto de cada apunte es opcional, en un desplegable.
- **Al abrir:** sin nada escrito, se despliega la primera caja; ya enviada, todas plegadas; con la semana cerrada, solo lectura y solo las cajas con texto (F-043).
- **Autoguardado** (F-051): cuando se deja de escribir 700 ms, como WeeklySync, con PUT `my-weekly.draft` en JSON y sin recargar. El estado se ve (Guardando…, Guardado el…, No se ha podido guardar con «Reintentar») y se anuncia sin interrumpir. Lo pendiente se manda con `keepalive` al salir de la página y el navegador avisa.
- **Autocompletar** (F-048): añade a cada caja el texto de `autofill` que aún no contiene.
- **La guía de cómo reportar** (F-047): en un desplegable que se abre con clic o teclado, no con el ratón encima (en el móvil no hay «encima»).
- **Enviar:** no se puede mientras hay un dictado en curso, para no enviar sin el texto que falta.
- Al cambiar de semana, al renunciar a la exención o al cerrarse la semana, la pantalla vuelve a empezar con los datos del servidor.

### D-182 · El dictado en el navegador
- Se graba con la grabadora del chat (`useAudioRecorder`): el mismo formato, el límite de duración `max_audio_seconds` y menos de 1 s no se envía. El botón «Dictar» va en cada caja.
- Tras subir el audio, «Transcribiendo…» hasta que llega `dictation.updated` por el canal privado de quien dicta. Siempre hay un sondeo de respaldo de `dictations.show`: cada 3 s sin Reverb y cada 15 s con Reverb, por si se pierde el evento. A los 5 minutos se deja de esperar y se avisa.
- El texto se añade al final del apunte, sin pisar lo escrito, y el apunte queda con la fuente `dictation`. Sin voz útil (`no_speech`) o demasiado corto (`too_short`), se avisa y el texto no se toca.

### D-183 · El resumen y el histórico de /weeklies
- **«Resumen»:** para todos, mi weekly de la semana activa con su botón, mi racha y mis clientes, con «Unirme a proyectos» y «Dejar proyecto». Para quien gestiona, además:
  - el estado global con el porcentaje y la tira,
  - quién falta, con «Eximir» y una nota,
  - los exentos, con «Quitar exención» (las exenciones por ausencia no se quitan a mano, D-159),
  - «¡Todo el equipo disponible ha enviado su weekly!»,
  - sin semana activa, «Iniciar la semana».
- **«Histórico»:** la semana activa y la última cerrada destacadas con su equipo y el estado del informe, y debajo la tabla, que en el móvil pasa a tarjetas.
- **Lo que llega en otras entregas:** «Cerrar semana» (10.3), «Recordar» (10.5) y la pestaña «Estado de proyectos» (10.4).
- Las pestañas son enlaces (`?pestana=`), como las de Ausencias.

### D-184 · Versión nueva y conexión (F-013 y F-014)
- La pestaña pregunta por `GET /version`:
  - al volver a ella,
  - al recuperar la conexión,
  - y cada 10 minutos.
- Si hay un despliegue nuevo, avisa con «Recargar». **Nunca recarga sola**, aunque WeeklySync sí lo hacía: la siguiente navegación de Inertia ya recarga la página por el cambio de versión, y así no se pierde nada a medias.
- Sin conexión, avisa. Al volver la conexión, o a la pestaña tras 10 minutos oculta, se recargan los datos de la página (`router.reload`). Esto sustituye al tiempo real de WeeklySync en todo menos el dictado (F-015).

### D-185 · Módulos, limpieza del dictado y aviso global en los ajustes
- En `/admin/ajustes`, la sección «Weekly y módulos» tiene:
  - los interruptores de los cinco módulos (F-177),
  - la limpieza del dictado con IA (`weekly_dictation_cleanup`, apagada por defecto, D-158),
  - el aviso global (F-178), con su texto y su tipo, informativo o de advertencia. Sin texto, no hay aviso.
- **El aviso global** sale arriba en todas las páginas internas (no en el portal), con su icono, y cada persona lo puede ocultar en su sesión; uno nuevo vuelve a salir. Mide su alto en `--global-banner-space`, para que el chat no quede tapado.

### D-186 · La Weekly en los datos de ejemplo
- El `DemoDataSeeder` (solo en local, tests y CI) crea las tres semanas anteriores cerradas, con los envíos de la plantilla: Elena siempre a tiempo, Pablo una con retraso y Daniel sin enviar la última.
- **No abre la semana en curso** (cambiado en D-187: ahora sí la abre). La abre el planificador o «Iniciar la semana», que es lo que hace el E2E. Con una semana activa, el contador de «Mi espacio» (D-160) gasta consultas con la caché fría en todas las páginas, y los presupuestos de los tests de rendimiento que usan los datos de ejemplo (D-046) se pasarían sin que la página haya cambiado.
- La foto `expected_user_ids` se pone a mano: la plantilla de ejemplo se da de alta el mismo día y `WeeklyEligibility::freeze()` no la vería en semanas pasadas.

## 05/10/2026: Informe, audio y cierre de la Weekly (entrega 10.3)
Detalle de las clases, rutas y props en `docs/PLAN-FASE-10.md` («10.3 (hecho)»).

### D-187 · La semana en curso abierta en los datos de ejemplo y el contador compartido **[cambia D-160 y D-186]**
- El `DemoDataSeeder` abre la semana en curso, como estará en producción (la abre el planificador, D-155), con la weekly de Elena enviada y un borrador de Pablo.
- **El contador de «Mi espacio»** (F-003) ya no se guarda por persona: es **una sola lista** de quién tiene pendiente la semana activa, compartida por toda la plantilla y en caché 5 minutos (la clave lleva el plazo y el `updated_at` de la semana). La calcula la primera página que la necesita (cinco consultas) y las demás personas no pagan nada, aunque su caché personal esté fría (p. ej. al cambiar de usuario en los tests).
- Se olvida igual que antes: al enviar, al cambiar una exención y al guardar o borrar una ausencia; lo demás se nota como mucho a los 5 minutos.
- Con la semana activa, **todos los presupuestos de consultas existentes pasan sin cambiarlos** (C3: 8; aprobaciones: 18).

### D-188 · El informe con Gemini: el pipeline de WeeklySync con los datos de Audax
- **Port fiel de `generate-weekly-report`** (`Report\ReportPipeline` y `LlmWeeklyReportGenerator`): agrupar los apuntes **enviados** por cliente en orden de envío, lotes de 9.000 caracteres (contados como `String.length`), una llamada por lote y otra para fusionar, resumen global y riesgos, «Sin novedades» y el estado por consumo (más del 100 % Blocked; desde el 85 % Risk; en un fee, por encima de lo esperado Risk y con 4 h o más Blocked). **Los prompts son los del original, palabra por palabra.** Cada llamada pide JSON con `responseSchema`.
- **Diferencias con el original, anotadas:**
  - las llamadas van **de una en una** (WeeklySync: 3 clientes a la vez): es la cola `ai` de un proceso (D-154) y el Job tiene 10 minutos; si se acerca el límite, para con «La generación de la weekly tardó más de 10 minutos.», como el original,
  - la **traducción forzada** (F-076) se aplica al texto final de **cada** cliente (el original solo la aplicaba tras fusionar lotes) y al resumen global,
  - si la IA falla con un cliente, su resumen sale de sus apuntes, como en el original; **sin clave** (`LlmNotConfigured`) se para y la semana queda con el error.
- **El contexto de proyectos** (D-148) sale de `Report\WeeklyProjectStatus`, en minutos, por proyecto no archivado del cliente:
  - **bolsa de horas:** la bolsa en curso (activa o agotada, la más reciente que ha empezado), con su total y su consumo de `HourBankLedger`,
  - **fee mensual:** un proyecto «Por horas» cuya descripción empieza por «Fee mensual» (así importa ClickUp los FE, D-135). El presupuesto es `budget_minutes` o las horas de la descripción; el consumo, el del mes hasta la fecha de referencia; y lo esperado se reparte por días laborables del mes, sin fines de semana ni festivos de Audax,
  - **cualquier otro con presupuesto:** todas sus horas frente a `budget_minutes`,
  - los demás, solo si tienen horas esa semana. Siempre con las horas de la semana (de lunes a domingo).
  - La fecha de referencia es el viernes de la semana o hoy, si aún no ha llegado. En el prompt, las horas van como un `Number` de JavaScript («12.5»), como el original.
- La foto de los proyectos se guarda en el informe (`WeeklyProjectSnapshot`, ahora con `week_minutes`), para que un informe pasado enseñe lo que había entonces.

### D-189 · «General / Interno» y los clientes inactivos en el informe
- Los apuntes sin cliente (F-044) **no se pierden**: van al final del informe como «General / Interno» (`client_id` nulo), con su resumen por IA. No reciben «Sin novedades» ni satisfacción.
- Entran los clientes **activos** y, además, los que tengan apuntes esa semana aunque se hayan desactivado (WeeklySync los dejaba fuera y sus apuntes se perdían en el informe).

### D-190 · Generar, seguir, editar y escuchar el informe
- **Generar** el texto o el audio encola un Job en la cola `ai` (`GenerateWeeklyReport` y `GenerateWeeklyAudio`, un intento) y la página vuelve al momento. No se encola otro del mismo tipo mientras haya uno «en cola» o «generando», salvo que la semana lleve más de 12 minutos sin cambios (se da por atascado).
- **Progreso:** el evento `weekly.progress` por el canal privado `weeklies.{id}` (quien puede ver la semana), al empezar, en cada cliente o sección y al terminar; el detalle (paso, hechos y total) también en caché para `weeklies.report.status`, que la página consulta si no hay Reverb. Al terminar, la página recarga sus datos.
- **El texto de una semana cerrada no se regenera** (como en WeeklySync); el audio sí.
- **«Desactualizado»** (F-072): hay más envíos que al generar.
- **Editar** (F-077): quien gestiona, también con la semana cerrada. Por cliente, el estado, el resumen, los pasos y los hitos; además, el resumen global y los riesgos. Los proyectos, la satisfacción y las etiquetas se conservan. Generar de nuevo descarta la edición.
- **El texto para copiar** (`report_text`) es el Markdown que WeeklySync escribía al editar, ahora también al generar: el texto y el informe nunca difieren.
- **Audio** (F-084 a F-087), port de `generate-audio-tts` (modo `scripts`) y de la generación de App.tsx: el guion por secciones con Gemini (entrada, un bloque por cliente con sus reportes originales y el estado de sus proyectos, y cierre; con los mismos prompts), una sección en inglés se traduce, y lo que falta se completa con el texto determinista del original; si la IA no responde, todo el guion sale así. Cada sección se locuta con Google TTS (trozos de 4.500 bytes, MP3) y además se guarda el audio completo unido. Todo en el disco privado: las secciones con URL firmada (relativa) y el completo por ruta con permiso, con Range. Las secciones nuevas sustituyen a las anteriores solo si todas se han locutado; si la locución falla, el error dice que es la locución (`SpeechFailed`).
- **Reproductores:** el principal con una marca por cliente (la posición se reparte en proporción a la duración de cada sección, que se calcula contando las tramas del MP3) y uno en cada tarjeta; solo suena uno a la vez.

### D-191 · Cerrar la semana
- Solo la activa y con el texto y el audio generados (basta el audio completo o una sección); quien falte por enviar no lo impide, solo se avisa (F-089). También desde la gestión del resumen de `/weeklies` (F-035).
- En una transacción con la semana bloqueada: `WeeklyEligibility::freeze()` (participación y exentos, F-092) y la semana cerrada con quién y cuándo. Después, `WeeklyCycleOpener::afterClose()` abre la siguiente (F-070).
- **En segundo plano** (`UpdateWeeklySatisfaction`, cola `ai`): la satisfacción de cada cliente con apuntes, como `close-week-and-update-satisfaction` (mismo prompt, con el historial de sus 5 apuntes anteriores): Gemini propone el delta, `SatisfactionStabilizer` lo amortigua y se guarda en `clients.satisfaction_score`, en `client_satisfaction_snapshots` y en el informe. Como el original, una satisfacción de 0 cuenta como 50. Si la IA falla con un cliente, ese no cambia. Es idempotente: un cliente con su foto de esa semana no se vuelve a mover.
- Al terminar, aunque la IA falle, el evento **`WeeklyCycleClosed`**: el aviso «weekly cerrada» (F-095), sus plantillas, el email y el registro los escucha la 10.5.

### D-192 · PDF, impresión y HTML del informe
- `ReportKind::Weekly` (ruta `weeklies.report.pdf`, parámetro `cycle`) con `WeeklyDocument` y la hoja de Audax (D-140): portada, cifras (clientes, con novedades, en riesgo y bloqueados), resumen global, riesgos y un bloque por cliente con estado, satisfacción, resumen, pasos, hitos, etiquetas y el estado de sus proyectos. En Excel y CSV, una fila por cliente.
- Sustituye a la descarga en HTML de WeeklySync, que se mantiene con `?formato=html` (el mismo documento, sin el diálogo de impresión). `?mios=1` aplica «Solo mis proyectos» (F-080), como hacía la descarga del original.
- Entra en el menú «Exportar ▾» (envío por correo y programación incluidos), con `WeeklyCyclePolicy::view` y el módulo `weeklies` encendido (`ReportAccess`).

### D-193 · «Uso de IA» y la IA de prueba
- **«Uso de IA»** (`/admin/uso-ia`, F-173 y F-180): solo admins. Llamadas, errores, tokens, caracteres y coste estimado de los últimos 7, 30 o 90 días, por función y por modelo, el coste por día y las 50 últimas llamadas. Enlace en Administración.
- **La IA de prueba:** con `GEMINI_DRIVER=fake` fuera de los tests (local sin clave y los E2E de la CI), `FakeLlm::demo()` responde siempre con textos que dicen que no son de la IA y con la forma del esquema pedido. En los tests, el `FakeLlm` vacío (cada test programa sus respuestas). La CI de los E2E fija `GEMINI_DRIVER=fake` y `GOOGLE_TTS_DRIVER=fake`.

## 05/10/2026: Clientes, equipo y estado de proyectos de la Weekly (entrega 10.4)
Detalle de las clases, rutas y props en `docs/PLAN-FASE-10.md` («10.4 (hecho)»).

### D-194 · Los resúmenes con IA de las fichas
- **Cuatro resúmenes** (F-129, F-131, F-144 y F-145): el del cliente, el análisis del equipo en un cliente, el desempeño de una persona y su actividad por cliente. Prompts de las Edge Functions de WeeklySync **palabra por palabra** (`Insights\InsightPrompts`), con los datos de Audax.
- **Se piden a mano y se guardan** en `ai_summaries` (el último de cada tipo para cada cliente o persona) hasta que alguien los regenera; la página dice quién y cuándo. WeeklySync los perdía al recargar.
- **En la cola `ai`** (`GenerateAiSummary`, un intento, D-146): la página vuelve al momento y recarga solo su prop cada 3 s mientras se genera (sin Reverb). No se encola otro mientras uno esté en cola o generando, salvo que lleve 12 minutos sin cambios (atascado, como D-190). Cada llamada va a `ai_usage` (quién la pidió y sobre qué).
- **Sin historial, no se llama a la IA:** se guarda el mensaje del original («No hay suficiente historial…»). Si la IA falla, el resumen queda con el error y se puede volver a pedir. En las respuestas con una frase por persona o por cliente, las que falten se rellenan como el original.
- **Quién los pide y los ve:** los del cliente, quien ve el cliente y usa la Weekly (la plantilla, D-021): solo usan reportes **enviados**, que ya ve toda la plantilla, y minutos de proyectos, sin importes. El análisis del equipo en un cliente resume el trabajo hecho en ese cliente, no el desempeño, así que no lleva la restricción de D-147. Los de una persona, solo el admin y sus responsables (`view-person-ai-summary`, D-147 y D-151): a los demás ni les llegan en la página.

### D-195 · Equipo y responsable del cliente
- **«Equipo»** (`/equipo`, F-134 a F-137) es una página de la Weekly, tras «Clientes» en la barra lateral, para quien la usa y con el módulo encendido. Lista la plantilla activa que escribe la weekly con su departamento, puesto, rol y el estado de su weekly en la semana activa; los filtros y el orden son del navegador (es una lista corta, como en WeeklySync). El alta, la edición y la baja siguen en `/admin/usuarios` (F-138 a F-141): la página y la ficha enlazan allí a quien puede gestionar personas.
- **«Ausente»** sale si una ausencia aprobada de día completo cubre hoy; el tipo (vacaciones, baja…), solo para quien puede saberlo (D-088).
- **La ficha de persona** (`/equipo/{id}`) la ve la plantilla: su estado esta semana, racha, hábitos, clientes, último reporte por cliente e historial de un año. Una persona que no escribe la weekly (colaborador externo o cliente) da 404; una desactivada se ve, con su historial.
- **El responsable de un cliente** (F-128) es quien gestiona más proyectos abiertos del cliente (a igualdad, el del proyecto más antiguo); desde D-232 se puede elegir a mano. **Lidera** un cliente quien gestiona alguno de sus proyectos abiertos y **colabora** quien es miembro sin gestionarlo o se ha unido al cliente en la Weekly (D-221). El **equipo** del cliente son los gestores y miembros de sus proyectos abiertos y quienes se han unido en la Weekly, que escriben la weekly.

### D-196 · «Estado de proyectos» nativo
- La cartera (F-119 a F-121, `ProjectStatusBoard`) son los proyectos **abiertos** (planificados, activos y en pausa) de los clientes **activos**, a fecha de hoy y con las reglas del informe (D-188): la bolsa en curso, el fee con lo esperado por días laborables sin festivos de Audax, el presupuesto o, sin presupuesto, todas sus horas («Sin horas asignadas»). Más las horas de esta semana.
- **El tipo de WeeklySync** (BH, FE, WE, PR, EC, AD, AM, AT, BR y GE) sale del código importado de ClickUp (`CLIENTE-FE1`, D-135) y, si no es uno de esos, del tipo del proyecto: bolsa BH, fee FE y lo demás GE (`ProjectKindCode`). Las tres auditorías comparten grupo, como en WeeklySync.
- La ve la plantilla, en minutos y sin importes (D-151). Cinco consultas como mucho, sea cual sea la cartera.
- **Mismas piezas que el original:** vista por cliente o en tabla, filtros por cliente y tipo, tabla ordenable por cliente, tipo o progreso, barra de consumo con la marca de lo esperado y la desviación (por encima, en tono de peligro; por debajo, de éxito; siempre con texto).

### D-197 · La cartera de clientes
- **Con la Weekly** (módulo encendido y `use-weeklies`), `/clientes` añade el icono, el último reporte, la satisfacción con su tendencia frente al cierre anterior y las insignias por tipo de proyecto (F-096, F-120, F-123 y F-124); sin ella, la lista de siempre.
- **Orden** por nombre, último reporte o satisfacción (`?orden=&dir=`); sin reportes cuenta como el más antiguo, igual en SQLite y PostgreSQL.
- **Filtros:** tipo de proyecto (por grupo), persona y «Mis proyectos» (gestiona o es miembro de un proyecto abierto del cliente). Las personas del filtro llegan diferidas para no pasar del presupuesto de consultas de la lista (D-046).
- **Cabeceras fijas** (F-020): las tablas de clientes, equipo y estado de proyectos desplazan dentro de su caja (hasta el 70 % del alto de la ventana) con la cabecera fija.
- **El icono** del cliente (F-126) se pone en el alta y la edición: un solo emoji.

### D-198 · Detalles portados
- **Hábitos de envío** (F-142): en la hora de Madrid; la hora media se redondea al minuto (WeeklySync truncaba y, por la coma flotante, 14:10 salía 14:09). «Otro día» agrupa de lunes a jueves (en WeeklySync, «Lunes+»).
- **Tendencias de la satisfacción** (F-132): la última puntuación menos la de 1, 4 y 13 cierres antes, como en WeeklySync; la serie sale de `client_satisfaction_snapshots`.
- **Ventanas:** el historial del cliente mira las 26 últimas semanas; el historial de la persona, las 52; el resumen del cliente, sus 10 últimas semanas con reportes (de sus 500 últimos apuntes); el desempeño, los 8 últimos envíos.
- **Recortes de los prompts:** por caracteres, no por unidades UTF-16 de JavaScript (solo cambia con emojis).

## 05/10/2026: Avisos de la Weekly (entrega 10.5)
Detalle de las clases, rutas y props en `docs/PLAN-FASE-10.md` («10.5 (hecho)»).

### D-199 · Los avisos de la Weekly: qué avisa, a quién y dónde se configura
- **Grupo `weeklies` del catálogo** (D-073), visible para quien escribe la weekly con el módulo encendido (nunca un colaborador externo):
  - `weeklies.reminder`: los recordatorios (reglas, envío manual y «Recordar»); canales app, email y navegador; por defecto en la app y por email, como el email de WeeklySync,
  - `weeklies.closed`: «weekly cerrada» con el enlace al informe (F-095), a **todo el equipo activo** que escribe la weekly (el `all_active` del original), una vez por semana, al terminar la satisfacción (`WeeklyCycleClosed`); por defecto, app y email,
  - `weeklies.deadline_changed` (**nuevo**): al cambiar el plazo de la semana activa, a quien aún debe enviarla, salvo a quien lo cambia; por defecto, en la app.
- **A quién recuerda** (F-106): solo a quien **debe** enviar la semana activa y no lo ha hecho (`WeeklyEligibility`: internos activos de plantilla, dados de alta a tiempo, sin exención manual ni por una ausencia aprobada que cubre el plazo; un borrador cuenta como pendiente). WeeklySync no miraba las exenciones en el aviso web (D-145): aquí un exento nunca recibe un recordatorio.
- **Reglas** (F-101 y F-102): una lista con día ISO, hora de Madrid y **un canal por regla**: «En la app» (nuevo), email o navegador (Web Push, que sustituye al `Notification` del navegador con la pestaña abierta). La configura quien gestiona la Weekly (`manage-weeklies`), como todo lo de esta entrega.
- **El canal de la regla manda, y la persona puede quitarlo:** si alguien desactiva un canal en `/ajustes/notificaciones`, ese recordatorio no le llega por ahí (queda «omitido» con el motivo). Con el resumen diario activo, el email va a la campana y al resumen (D-073). El navegador solo llega a quien tiene alguno suscrito y con Web Push configurado.
- **Plantillas** (F-104 y F-105): `automatic`, `manual` y `weekly_closed`, con asunto y cuerpo y las variables del original (`{nombre}`, `{semana}`, `{week_label}` y `{weekly_url}`, que lleva a «Mi weekly» en los recordatorios y al informe en «weekly cerrada»). En la app y en el navegador se usa el asunto como título. Se guarda solo lo que difiere de la de por defecto (`weekly_email_templates`), así que «Restaurar por defecto» la quita. El aviso del plazo y la parte de la weekly del viernes tienen texto fijo.
- **Envío manual** (F-109): a todas las pendientes o a las elegidas (que también tienen que estar pendientes; «a todas» no incluye a quien ya ha enviado, por F-106), con el texto manual o el automático y los canales elegidos. **«Recordar»** (F-037 y F-110): en las pendientes del resumen de `/weeklies`, en la lista de Equipo y en la ficha de la persona, con el texto manual y los canales que la persona tenga activados.
- **Dónde:** una cuarta pestaña de `/weeklies`, «Avisos» (`/weeklies/avisos`), solo para quien gestiona la Weekly: es donde se gestiona la semana, y en `/admin/ajustes` solo entra el admin. La sección «Weekly y módulos» de los ajustes enlaza a ella.

### D-200 · Un solo recordatorio de los viernes **[concreta D-150 y D-123]**
- **Decisión:** «un solo aviso con dos partes». El recordatorio de los viernes a las 13:00 (`time:remind-week`) manda a cada persona **un** aviso con lo que le falte: la semana de horas (D-123) y la weekly de la semana activa. Quien solo tiene pendiente una de las dos recibe solo esa parte (título y enlace según lo que falte: a la hoja de horas o a «Mi weekly»).
- Sigue siendo el evento `time.week_reminder` («Recordatorio de los viernes»), con las preferencias de cada persona (por defecto, en la app). La parte de la weekly queda en el registro de avisos (plantilla `friday`) y respeta las exenciones.
- **Dos interruptores:** las horas, `week_reminder_enabled` (en `/admin/ajustes`), y la weekly, `weekly_friday_reminder` (en «Avisos», activado por defecto). El programador lo lanza si alguno está activo; con el módulo de la Weekly apagado, solo las horas.
- **Las reglas son aparte y no lo duplican:** son los recordatorios que programa quien gestiona (en WeeklySync, el email del viernes a las 16:00). Cada disparo es otro momento y otra clave; un mismo aviso nunca llega dos veces. Por eso no se descarta: el de las 13:00 lleva las dos cosas y las reglas son recordatorios adicionales a otras horas, como en WeeklySync.

### D-201 · Registro y deduplicación de los avisos **[amplía el contrato 10.1]**
- **Cada fila de `weekly_reminder_logs`** es un aviso a una persona por un canal en un disparo, con la plantilla, la semana, el nombre y el email de entonces y quién lo envió a mano. Se **reclama** antes de enviar (índice único `trigger_key`, `channel`, `user_id`): repetir el comando, solapar dos pasadas o dos clics no mandan dos.
- **Claves:** `rule:{regla}:{semana}:{fecha}T{hora}`, `friday:{semana}:{fecha}`, `closed:{semana}`, `deadline:{semana}:{fecha}`, y por minuto `manual:{semana}:{quien}:{minuto}` y `remind:{semana}:{persona}:{minuto}`.
- **Estados:** «en cola» al reclamarla, «enviado» cuando el canal la entrega (`afterSending`), «fallido» con el error si el canal falla (`NotificationFailed`; si la cola lo reintenta y llega, vuelve a «enviado») y «omitido» con el motivo. En las reglas y el envío manual se registra cada canal elegido que no sale; en los avisos «según sus preferencias» (cerrada, plazo y «Recordar»), solo quien no recibe ninguno.
- **El programador** lanza `weeklies:remind` cada 5 minutos (como la GitHub Action) y una regla toca si su hora de Madrid cae en los últimos 10 minutos. Con instantes reales: una hora que no existe al pasar a la de verano (02:30 del último domingo de marzo) sale a las 03:30, en vez de perderse como en WeeklySync, y una que se repite al volver a la de invierno sale una vez (la segunda, la que elige PHP). Si el servidor está parado más de 10 minutos, ese disparo se pierde, como en el original.
- **Cambios compatibles del contrato 10.1:** `WeeklyReminderChannel` gana `app`, `WeeklyReminderStatus` gana `queued` y `WeeklyReminderTemplate` gana `deadline` y `friday` (columnas de texto: sin migración). `AppNotification::$onlyChannels` limita un envío a unos canales (solo quita, nunca añade): lo que decide los canales sigue siendo `NotificationPreferences`.
- **Auditoría:** las reglas (`LogsDomainActivity`), los cambios de plantillas y de la weekly del viernes, y los envíos manuales y «Recordar» (log `weekly-reminders`). Entidades nuevas del filtro: «Weekly (semanas y exenciones)» y «Avisos de la Weekly».

### D-202 · RGPD de la Weekly
- **La exportación de datos personales** (D-075) lleva, además: sus weeklies (una fila por semana y otra por apunte, con «General / Interno»), sus dictados (transcripción y texto; el audio nunca se guarda), sus exenciones (sin quién las puso), **los resúmenes con IA sobre la persona** (desempeño, actividad por cliente y la frase que habla de ella en el análisis del equipo de cada cliente, aunque ella no los vea en la app, D-147) y los avisos de la weekly que ha recibido.
- **Plazos nuevos** en `/admin/privacidad` (`app:prune-data`): el registro de avisos, **12 meses** (lleva nombres y emails), y los dictados, **3 meses** (su texto ya está en el apunte; si quedó algún audio en el disco, se borra con él). **Las weeklies no caducan:** son el histórico del equipo, como las horas.
- Falta el texto RGPD del asesor (pendiente desde la Fase 7): debe mencionar los resúmenes con IA por persona (D-147) y que el texto de las weeklies va a Gemini (D-146).

## 05/10/2026: Tareas de Mi espacio y asistente IA (entrega 10.6)
Detalle de las clases, rutas y props en `docs/PLAN-FASE-10.md` («10.6 (hecho)»).

### D-203 · La pestaña «Tareas» de Mi espacio **[concreta D-151]**
- **Qué tareas:** las **asignadas a mí** (como el TaskView de WeeklySync), de proyectos sin archivar que puedo ver: todas las pendientes y las **100 últimas hechas**, de la más nueva a la más antigua. Agrupadas por el cliente del proyecto; las de proyectos sin cliente, en «Tareas generales». Mis tareas (D-143) sigue igual y se enlaza desde la pestaña.
- **Filtros como el original:** Todas, Pendientes o Completadas, y «Ver archivadas», en el navegador (la lista es corta).
- **Archivado personal** (F-057): `task_archives`, solo para quien archiva; la tarea sigue igual para los demás. Se puede recuperar.
- **Crear** (F-058): «Nueva tarea» con la descripción y el cliente del original, más lo que exige Audax: el **proyecto** (obligatorio; si el cliente tiene uno solo, se elige solo) y, en uno de bolsas, la **bolsa** (la del departamento primero), y la prioridad y la entrega (F-063). Es para mí. Va por `tasks.store` y `TaskWriter`, como el alta de siempre. Se ofrecen los proyectos abiertos en los que puedo crear tareas (`TaskPolicy::create`: miembro o gestor; todos para el admin y los responsables). No se reutiliza el diálogo de D-173: depende de los datos de un proyecto ya elegido y pide campos (responsable, tipo, estimación) que en «Mi espacio» sobran; se reutilizan sus piezas (prioridad y fecha).
- **Editar** el título, la prioridad y la entrega; cambiar de proyecto, desde la tarea. **Marcar hecha** (F-059) pasa al primer estado «hecho» o al estado por defecto. **Eliminar** (F-061) borra la tarea para todos (con confirmación que lo dice) y solo sin horas (D-037). Todo con las rutas y la política de siempre.
- **Notas** (F-060) = la **descripción en texto plano**: una línea por párrafo (`TaskNotes`), con autoguardado al dejar de escribir 1 s y al salir del campo (WeeklySync: 500 ms; aquí algo más, porque cada guardado queda en la actividad de la tarea) y con **dictado** (Whisper del servidor, contexto `task_note` de D-152, solo si puedo editar la tarea). Una descripción **con formato** (negritas, listas, enlaces, menciones) no se pisa: se enseña y se enlaza a la tarea.

### D-204 · Tareas sugeridas por IA: propuestas que se revisan
- **Fuente:** la última weekly cerrada (por fecha de fin), con los reportes **enviados** de todo el equipo, como `extract-tasks`. Sin reportes, no se llama a la IA.
- **Cómo:** Job `SuggestTasksFromWeekly` en la cola `ai` (un intento, D-146), con el **prompt de `extract-tasks` palabra por palabra** y los ids de Audax, y un `responseSchema` con su forma. Cada llamada va a `ai_usage`.
- **Deduplicación de App.tsx** (`TaskSuggestionPrompt::isDuplicate`): repetida si ya tengo (asignada a mí, sin archivar, en cualquier estado) una tarea del **mismo cliente** con la misma descripción o una que contiene a la otra, sin mayúsculas ni espacios de los extremos. Además, a diferencia del original: las propuestas no se repiten entre sí y se descartan las que la IA asigna a otra persona (WeeklySync las habría creado para ella).
- **Nunca se crean solas** (cambia el original, que las insertaba sin preguntar y sin proyecto): se guardan como **propuestas** en `task_suggestion_batches` (la última tanda de cada persona; la siguiente la sustituye) con el proyecto y la bolsa **sugeridos por el cliente** (el único proyecto del cliente en el que puedo crear; si hay varios, aquel en el que imputé más recientemente). La persona revisa cada una (título, cliente, proyecto, bolsa, prioridad y entrega), marca las que quiere y se crean con `TaskWriter`, **todas o ninguna**, en proyectos en los que puede crear tareas; las demás siguen ahí o se descartan.
- **Seguimiento:** la página recarga la tanda cada 3 s mientras se genera (sin Reverb, como D-194); en cola o generando más de 12 minutos, atascada y se puede volver a pedir.
- **RGPD:** las propuestas sin crear y el archivado personal entran en la exportación de datos (`mi-espacio-tareas`). Se borran con la persona.

### D-205 · El contexto del asistente: solo lo que ve quien pregunta **[concreta D-146]**
- **Recortes de `query-knowledge-base`:** las 6 últimas semanas, los 20 últimos reportes (se envían 15, con 450 caracteres de texto general y 220 por cliente), 40 tareas (se envían 30) y 80 filas de estado de proyectos. El prompt y sus instrucciones son los del original.
- **Construido por persona** (`AssistantContext`), nunca con un cliente administrador como el original:
  - semanas, reportes **enviados** (nunca un borrador ajeno) y el último informe cerrado: lo ve toda la plantilla; con el módulo de la Weekly encendido,
  - clientes activos con su responsable (D-195) y su satisfacción (`ClientPolicy::viewAny`), y el equipo que escribe la weekly (como `/equipo`),
  - tareas abiertas que puede ver (`Task::visibleTo`), primero las suyas, sin las que ha archivado,
  - estado de proyectos en horas, sin importes (D-151), con el módulo `project_status`,
  - **horas** de los últimos 28 días solo de quien puede ver (`canSeeHoursOf`, D-021): las suyas; un responsable, también las de su equipo; el admin, todas,
  - **importes de venta** (bolsas y proyectos) solo con `view-financials`.
  - **Nunca:** los costes por hora de las personas, los resúmenes con IA de una persona (D-147), las ausencias ni nada de otra persona que no pueda ver.
- **Añadidos al prompt:** quién pregunta, el último informe, el equipo, las horas, los importes si los ve, la conversación anterior y una instrucción para que diga que no tiene lo que no está en el contexto. La página explica qué entra para cada persona.

### D-206 · Preguntar en la cola `ai` y la conversación de la sesión
- **Cada pregunta** (2.000 caracteres como mucho, 20 por minuto) es un Job `AnswerAssistantQuestion` en la cola `ai` (D-146: nunca dentro de una petición web). La pregunta y su respuesta viven **una hora en la caché** y solo las ve quien pregunta. La respuesta llega por el evento `assistant.answered` del canal privado de quien pregunta (sin el texto) y, siempre, por un sondeo de respaldo (cada 2 s sin Reverb, cada 10 s con él). A los 5 minutos se deja de esperar (la cola `ai` puede estar ocupada con un informe).
- **La conversación es de la sesión**, como en WeeklySync: la guarda el navegador (`sessionStorage`), así que sobrevive a recargar la pestaña y desaparece al cerrarla; el servidor no guarda historial. Con cada pregunta se mandan los 6 últimos turnos (sin los errores), recortados a 600 caracteres, para entender «¿y la semana pasada?».
- **Avisos de que es IA:** el de WeeklySync bajo la caja («La IA puede cometer errores…») y, en las tareas sugeridas, que son propuestas que hay que revisar.
- **F-006:** el botón flotante «Asistente AI» pasa a una entrada **«Asistente IA» de la barra lateral**, tras Chat, para quien usa la Weekly con el módulo `assistant` encendido. Las preguntas sugeridas son las del original con nombres de Audax (el cliente con el último reporte, el departamento de quien pregunta y la última persona que ha enviado su weekly).

## 05/10/2026: Centro de ayuda y sugerencias (entrega 10.7)
Detalle de las clases, rutas y props en `docs/PLAN-FASE-10.md` («10.7 (hecho)»).

### D-207 · Los vídeos de los tutoriales: subida por trozos y reproducción con Range
- **El límite:** WeeklySync admitía vídeos de 200 MB (Supabase Storage). El PHP del dominio acepta 55 MB por fichero y 64 MB por petición (RUNBOOK-DESPLIEGUE, paso 3) y `max_execution_time` es 60 s. Subir 200 MB de una vez exigiría tocar PHP, nginx y ModSecurity del servidor compartido. **No se toca el servidor:** el navegador sube el vídeo **por trozos de 8 MB** (`TutorialVideoUploads`), cada uno en su petición.
- **Cómo:** se reserva la subida (nombre y tamaño, como mucho 200 MB) y se mandan los trozos en orden con su posición; el servidor dice cuántos bytes tiene, así que un trozo repetido tras un corte no se duplica (el navegador lo reintenta hasta 3 veces) y uno desordenado se rechaza. Al guardar el tutorial se comprueba que está completo y que es un vídeo de verdad (fileinfo: MP4, WebM, MOV, OGG o M4V), se mueve a `help/tutorials` del disco privado y queda como su `Attachment`; el anterior se borra al sustituirlo. Solo continúa una subida quien la empezó (`manage-help`).
- **Lo abandonado** (pestaña cerrada, corte) se borra a las 24 h: `help:prune-uploads`, cada noche a las 03:35.
- **Reproducción:** `help.tutorials.video` con URL firmada (relativa, 6 h) y `BinaryFileResponse`, que atiende `Range` (206): el navegador reproduce a trozos y salta a cualquier punto sin descargarlo entero, como los audios del chat. Lo ve la plantilla (`use-weeklies`).
- **Disco:** los vídeos de WeeklySync suman 135 MB (4); el servidor tiene 47 GB libres. Entran en la copia nocturna con el resto del disco privado (D-029).

### D-208 · El centro de ayuda: novedades, manual, tutoriales y preguntas frecuentes **[concreta D-147 y D-151]**
- **Una página, `/ayuda`, con cuatro pestañas en la URL** (F-148); cada una trae solo sus datos y las demás llegan a null sin consultas. «Ayuda» va en la barra lateral, tras el asistente (F-010), con el módulo `help`.
- **Novedades automáticas** (F-150): al abrir General o Tutoriales se crea, si no existe, la versión de esta semana (`V.serie.mes.semana`, la serie es el año desde 2026) con el resumen por defecto de WeeklySync; una sola inserción que no pisa nada. Su fecha es el día `(semana − 1) · 7 + 5` del mes y está **en curso** mientras es la de esta semana, no ha llegado su fecha y no tiene resumen propio ni cambios. En el listado, la más reciente que no está en curso es la **nueva**; las demás, **anteriores** (port de `helpCenter.ts`, `HelpReleaseCalendar`).
- **Los cambios de una versión** se editan en su diálogo, en orden (subir, bajar, añadir y quitar): WeeklySync tenía las funciones pero no la pantalla. «Eliminar versión» la **oculta**, como en el original; quien gestiona la puede volver a mostrar. El texto de la versión cambia a «V.1.10.2», el del original.
- **Actualizaciones a mano** (F-151) con contenido en el editor de Audax (RichText saneado en el servidor). WeeklySync admitía imágenes incrustadas en base64; el editor de Audax no (D-037: los ficheros, como adjuntos).
- **Manual y soporte** (F-157): el PDF va al disco privado (`help_manual`) con URL firmada; el enlace de soporte, en `help_support_url`. WeeklySync tenía los dos campos sin pantalla; aquí se ven en General y los cambia quien gestiona.
- **Preguntas frecuentes** (F-156): una sección con preguntas no se borra (antes se mueven o se borran). Además del original, un **buscador** en las preguntas y respuestas de todas las secciones. Las respuestas, con el editor de Audax.
- **Reordenar** (tutoriales, secciones y preguntas, tableros y categorías): arrastrar con ratón, dedo o teclado, o «Subir» y «Bajar». Se manda la lista entera; si alguien ha cambiado algo mientras tanto, se pide recargar.
- **Gestiona** `manage-help` (admin y responsables, D-147); los «me gusta» y ver los vídeos, toda la plantilla; nunca un colaborador externo.

### D-209 · Los avisos de las sugerencias
- **Grupo nuevo «Sugerencias»** del catálogo (D-073), tras «Weekly», para quien usa la ayuda con los módulos `help` y `suggestions` encendidos (audiencia `suggestions`):
  - `suggestions.status_changed`: cambia el estado de **tu** sugerencia (con la nota oficial); por defecto, en la app,
  - `suggestions.replied`: comentan tu sugerencia o responden a tu comentario; por defecto, en la app,
  - `suggestions.mentioned`: te mencionan en una sugerencia o un comentario; por defecto, en la app y en el navegador (como las menciones de tareas y del chat).
- **Nunca a quien lo hace**, y una sola vez por persona: a quien se menciona y además se responde le llega solo la mención. Al editar, solo avisa a los mencionados nuevos. Solo a la plantilla activa que usa la Weekly (un colaborador externo o alguien desactivado no recibe nada aunque se le mencione).
- WeeklySync no avisaba de nada de esto; los avisos los pide el propietario para esta entrega.

### D-210 · Sugerencias: roadmap, votos, comentarios y búsqueda
- **Port de `helpSuggestions.ts`:** tableros y categorías activos, slug único por tablero, estado inicial `open`, contadores `vote_count` y `comment_count` **recontados** (nunca sumados) y `last_activity_at` al comentar o cambiar el estado. Órdenes del feed: Trending (comentarios, votos, actividad y fecha), Top (votos y fecha), Nuevas (fecha) o un estado del roadmap (actividad). Con texto, la **búsqueda es global**: ignora tablero y categoría y busca en el título, el detalle, el slug y el nombre de los tableros y las categorías.
- **El roadmap se ordena en el servidor** (`position` por columna). En WeeklySync el orden de las tarjetas y de las columnas vivía en el `localStorage` de cada navegador: aquí el orden de las tarjetas es el mismo para todos y lo decide quien gestiona; las columnas van en el orden del ciclo de vida. Arrastrar a otra columna **cambia el estado** (queda en el historial y avisa a quien la propuso); un cambio de estado desde el detalle la pone al final de su columna. También con el menú «Mover a…» de cada tarjeta (teclado y lector de pantalla).
- **Comentarios:** respuestas anidadas sin límite (se dibujan con sangría hasta 4 niveles), editar solo su autor (queda «editado»), eliminar su autor o quien gestiona, con todas sus respuestas y sus adjuntos. Un comentario puede ir solo con adjuntos. **Reacciones:** una por persona y comentario (la misma la quita, otra la cambia).
- **Adjuntos** de sugerencias y comentarios: los `Attachment` de Audax con sus reglas (tipo real, extensión, 10 por subida y `max_attachment_mb`, D-037), en `attachments/suggestions/…`, servidos por `attachments.show` con URL firmada a quien puede ver la sugerencia y con el módulo encendido. Se quitan desde la sugerencia o el comentario, nunca sueltos. Se pueden **pegar** en el texto.
- **«Reportar un bug»** (F-149): `?nueva=bug` abre el formulario fijado en la categoría con slug `bugs`. Esa categoría se puede renombrar u ocultar, pero no eliminar ni cambiar su slug. Un tablero con sugerencias no se elimina: se oculta (sus sugerencias dejan de salir en el feed y el roadmap; quien gestiona aún puede abrirlas).

### D-211 · RGPD, retención y auditoría de la ayuda y las sugerencias **[concreta D-202]**
- **Exportación de datos personales** (D-075): sus sugerencias (`sugerencias`), sus comentarios (`sugerencias-comentarios`), sus votos y reacciones (`sugerencias-votos`) y sus «me gusta» a las novedades (`ayuda-me-gusta`). Los adjuntos van por su nombre.
- **Retención:** no caducan. Las sugerencias y la ayuda son el histórico del producto, como las weeklies; sus autores se desactivan, no se borran (las FK de autoría son `restrict`). Lo único temporal, las subidas a medias de vídeos, se borra a las 24 h (D-207).
- **Auditoría** (D-074): entidades nuevas «Centro de ayuda» (versiones, actualizaciones, tutoriales, secciones, preguntas y los cambios del manual y del soporte) y «Sugerencias» (tableros, categorías y sugerencias, sin los contadores ni el orden del roadmap), y acciones «Cambios en el manual y el enlace de soporte de la ayuda» y «Cambios de estado de las sugerencias». Los comentarios, los votos y las reacciones no se auditan (como los comentarios de las tareas).
- El texto RGPD pendiente del asesor (Fase 7) debe mencionar las sugerencias y sus comentarios.

### D-212 · Tiempo real de la ayuda **[concreta D-184]**
- WeeklySync escuchaba los cambios de las tablas de la ayuda con Supabase Realtime (F-170). Aquí, cada cambio emite `help.changed` por el canal privado **`help`** (quien usa la ayuda), sin contenido: solo si es de la ayuda o de las sugerencias (y qué sugerencia). La página abierta recarga **solo las props de su pestaña** (Inertia `only`), sin perder lo que se está escribiendo; varios cambios seguidos, una recarga.
- Sin Reverb, la página se pone al día al volver a ella o al recuperar la conexión (D-184).

## 07/10/2026: Migración de los datos de WeeklySync (entrega 10.8)
Concretan D-149. Procedimiento exacto en `PLAN-FASE-10.md` (10.8).

### D-213 · Volcado en el Mac, importación en el servidor **[concreta D-149]**
- **Dos pasos** para que las credenciales de Supabase nunca lleguen al servidor ni a Git:
  1. `php artisan app:dump-weeklysync <carpeta>` en el Mac del propietario lee la base en **solo lectura** y descarga del Storage los ficheros que usan sus filas,
  2. `php artisan app:import-weeklysync <carpeta>` en el servidor lee solo esa carpeta.
- **Credenciales:** un fichero local fuera de Git (`~/.config/audax/weeklysync.env`, **600**; el comando se niega si otras cuentas pueden leerlo) con `WEEKLYSYNC_DB_URL` (la del «Session pooler», sin contraseña), `WEEKLYSYNC_DB_PASSWORD`, `WEEKLYSYNC_URL` y `WEEKLYSYNC_SERVICE_KEY`. Nunca se imprimen: ni en la salida, ni en los errores (solo el SQLSTATE o el código HTTP), ni en un `dump()` (`__debugInfo`). Se crean sin que aparezcan en pantalla (`stty -echo`).
- **Solo lectura de verdad:**
  - la base, en una transacción `READ ONLY` y `REPEATABLE READ` (PostgreSQL rechaza cualquier escritura y todas las tablas salen de la misma foto), con TLS obligatorio y solo `SELECT to_jsonb(t)` de una lista fija de tablas,
  - el Storage, solo con `GET` (`/storage/v1/object/<bucket>/<ruta>`), reintentando cortes, 5xx y 429.
- **Formato** (`WeeklySyncDump`): `manifest.json` (formato, fecha, filas y sha256 de cada tabla y de cada fichero, y los que faltan), `tables/<tabla>.json` (una fila por línea) y `storage/<bucket>/<ruta>`. Carpeta nueva **700** y ficheros **600**. El importador comprueba todos los sha256 **antes de tocar nada**: un volcado incompleto o tocado no se importa.
- **Solo los ficheros a los que apunta alguna fila:** los audios del informe (completo y por secciones), el manual, los vídeos de los tutoriales y los adjuntos de las sugerencias. Los MP3 de regeneraciones anteriores que quedaron huérfanos en el bucket (la mayoría de los 993) no se copian.
- **Tablas** (32): personas e identidades, clientes, la foto **actual** del estado de proyectos (solo para casar clientes), semanas, envíos y apuntes, borradores, tareas, avisos, ayuda, sugerencias y uso de IA.

### D-214 · Cómo se casan personas y clientes **[concreta D-149 y WEEKLY-INVENTARIO D.3]**
- **Personas**, en este orden:
  1. el fichero de personas (`email`, `import: false` o `create: true`),
  2. `import_refs` de una pasada anterior,
  3. cualquiera de sus correos (el suyo, el canónico y los de sus identidades de Google) contra el correo de una cuenta de Audax,
  4. si no, una cuenta **inactiva** (empleado, contraseña aleatoria que nadie conoce, sin invitación), solo si escribió algo o contaba para la weekly. Las cuentas «PENDING» sin nada escrito no se crean.
  - No se cambian el rol, el departamento ni el estado de las cuentas que ya existen (mandan los de Audax); solo se rellena el puesto (`job_title`) si está vacío.
- **Clientes**, en este orden:
  1. el fichero de clientes (`client`, `client_id` o `create: true`),
  2. `import_refs`,
  3. el nombre normalizado (sin tildes, emoji, signos ni la forma societaria), si es único,
  4. la foto actual del estado de proyectos contra lo importado de ClickUp: primero el código F de factura (`hour_banks.invoice_reference`) y después los códigos de proyecto («FE1» contra «CLIENTE-FE1»), siempre que el nombre se parezca. Sale como aviso para revisarlo,
  5. si no, un cliente **inactivo** sin proyectos.
  - El icono se copia si el de Audax está vacío.
- **El fichero manda sobre `import_refs`:** si se corrige una correspondencia, la siguiente pasada mueve lo importado (los apuntes cambian de cliente). Lo creado inactivo en la pasada anterior se queda, sin datos.
- **Idempotencia:** `import_refs` con la fuente `weeklysync`. Repetir no duplica nada; la segunda pasada sobre el mismo volcado no crea ni cambia nada (probado).

### D-215 · Semanas, informe, audio, exenciones y satisfacción **[concreta D-149 y D-151]**
- **Semana:** casa por `import_refs` o por la **fecha de inicio** (Audax puede haber abierto ya la semana en curso). Número y etiqueta, los de Audax (`WeeklyCalendar`); fechas y plazo, los de WeeklySync. Si su número choca con otra semana de Audax, no se importa (aviso).
- **Activa:** la semana activa de WeeklySync entra activa, salvo que Audax ya tenga otra activa: entonces entra cerrada, con aviso.
- **Informe:** `structured_report` con `WeeklyReport::fromWeeklySync()`: los `clientId` se reescriben a los de Audax (por id o, si no lo trae, por nombre); un cliente que ya no existe queda sin enlace. Estados «On Track», «Risk» y «Blocked» → `on_track`, `risk` y `blocked`. El texto, en `report_text`. Al comparar se ignora el orden de las claves (jsonb).
- **Quién debía enviar (`expected_user_ids`)**, en las cerradas: **se reconstruye** con la regla de WeeklySync (`isUserEligibleForWeek`): sus cuentas no pendientes que se unieron antes del final del plazo, menos las excusadas. Es una aproximación: WeeklySync no guardaba esa foto, así que una persona que ya no está en su tabla de usuarios no cuenta.
- **Exenciones:** `excused_user_ids` → motivo «ausencia», sin ausencia enlazada y con la nota «Importada de WeeklySync».
- **Cerrada:** `closed_at` es el último cambio de la semana en WeeklySync; `closed_by`, vacío.
- **Audio:** el completo y cada sección, copiados a `weeklies/{id}/audio/` con **nombre fijo** (`weekly-weeklysync.mp3`, `<clave>-weeklysync.mp3`): repetir no duplica ficheros y no se vuelve a copiar lo que ya está igual (tamaño y sha256). La clave de la sección de un cliente pasa a `client-<id de Audax>`. Una sección sin fichero se queda con su guion.
- **Satisfacción:** la de cada cliente al cerrar cada semana (`satisfactionScore` del informe) → `client_satisfaction_snapshots` (regla `weeklysync_import`, delta con la anterior); la actual → `clients.satisfaction_score`. No se tocan los clientes en los que Audax ya calcula la suya.

### D-216 · Envíos, borradores y tareas
- **Envíos:** el texto general es el apunte «General / Interno» y cada `client_report_entries`, un apunte por cliente. Los apuntes vacíos no entran.
- **Dos de WeeklySync en uno de Audax:** si dos personas o dos clientes de WeeklySync son el mismo en Audax, sus apuntes se unen (separados por una línea en blanco) en el mismo envío o apunte.
- **Borradores:** un envío con `submitted_at` vacío; no entran si esa persona ya envió esa semana.
- **Lo escrito en Audax nunca se toca:** un envío, una exención o una satisfacción que no viene de la importación se conserva, y lo de WeeklySync para esa semana y persona se omite (sale en el informe).
- **Tareas:** solo las de un cliente con un proyecto claro en Audax (uno solo, o uno solo sin archivar ni terminar), hechas y con su archivado personal (`task_archives`) para quien la tenía asignada. El resto se lista en los avisos del informe.

### D-217 · Avisos: reglas, plantillas y registro **[concreta D-199 a D-201]**
- **Reglas:** `email_reminders` (correo) y `web_notification_reminders` (navegador), con el día de 0 = domingo a ISO (7). Una regla igual que ya esté en Audax (canal, día y hora) no se duplica ni se cambia: dos reglas iguales mandarían dos avisos.
- **Plantillas:** solo las que el propietario cambió en WeeklySync (las que difieren de sus textos de serie) y solo si en Audax siguen con el texto de serie. «WeeklySync» pasa a «Audax Proyectos». Las variables son las mismas.
- **Registro:** `email_log` → `weekly_reminder_logs` (canal correo, su semana por id o por número, la persona por su correo) con una clave propia, `ws:<id>:<clave original>`, que no choca con la deduplicación de Audax.

### D-218 · Centro de ayuda **[concreta D-208]**
- Todo: manual y soporte, versiones con sus cambios, actualizaciones puntuales, «me gusta», tutoriales con su vídeo y preguntas frecuentes por secciones.
- **No se pisa lo de Audax:** el enlace de soporte y el manual solo si en Audax están vacíos (o son los importados); el resumen de una versión que Audax ya escribió; el vídeo de un tutorial subido aquí.
- **Formato:** el Markdown (o HTML) de las actualizaciones y las respuestas pasa a HTML saneado con `RichText`. Una pregunta sin sección va a la sección «General».
- **Vídeos:** un `Attachment` en `help/tutorials/weeklysync-<id>.<ext>`, como los subidos en Audax; si el fichero no es un vídeo que se pueda reproducir, no se adjunta (aviso).

### D-219 · Sugerencias **[concreta D-210]**
- El tablero de WeeklySync que tiene la categoría «bugs» es el precargado de Audax («Sugerencias»), para que «Reportar un bug» siga teniéndolo todo junto; los demás, por su `slug` o nuevos. Los tableros y categorías de Audax solo se usan, nunca se cambian.
- Propuestas, votos, comentarios con su árbol, reacciones, cambios de estado y adjuntos (los de un tipo que Audax no admite se omiten).
- Las menciones `@[Nombre](user:uuid)` pasan a menciones de Audax con su id (o al nombre en texto si la persona se queda fuera).
- Al final se recuentan votos y comentarios, y el roadmap de lo importado se ordena por la última actividad, detrás de lo que ya había en Audax en cada estado.

### D-220 · Uso de IA y lo que no se migra **[concreta D-149]**
- **Uso de IA:** `ai_usage_events` → `ai_usage`, cada función de WeeklySync en la de Audax que la sustituye (informe, satisfacción, guion, locución, limpieza del dictado, tareas sugeridas, resúmenes y asistente). Lo del OCR del estado de proyectos y de las bolsas (D-148) no entra; su coste sale en el informe.
- **No se migran:** las bolsas (`hour_banks`) y la foto del estado de proyectos (`project_status_*`, D-148), los responsables de cliente (`owner_id`; los equipos, `client_team_members`, sí desde D-221) ni las insignias (`client_projects`), que salen de los proyectos de Audax (WEEKLY-INVENTARIO D.1), los roles, departamentos, estados y avatares de las personas, la consola multi-tenant (módulos y aviso global incluidos: se configuran en Audax) y `user_merge_audit` (vacía).
- **Informe** (`WeeklySyncImportReport`): recuentos por tipo (creados, actualizados, sin cambios y omitidos, por fila de origen), cada tabla frente al manifiesto (volcado, leídas, importadas y omitidas, y si cuadra), ficheros copiados, iguales y que faltan, motivos de lo omitido, avisos, tiempo y memoria. Una sola entrada de auditoría, «Importación de WeeklySync».

### D-221 · «Unirme» en la Weekly es una suscripción, no una membresía de proyecto **[sustituye a D-156; concreta D-149]**
Decisión del propietario por defecto tras la revisión de seguridad de la Fase 10 (hallazgo 1, alta): con D-156 cualquiera se hacía miembro de cualquier proyecto y ganaba el chat (con todo su histórico), imputar en la bolsa del cliente y editar tareas.
- **Qué es:** «Unirme a clientes» crea una fila en `weekly_client_subscriptions` (cliente y persona, única). Es el `client_team_members` de WeeklySync. Se une cualquier interno de plantilla a clientes **activos** (`use-weeklies`; los colaboradores externos no) y lo deja cuando quiere.
- **Para qué sirve, y nada más:** el cliente sale **propuesto en «Mi weekly»**, en **«Mis clientes»** («Colaboras en», con «Dejar el cliente») y en los clientes de su ficha de persona, y la persona sale en el **equipo del cliente** de la Weekly (rol «miembro», sin proyectos) y en el filtro por cliente de «Equipo».
- **Qué NO da:** ni el chat del proyecto, ni imputar horas, ni crear o editar tareas, ni bolsas. Todo eso sigue en `project_members`, que la Weekly **ya no toca**: ni «Unirme» ni «Dejar». Las rutas `weeklies.projects.*` desaparecen (`/mi-espacio/proyectos` da 404) y las nuevas son `POST /mi-espacio/clientes` (`client_ids[]`) y `DELETE /mi-espacio/clientes/{client}` (`weeklies.clients.join` y `.leave`).
- **Interfaz:** en `/weeklies`, «Unirme a clientes» (lista de clientes activos que aún no son míos, que se busca también por el código o el nombre de sus proyectos abiertos, prop opcional `joinable_clients`). En la pestaña «Equipo» de la ficha de cliente, «Unirme a este cliente» o «Dejar este cliente» (`weekly.subscription`), con el aviso de que no da acceso a los proyectos; «Mis proyectos en este cliente» solo enlaza.
- **Importación (D-149):** `client_team_members` del original → `weekly_client_subscriptions` (`ClientTeamStage`, idempotente; se omiten las filas con persona o cliente sin casar). Nunca crea miembros de proyecto.
- **Sin auditoría:** es una preferencia personal de la Weekly, como un «seguir».

### D-222 · Coste de Gemini y la cola `ai`: límites, Jobs únicos y prioridad del informe
Hallazgo 2 (media) de la revisión de seguridad: cualquiera podía llenar la cola `ai` de llamadas a Gemini y dejar el informe semanal y su audio horas en cola.
- **Límites diarios por persona** (`AiDailyLimits`, de 0:00 a 24:00 en Madrid, en la caché): asistente 60, resúmenes con IA 30 y tareas sugeridas 10, configurables en `services.gemini.daily_limits` (`AI_DAILY_LIMIT_ASSISTANT`, `AI_DAILY_LIMIT_SUMMARIES` y `AI_DAILY_LIMIT_SUGGESTED_TASKS`; 0 = sin límite). Solo gasta lo que de verdad se encola: pedir un resumen que ya se está generando no cuenta. El informe, su audio y la satisfacción del cierre no tienen límite (los pide quien gestiona).
- **Mensajes:** el asistente responde 422 con el motivo en la conversación; los resúmenes y las tareas sugeridas, 429 en JSON o un aviso de error en la página.
- **Una pregunta al asistente en curso por persona:** la siguiente espera a la respuesta («Espera a que termine la respuesta anterior…»), salvo que la anterior lleve más de lo que dura un Job (atascada).
- **Jobs únicos** (`ShouldBeUnique`, `AiQueue::UNIQUE_FOR` = 660 s, por debajo de los 12 minutos con los que un trabajo se da por atascado): informe y audio por semana, satisfacción por semana, resumen, pregunta, tanda de tareas sugeridas y dictado.
- **Prioridad sin procesos nuevos:** el informe, su audio y la satisfacción del cierre van a la cola **`ai-high`**; el resto, a `ai`. El mismo `supervisor-ai` (un proceso, 128 MB) atiende `['ai-high', 'ai']` **sin balanceo**, es decir, por orden: el informe nunca espera detrás de las preguntas en cola, como mucho a que acabe el Job en curso. No se añade un proceso porque el límite de memoria de `audax-horizon.service` (640 MB) está ajustado y cambiarlo sería tocar el servidor.
- **Límites de peticiones por acción** (hallazgo 3, media): `throttle:N,M` sin prefijo comparte una clave por persona entre todas las rutas, así que el sondeo (estado del informe, respuesta del asistente, autoguardado) agotaba el límite de «Generar», «Preguntar» o «Enviar». Todas las rutas con un límite numérico llevan ahora su nombre de ruta como prefijo (`throttle:20,1,assistant.ask`); solo comparten contador los grupos hechos a propósito (`home-layout`, chat, Google y tiempo real). Un test lo comprueba en todas las rutas.

### D-223 · Cuotas de las subidas de la ayuda y las sugerencias
Hallazgo 4 (baja): sin cuota, las subidas podían llenar el disco del servidor compartido con 38 webs (SPEC §16). En `config/help.php` (`HelpUploadQuota`):
- **Vídeos de tutoriales:** una subida abierta por persona (empezar otra descarta la anterior) y como mucho **1 GB** a medio subir entre todas (`HELP_TUTORIAL_PENDING_MB`).
- **Adjuntos de sugerencias y comentarios:** como mucho **250 MB** por persona (`HELP_SUGGESTION_USER_MB`); lo que quita al editar libera su cuota. Editar una sugerencia o un comentario tiene ya su límite de peticiones (30 y 60 por minuto).
- **Disco:** no se acepta ninguna subida (ni un trozo de vídeo) si dejaría menos de **5 GB** libres (`HELP_UPLOADS_MIN_FREE_MB`), medido con `DiskUsage`, como el aviso de almacenamiento (D-076). En los tests, 0.

### D-224 · Los resúmenes con IA no tienen enlaces activos
Hallazgo 5 (baja): un texto de una weekly podía colar un enlace de phishing en el resumen de un cliente o una persona, que se pinta con `SafeMarkdown`. Ahora `SafeMarkdown links={false}` (`withoutLinks` de `lib/markdown.ts`) deja cada enlace como su texto con el **dominio visible** entre paréntesis («Revisa el informe (phishing.example)»). Lo usa `AiSummaryPanel`; el texto de privacidad sigue con enlaces. El asistente ya pintaba texto plano.

### D-225 · El uso de la IA en el RGPD
Hallazgo 6 (baja).
- **Exportación:** sección `uso-ia` (`AiUsageSection`): las llamadas que pidió la persona y las que tratan sobre ella (los resúmenes de su desempeño o de su actividad), con fecha, función, operación, proveedor, modelo, estado y sobre qué. Nunca hay textos.
- **Retención:** `retention_ai_usage_months`, **12 meses** por defecto (mínimo 1, en `/admin/privacidad`). Pasado el plazo, `app:prune-data` **anonimiza** (`AiUsagePruner`: quita la persona, el objeto y los metadatos, que llevan `target_user_id`) y conserva el coste para «Uso de IA».
- Queda pendiente mencionarlo en el texto RGPD (con el propietario, al final).

### D-226 · Tableros ocultos de sugerencias
Hallazgo 7 (baja): en un tablero oculto (D-210) **nadie** vota, comenta ni reacciona (tampoco quien gestiona, que sí la ve para moderarla), y sus adjuntos solo los baja quien gestiona. Lo aplican `SuggestionPostPolicy` (`view`, `vote` y `comment`) y `SuggestionCommentPolicy::react`; `AttachmentPolicy` ya usaba `view`. La página lo dice («Este tablero está oculto…») y desactiva el voto (`post.can.interact`).

### D-227 · La limpieza del dictado, encendida por defecto **[cambia D-158, paso 5]**
Revisión de paridad 10.9b (F-172, P1): en WeeklySync el dictado de la weekly llegaba siempre limpio (sin muletillas y con los nombres de clientes y personas corregidos). Con la limpieza apagada, el equipo lo notaría en el primer dictado. El propietario ya autorizó en G1 mandar el texto a Gemini (D-146).
- **`weekly_dictation_cleanup` = true por defecto** (se sigue pudiendo apagar en `/admin/ajustes`).
- **Solo el dictado de la weekly** (`DictationCleaner::appliesTo`, contexto `weekly_entry`), como el modo `weekly-report` del original: las notas de las tareas quedan literales.
- **Si la IA no responde**, se queda el texto de Whisper con el aviso nuevo `cleanup_failed` («Se ha añadido la transcripción literal: la limpieza automática no se ha podido completar»), como avisaba WeeklySync. Si responde vacío, el texto literal sin aviso.

### D-228 · «Estoy fuera»: marcarse fuera con efecto inmediato **[cambia D-150 y D-159; concreta F-007, F-038 y F-106]**
Revisión de paridad 10.9b (P1): en WeeklySync una persona se marcaba «De vacaciones» o «Ausente / Baja» con fecha de vuelta desde su avatar (y quien gestionaba, desde el resumen) y quedaba exenta al momento. En Audax hacía falta una ausencia **aprobada**: mientras tanto seguía pendiente y le llegaban recordatorios.
- **Qué es:** un estado de la Weekly en la persona (`users.weekly_away_reason` = `vacation` | `absent`, `weekly_away_since` y `weekly_away_until`; migración `2026_10_06_110000`), sin aprobación. Solo toca la Weekly: no resta capacidad ni sale en el calendario ni sustituye a una ausencia.
- **Activo** si tiene motivo y la vuelta es hoy o después (o no tiene vuelta: hasta que se quite, como el original). Pasada la vuelta se apaga solo.
- **Exime** (motivo nuevo de exención `away`) de cada semana cuyo plazo cae entre el día en que se marca y la vuelta: al vuelo con la semana activa y congelado al cerrar, como la ausencia. Precedencia: exención manual, ausencia aprobada, «Estoy fuera». Se puede renunciar para escribir (D-159). **A diferencia del original**, no exime de una semana cuyo plazo ya había pasado al marcarse (evita «escaparse» de una weekly con retraso; para eso, la exención manual).
- **Recordatorios (F-106):** como WeeklySync (`status = 'AVAILABLE'` al enviar), no se recuerda a quien está fuera **ese día**, aunque vuelva antes del plazo: «Estoy fuera» activo o una ausencia aprobada de día completo que cubre el día (`WeeklyAway::awayOn`), salvo que haya renunciado a su exención para escribir. Corrige el texto de ayuda de «Avisos», que prometía más de lo que hacía.
- **Quién:** la propia persona (`use-weeklies`) y quien gestiona la Weekly a cualquier interno activo que la escribe. Rutas `PUT` y `DELETE /equipo/{user}/fuera` (`weeklies.away.update` y `.destroy`).
- **Dónde:** «Estoy fuera…» en el menú del avatar (con una insignia naranja o roja sobre el avatar mientras dura), en «Mi weekly» del resumen y de Inicio («¿Estás de vacaciones o de baja? Marcar que estoy fuera · Solicitar ausencia») y en la ficha de la persona. El diálogo de quien gestiona («Eximir») elige entre «Solo esta semana» (la exención manual de siempre, con nota) y «De vacaciones» o «Ausente o de baja» hasta una fecha (varias semanas), con enlace a «Ausencias del equipo». El equipo y la ficha enseñan «Fuera: de vacaciones hasta el…».
- **Y la ausencia de verdad:** la propia persona puede marcar «Solicitar también la ausencia» (desde hoy hasta la vuelta, tipo vacaciones u otro), que sigue su aprobación (`AbsenceService::request`). Siempre hay enlace a «Solicitar ausencia» (`/ausencias?solicitar=1`).
- **Importación (D-149):** el `status` VACATION/ABSENT de WeeklySync con la vuelta pendiente pasa a «Estoy fuera» (salvo que la cuenta de Audax ya tenga uno), con un aviso en el informe.
- **RGPD:** es un dato de la persona como el puesto; no dice el tipo de ausencia (una baja sigue siendo solo «Ausente o de baja», D-088).

### D-229 · La Weekly en tiempo real **[cambia D-184 para la Weekly; concreta F-015 y F-088]**
Revisión de paridad 10.9b (P2): en WeeklySync el resumen de quien gestiona, la tira del equipo, «Hay nuevos reportes», los reportes originales y el aviso de cierre cambiaban en menos de un segundo cuando alguien enviaba (Supabase Realtime, `ws:App.tsx:922-980`). En Audax había que recargar.
- **Evento** `weekly.changed` (`WeeklyChanged`) por el canal privado **`weeklies`** (quien usa la Weekly, `use-weeklies`), con solo el id de la semana y el motivo (`submission`, `exemption`, `cycle`, `report`, `satisfaction`); la página vuelve a pedir sus datos con sus permisos. Sale tras la transacción y, sin Reverb, no rompe nada (`ShouldRescue`), como `help.changed` (D-212).
- **Cuándo** (observadores en `WeekliesServiceProvider`): un envío o reenvío (no el autoguardado de un borrador), una exención (poner, renunciar o quitar), una ausencia y un «Estoy fuera» (D-228) que eximen al vuelo, abrir, cambiar el plazo, cerrar o borrar una semana, el informe o el audio terminados (no cada paso, que ya va por `weeklies.{cycle}`), una edición a mano y la satisfacción calculada tras el cierre.
- **Quién escucha** (`useWeeklyLive`, juntando los cambios de 400 ms en una recarga): `/weeklies` (resumen e histórico, cualquier semana) y el informe `/weeklies/{ciclo}` (solo su semana: el equipo, «Hay nuevos reportes», los reportes originales, el aviso de cierre y lo que se puede hacer). «Mi weekly» no se recarga sola para no pisar lo que se escribe (el autoguardado ya avisa si la semana se ha cerrado). Al recuperar la conexión también se recarga.
- **Silenciado** en la importación (ya va sin eventos de modelo) y en los datos de ejemplo (`WeeklyChanged::muted`).

### D-230 · Enviar la weekly sin apuntes y pedir los avisos del navegador **[cambia la regla 7 del contrato 10.1; concreta F-052 y F-103]**
Revisión de paridad 10.9b (P2).
- **Weekly vacía:** WeeklySync dejaba enviarla (una semana sin trabajo de cliente quedaba como hecha, `ws:App.tsx:2062-2089`) y Audax la rechazaba («Escribe algo en al menos un cliente»). Ahora se puede: el envío queda con 0 apuntes, cuenta como enviado (a tiempo o con retraso) y en el informe no aporta nada. La interfaz pide confirmarlo («¿Enviar la weekly sin apuntes?») para que no se envíe vacía sin querer.
- **Avisos del navegador:** WeeklySync pedía el permiso solo, a los 2 s del primer acceso. Audax no lo pide nunca sin un gesto (los navegadores castigan pedirlo de entrada y un «Bloquear» no tiene vuelta atrás fácil). En su lugar, un aviso amable «¿Te avisamos en el navegador?» con «Activar avisos» y «Ahora no» en «Mi weekly» tras enviar y en el resumen de `/weeklies`, solo si el navegador los admite, el servidor tiene Web Push y aún no se ha dado ni negado el permiso. «Ahora no» se recuerda en ese navegador. Sigue el interruptor de `/ajustes/notificaciones`.

### D-231 · Tareas de Mi espacio: menos pasos y más claras **[concreta D-203 y D-204]**
Revisión de paridad 10.9b (P2).
- **«Generar tareas con IA» (F-062):** sigue siendo revisar antes de crear (D-204), pero al terminar la tanda sale un aviso («La IA ha propuesto N tareas: revísalas y crea las marcadas con un clic») y la revisión se pone a la vista con el foco en su título. Las propuestas con proyecto ya vienen marcadas, así que crearlas es un clic.
- **Buscador** en los selectores de cliente y de proyecto a partir de 9 opciones (como el `searchable={clients.length > 8}` del original), también en la revisión de las propuestas.
- **Clientes de «Nueva tarea» (F-058):** no se abren todos los clientes como en el original porque una tarea de Audax vive en un proyecto y crearla exige ser miembro o gestor (D-021, D-221). En su lugar, el diálogo explica que solo salen los clientes con proyectos abiertos en los que se participa y que, si falta alguno, se pide a quien lo gestiona; con enlace a la lista de clientes, que ya enseña el responsable de cada uno (D-232).
- **Avisos** al crear una tarea, al marcarla hecha o pendiente y si falla, como los de WeeklySync.
- **Notas (F-060):** debajo del campo, «Es la descripción de la tarea: la ve el equipo del proyecto», para que nadie la tome por privada.
- **Eliminar con horas (F-061):** el botón ya no desaparece: queda desactivado con «Tiene horas registradas: no se puede eliminar, archívala» (`has_time`).
- **Autocompletar (F-048):** cada tarea lleva sus notas en una línea («- Nota: …», hasta 300 caracteres), como el original.

### D-232 · Responsable del cliente elegible y equipo en la cartera **[cambia D-195; concreta F-123, F-126 y F-128]**
Revisión de paridad 10.9b (P2): en WeeklySync el responsable del cliente se elegía en un desplegable y la lista de clientes enseñaba en cada fila el responsable y los avatares del equipo («quién lleva qué» de un vistazo). En Audax se deducía y solo salía en la ficha.
- **Elegir el responsable:** columna nueva `clients.owner_user_id` (migración `2026_10_06_120000`), en el diálogo del cliente («Responsable», con la Weekly encendida) entre la plantilla activa que escribe la weekly. Vacío = «Automático: quien gestiona más proyectos abiertos» (la regla de D-195). Si el elegido se da de baja, vuelve a mandar la regla. Lo usan la ficha, la pestaña Equipo (que lo pone primero aunque no esté en ningún proyecto), el resumen con IA, el asistente y la cartera (`ClientInsights::ownerOf`). No da acceso a ningún proyecto.
- **Cartera (`/clientes`):** columna «Responsable y equipo» con el responsable (enlace a su ficha) y hasta tres avatares del equipo (mismas reglas que la pestaña Equipo: gestores y miembros de proyectos abiertos y quienes se han unido en la Weekly) con «+N». `ClientPortfolioTeams`: tres consultas fijas por página (presupuesto de `clients.index` de 8 a 10).
- **Satisfacción** con color por tramo en el icono (≥ 80 verde, ≥ 50 ámbar, < 50 rojo), como WeeklySync; la cifra sigue en texto normal (contraste AA).

### D-233 · Personas: asignar clientes, constancia e históricos completos **[cambia D-198; concreta F-028, F-130, F-131, F-141 a F-143]**
Revisión de paridad 10.9b (P2).
- **Asignar clientes a una persona** (F-141, el «Asignar (n)» de WeeklySync): en su ficha, quien gestiona la Weekly elige varios clientes activos de golpe (con buscador, prop opcional `assignable_clients`) y la persona queda unida a ellos en la Weekly (`POST /equipo/{user}/clientes`, `team.clients.assign`). Es la suscripción de D-221: sale en el equipo del cliente y se le proponen en «Mi weekly», **sin** acceso a los proyectos (para eso, miembro en cada proyecto, como dice el diálogo). Se le quita con «Quitar» en sus clientes (`DELETE /equipo/{user}/clientes/{client}`).
- **«Constancia (últimas 12 semanas)»** (`ws:ProfileView.tsx` y `ws:TeamView.tsx`): una casilla por semana desde el alta (verde a tiempo, naranja con retraso, rojo sin enviar, gris exenta o pendiente), con su texto y enlace a la semana, en el perfil (`/ajustes/perfil`, con las estadísticas) y en la ficha de persona. `WeeklyStreaks::overview` da la racha y el mapa con las mismas consultas.
- **«Ver histórico» por cliente** en la ficha de persona (F-143) y en la pestaña Equipo del cliente (F-131): todos sus apuntes enviados sobre el cliente, de 25 en 25 con «Ver más reportes» (`GET /equipo/{user}/clientes/{client|general}/historial`, JSON). Sustituye al tope de 20 reportes.
- **Históricos sin cortar** (D-198 los dejaba en 52 y 26 semanas): la ficha de persona y el historial del cliente van **por páginas** (un año y medio año por página, `?historial=2, 3…`, con «Semanas anteriores» y «Semanas más recientes»). El último reporte por cliente y los hábitos siguen saliendo del último año.
- **Filtros de `/equipo`** (F-134): se guardan en la pestaña (`sessionStorage`) y se conservan al volver de una ficha, como en WeeklySync, donde la lista seguía montada.

### D-234 · Foto de perfil, rol a la vista y pantalla de error del navegador **[concreta F-008, F-017, F-027 y F-029]**
Revisión de paridad 10.9b (P2).
- **Foto de perfil (F-029):** el inventario la daba por hecha, pero no había forma de subirla. En `/ajustes/perfil`, «Subir foto»: se elige una imagen, se encuadra en un recorte redondo (arrastrar o flechas y zoom) y se sube reducida a 512 px (`POST /ajustes/perfil/foto`, como mucho 5 MB). El servidor no se fía: la decodifica con GD, la recorta al cuadrado centrado, la gira según el EXIF, la deja en **256 px en WebP** y así **quita los metadatos** (ubicación, móvil). Se guarda en el disco privado (`avatars/`) y solo se sirve con una **URL firmada y relativa** (`/avatares/{id}`, `avatars.show`), que dura hasta el final del día siguiente y cambia con la foto (caché del navegador). «Quitar foto» la borra y vuelven las iniciales. Cambiarla borra la anterior.
- **RGPD:** la foto es un dato personal: va en la exportación (`foto-perfil.webp`, y «Foto de perfil» en `perfil`) y la quita la propia persona cuando quiere. Se conserva al desactivar la cuenta (como el resto de su historial). Queda pendiente mencionarla en el texto RGPD (con el propietario, al final).
- **Rol a la vista (F-008):** el pie de la barra lateral enseña el rol bajo el nombre (Administración, Responsable de departamento, Empleado…), como WeeklySync.
- **Departamento (F-027):** WeeklySync dejaba cambiarse el departamento a uno mismo. En Audax **no**: el departamento decide quién aprueba las horas y las ausencias (D-020 y D-049) y la capacidad, así que cambiarlo uno mismo dejaría elegirse el aprobador. El perfil lo enseña de solo lectura, con el rol, y explica que los cambia la administración.
- **Pantalla de error del navegador (F-017):** si una página de React falla al pintarse, en vez de quedarse en blanco sale «Algo ha fallado al mostrar esta página» con «Recargar» e «Ir al inicio» (`ErrorBoundary` en `app.tsx`). Se rearma al navegar. El error va a la consola del navegador; no se envía a terceros.

### D-235 · Vídeos en las sugerencias y los bugs **[amplía D-210 y D-219; concreta F-161 y F-165]**
Revisión de paridad 10.9b (P2): WeeklySync aceptaba cualquier fichero en las sugerencias, y lo que más se adjunta al reportar un bug es una **grabación de la pantalla**. Audax solo admitía imágenes, ofimática, texto y ZIP (D-037), y la importación los omitía.
- **Se admiten vídeos MP4, MOV y WebM** (`video/mp4` o `application/mp4`, `video/quicktime` y `video/webm`, con su extensión casando con el tipo real, como siempre) en las sugerencias, los bugs y sus comentarios (`attachmentRules(videos: true)` y `AttachmentStorage::allowedMimes`). **Solo ahí**: en las tareas y el chat sigue la lista de D-037.
- **Con los límites de D-223:** el tamaño por fichero de `max_attachment_mb` (50 MB por defecto, hasta 200 en `/admin/ajustes`), 10 por envío, 250 MB por persona entre todas sus sugerencias y comentarios y 5 GB libres en el disco. Se descargan con su URL firmada, como el resto de adjuntos.
- **Importación (D-219):** los vídeos adjuntos de WeeklySync ya no se omiten; el resto de tipos que Audax no admite, sí (con su recuento en el informe).

### D-236 · Paridad 10.9b: pequeños cambios y lo que se deja **[concreta D-145]**
- **Hechos sin decisión propia:** nombres de quien falta al confirmar el cierre, color de la participación por tramo (todas verde, más de la mitad ámbar, si no rojo), un solo dictado a la vez en «Mi weekly», la bienvenida también al entrar con Google, el fee reconocido por su código FE (además de «Fee mensual» en la descripción), los botones Título, Subtítulo y Divisor del editor (F-154), los saltos de línea del subtítulo de las novedades, «Hasta el…» de la exención en el editor y en «Mi weekly», el texto del editor exento («No puedes escribir mientras estés exento»), la importación del texto con formato de WeeklySync sin perder nada (h1/h2 → h3, b → strong, i → em, div → p, Markdown `#`/`##`/`**`, img → enlace o su texto alternativo) y las semanas importadas con solo el texto final, que se ven por secciones en la página, el PDF/HTML y el CSV.
- **Analítica de uso (Umami y Microsoft Clarity de WeeklySync): no se trae.** El SPEC no admite terceros y el RGPD exigiría consentimiento; las métricas de uso que hacen falta salen de la auditoría y de «Uso de IA».
- **Lo que queda con prioridad baja (P3)** está en el plan (10.9b), con su motivo; ninguno pierde datos ni impide trabajar.

### D-165 · Entrar con Google **[amplía SPEC §15 y §18]**
Pedido por el propietario el 05/10: la agencia usa Google Workspace (`audaxstudio.com`) y quiere «Entrar con Google» en el inicio de sesión. Es una excepción a «integraciones externas fuera de alcance» (§18) pedida expresamente; no envía datos de la app a Google: solo se lee la identidad.
- **Mismo cliente OAuth que Google Sheets (D-142)**, con una **segunda URI de redirección** que el propietario añade en Google Cloud: `https://projects.audaxstudio.com/login/google/callback` (`login.google.callback`; `GOOGLE_LOGIN_REDIRECT_URI`, vacía = esa ruta de `APP_URL`). URL bajo `/login`, como la página a la que acompaña.
- **Sin dependencias nuevas:** OpenID Connect con el cliente HTTP de Laravel (`App\Domain\Auth\Google\GoogleLogin`), reutilizando de `GoogleOAuth` la validación del `id_token` (`idTokenClaims`, `emailVerified`), el PKCE y el envío.
- **Flujo:**
  - `POST /login/google` (`login.google`, solo invitados) guarda en la sesión el `state` (40 caracteres), el verificador **PKCE** (S256), un **`nonce`** y «Mantener la sesión iniciada», de un solo uso y con 10 minutos de vida, y manda a Google con los alcances mínimos **`openid email profile`**, `prompt=select_account` y **`hd=audaxstudio.com`** como pista (solo si hay un único dominio permitido: Google no admite varios),
  - `GET /login/google/callback` comprueba el `state`, cambia el código y **valida en el servidor** el `id_token` recibido del endpoint de tokens: emisor, audiencia, caducidad, `nonce`, `email_verified`, dominio del correo dentro de los permitidos y **`hd` igual al dominio del correo**. Así solo entran cuentas gestionadas por Google Workspace; una cuenta personal de Google creada con un correo de la empresa (sin `hd`) no vale,
  - no se piden ni se guardan tokens de Google (sin `access_type=offline`).
- **Dominios permitidos:** `GOOGLE_LOGIN_DOMAINS` (por defecto `audaxstudio.com`; varios, separados por comas). Sin ninguno válido, el de `GOOGLE_HOSTED_DOMAIN`.
- **Quién entra:** solo quien **ya existe** en la app con ese correo (comparación `lower(email)`), **activo**, de la plantilla y **que no sea colaborador externo** (D-134). **Nunca se crean usuarios.** Los clientes del portal y los colaboradores externos (que suelen usar Gmail u otro dominio) siguen con su contraseña: el acceso con Google es para la plantilla de Workspace, que es quien se gestiona desde la consola de Google.
- **Mensajes** (en `/login`, bajo el botón, desde `errors.google`): enlace caducado, cancelado en Google, Google no acepta el código o no responde, identidad no válida, correo sin verificar, dominio no permitido, sin cuenta en la app («Pide a la administración que te dé de alta»), cuenta desactivada y «solo para la plantilla». Revelar que no hay cuenta no es un problema: quien lo ve ya ha demostrado que es dueño de ese correo de la empresa.
- **Dónde está el botón:** en `/login`, bajo el formulario tras un separador «o», y en la invitación de alta («o, sin crear contraseña») si el correo invitado es de un dominio permitido. Botón neutro con la «G» de Google a color y sin modificar (sus directrices de marca; única excepción a los tokens del tema) y el estilo plano de Audax (D-137). Texto «Entrar con Google», el que pidió el propietario.

### D-166 · Google no sustituye a la verificación en dos pasos propia
- **Decisión segura:** quien tiene el 2FA propio confirmado **lo sigue pasando** después de Google, con el mismo reto de Fortify que tras la contraseña (`login.id`, `login.remember` y `TwoFactorAuthenticationChallenged`). Quien no lo tiene entra directamente.
- **Por qué:** el `id_token` de Google no trae una señal fiable de que se usó la verificación en dos pasos de Workspace (Google no documenta `amr` ni `acr` en sus tokens), y la política de Workspace puede cambiar sin que la app lo sepa. Aceptar Google como segundo factor sin esa señal bajaría la seguridad de quien ya tiene el 2FA activado. Si algún día Google la ofrece, se puede revisar.
- **2FA obligatorio** (`require_2fa`, SPEC §14): igual que con contraseña, `RequireTwoFactor` lleva a configurarlo a quien no lo tenga.

### D-167 · Registro, auditoría, límite e invitación del acceso con Google
- **Igual que el login con contraseña:** sesión regenerada, «Recordarme», vuelta a la página que se pedía, último acceso y sesiones activas.
- **Registro de accesos:** columna nueva **`login_events.method`** (`password` o `google`; las filas anteriores, `password`). El acceso correcto lo registra `RecordSuccessfulLogin` con `google` si la sesión la abre Google (directamente o tras su 2FA; el id de la persona queda en la sesión y se consume ahí); un 2FA fallido tras Google, `google`. Un reto 2FA del login con contraseña olvida un Google a medias (`ForgetGoogleLoginOnChallenge`). Los rechazos con un correo identificado quedan como accesos fallidos con `google`. La exportación de datos personales (D-075) incluye el método.
- **Auditoría** (D-074): log `auth`, entidad «Accesos con Google» y acción «Accesos con Google (correctos y rechazados)»: `google_login` (con la cuenta de Google y si falta el 2FA) y `google_login_rejected` (con el motivo). Los intentos sin identidad (state caducado, cancelado) no se auditan: solo cuentan para el límite.
- **Límite:** `throttle:google-login`, 10 por minuto e IP entre la salida y la vuelta.
- **Invitación:** como Google ya ha verificado el correo, entrar con Google **acepta la invitación pendiente** (se borra el enlace de `invitation_tokens`) y marca el correo como verificado. La contraseña sigue sin fijar; si algún día la necesita, «¿Has olvidado tu contraseña?».

### D-168 · Interruptor del acceso con Google en /admin/ajustes
- Ajuste **`google_login_enabled`** en Seguridad («Entrar con Google»), **activado por defecto**; solo se ofrece si además hay credenciales de Google (`GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET`). Sin ellas, la página lo avisa. El cambio queda en la auditoría de ajustes.
- Desactivado o sin credenciales, `/login` no enseña el botón y las dos rutas responden 404.

### D-170 · Registrado de una tarea padre: lo suyo más lo de sus subtareas **[amplía D-037 y SPEC §6]**
Pedido por el propietario el 05/10, como el «tiempo registrado» de ClickUp: «Desarrollo web», estimada en 60 h, muestra 10 h registradas porque suma lo imputado en sus subtareas.
- **Regla:** el registrado de una tarea raíz es el suyo más el de sus subtareas (las borradas no cuentan); el de una subtarea, el suyo. Las entradas siguen en la tarea en la que se imputaron: solo cambia lo que se enseña.
- **Dónde:** la lista y el kanban (el total, con «Σ» y el desglose en el texto accesible y en la ayuda), el panel (un recuadro con «Registrado total … de <estimación>», «Propio» y «En subtareas»; la lista de entradas sigue siendo la de la tarea) y Mis tareas. El calendario del equipo no enseña horas (D-144) y el resumen del proyecto ya suma todas las del proyecto: no cambian.
- **Contrato:** `logged_minutes` sigue siendo lo propio y llega `subtasks_logged_minutes`; el total es la suma. En la lista y el panel sale de las subtareas ya cargadas (sin consultas); en Mis tareas, de una subconsulta agregada en la misma consulta (`Task::withSubtasksLogged()`): los presupuestos de consultas no cambian y el panel hace una menos.
- **Colaborador externo:** las dos cifras le llegan a null (D-134).
- **Arreglo de paso:** una tarea sin horas enviaba `logged_minutes` null (la suma vacía), que la interfaz leía como «no lo ves»; ahora envía 0.

### D-171 · Estimación propia del padre con subtareas **[concreta D-037]**
Las tareas importadas de ClickUp pueden traer estimación en el padre («Desarrollo web 60 h») y en algunas subtareas. Revisado: ni la importación ni crear o estimar subtareas tocan la estimación del padre; solo se dejaba de ver.
- **Regla (sin cambios en los cálculos):** si alguna subtarea tiene estimación, la del padre es la suma de las estimadas; si ninguna la tiene, manda la propia del padre. Es la que usan los informes, la carga, el Gantt y la precisión de estimación (D-087), así que no se cambia a «la mayor» ni a «propia + subtareas».
- **Nunca se pierde:** la estimación propia se guarda aparte y vuelve a mandar en cuanto las subtareas se quedan sin estimación. Mientras mandan las subtareas, el panel la enseña («Su estimación propia (60:00) se conserva…») y no se puede editar (como hasta ahora).

### D-172 · Imputar con hora de inicio y de fin **[amplía SPEC §7 y D-035]**
Pedido por el propietario el 05/10.
- **Dónde:** el diálogo de horas, que comparten el panel de la tarea, la hoja semanal y la entrada manual de `/horas`, Inicio y la cabecera, elige entre «Por duración» y «Con hora de inicio y fin» (con la duración calculada en vivo). Al editar, abre con franja si la entrada tiene una que corresponde exactamente a sus minutos; las del temporizador (redondeadas) se editan por duración.
- **Cálculo:** fecha + horas en hora de Madrid → `started_at` y `ended_at` en UTC y los minutos del tiempo real transcurrido (`App\Domain\Time\TimeRange`, gemelo `timeRangeMinutes` en `lib/duration.ts`). Con franja, la duración que llegue se ignora.
- **Validación:** fin posterior al inicio; «00:00» como fin es la medianoche que cierra el día (22:00–00:00 = 2 h del mismo día); como mucho 24 h. Y todas las reglas de `TimeEntryRules` (fecha futura, semana cerrada, más de 24 h en el día, bolsa `block`…), porque se escribe con `TimeEntryWriter`.
- **Medianoche: se rechaza, no se parte.** Una entrada es de un día (la hoja semanal y la aprobación van por días) y partirla en silencio sorprendería a quien la escribe; el mensaje explica cómo registrarla en dos entradas. El temporizador sí parte por días porque mide solo (D-035).
- **Solapes:** con otra entrada de la misma persona que tenga franja, **aviso sin bloqueo** (`overlap`, con las franjas que se pisan; tocar el extremo no es solaparse). No había regla previa. También avisa al parar el temporizador.
- **Invariante en `TimeEntryRules`:** la franja va completa y nunca al revés.
- **Al editar sin franja:** se conserva mientras no cambien la fecha ni los minutos; si cambian, se quita (ya no describiría la entrada).
- **Panel de la tarea:** cada entrada enseña su franja («09:00–11:30», «22:00–24:00») y, si el temporizador está en marcha en la tarea, desde qué hora y cuánto lleva. Iniciar y parar sigue en la cabecera del panel.

### D-173 · Crear subtareas (y tareas) con sus datos en un diálogo **[amplía SPEC §6]**
Pedido por el propietario el 05/10: «añadir subtarea» solo creaba el título.
- **«Añadir subtarea»** (panel de la tarea) abre un diálogo con título, responsable, tipo, inicio, entrega, horas estimadas (el parser de duración, hasta 999 h), prioridad y estado. Usa la misma ruta y `TaskWriter` que el alta rápida.
- **Por defecto, del padre:** el responsable, el tipo, la prioridad y la entrega; el estado, el por defecto. El inicio no se hereda (repartiría la estimación de la subtarea por todo el rango del padre en la Carga). La bolsa es siempre la del padre (D-037).
- **Teclado:** el foco empieza en el título, Intro guarda y «Crear otra al guardar» deja el diálogo abierto, vacía el título y la estimación, conserva lo demás, devuelve el foco al título y anuncia «Creada «…»». Errores por campo, como el diálogo de horas.
- **Tareas raíz:** el alta rápida de la lista y el kanban sigue creando con Intro y añade «Crear con más datos», que abre el mismo diálogo con lo escrito, el estado de la columna y, en un proyecto de bolsas, la bolsa (primero las del departamento).

### D-238 · «Entrar con Google» sin el alcance `profile` **[cambia D-165]**
En la vuelta de Google (`/login/google/callback`) el parámetro `scope` lleva `https://www.googleapis.com/auth/userinfo.profile`, y el WAF de Plesk (ModSecurity, Comodo, regla 210580 «OS File Access Attempt») lo toma por un intento de leer `.profile` y responde 403. Se piden solo `openid email`: el acceso casa por correo y no usa el nombre ni la foto de Google. Así no hay que tocar el WAF, que es común a todas las webs del servidor.

### D-239 · Modo de prueba de los módulos para los admins **[amplía D-151; concreta F-177]**
Pedido por el propietario el 06/10: la Weekly está desplegada con todos los módulos apagados (el equipo sigue con la antigua hasta el cambio) y quiere verla y probarla ya, sin afectar a nadie.
- **Ajuste `modules_preview`** (por defecto apagado), en `/admin/ajustes` → «Weekly y módulos», bajo los módulos: «Modo de prueba para los admins». Encendido, cada módulo **apagado** se comporta para los admins (rol admin) como si estuviera encendido: rutas, navegación, páginas, acciones, búsqueda global, Inicio (tarjeta de la weekly), contador de «Mi espacio», preferencias de aviso, adjuntos de la ayuda y canales en tiempo real. Para el resto de la plantilla sigue apagado: 404 y sin entradas en el menú. Un módulo encendido no cambia.
- **Dos preguntas en `App\Domain\Weeklies\AppModules`:**
  - `enabled($module)`: ¿encendido de verdad? La usan los procesos automáticos (`weeklies:open-week`, `weeklies:remind`, la parte de la weekly de `time:remind-week`), «weekly cerrada», el plazo cambiado y el envío o la programación del informe de la Weekly por correo (`ReportAccess`): en modo de prueba no hacen nada,
  - `visibleTo($user, $module)`: ¿lo ve y lo usa esta persona? La usan el middleware `module:…`, las props compartidas (`config.modules`, ya como las ve cada uno, y `config.modules_preview`, la lista de los que ve solo por la prueba) y el resto de los puntos de cara a las personas. `previewing()` dice si alguien lo ve solo por la prueba.
- **Sin avisos a terceros:** con la Weekly apagada, `WeeklyNotifier` solo puede avisar a quien envía (un admin que se recuerda a sí mismo); «Recordar» y el envío manual responden «Modo de prueba: no se avisa a nadie.», y el plazo y el cierre lo añaden a su aviso. Las sugerencias no avisan a nadie (menciones, respuestas y cambios de estado) con la ayuda o las sugerencias apagadas.
- **Aviso en la página:** en las páginas de un módulo que el admin ve solo por la prueba (lo marca el middleware, prop `module_preview`), una línea discreta bajo la cabecera: «Modo de prueba: solo lo ven los admins; no se envían avisos.». En «Avisos de la Weekly», además, «Modo de prueba: no se avisa a nadie.» junto al envío manual.
- **Barra lateral:** las entradas de la Weekly (Mi espacio, Weeklies, Equipo, Asistente IA y Ayuda) van en su propio bloque al final, bajo una línea fina (el separador de la barra lateral, D-137) y con el encabezado discreto «Weekly», en modo de prueba y encendida. Sin ninguna entrada, no hay bloque ni línea. Sigue siendo un solo `<nav>` «Navegación principal»; el bloque es un grupo con nombre.
- **Búsqueda global:** se añaden las cinco páginas de la Weekly, para quien usa la Weekly y con su módulo visible.

### D-240 · Informe de proyecto «Interno (completo)» y versión al exportar **[amplía SPEC §10.3 y D-139]**
Pedido por el propietario el 06/10: el informe de un proyecto exportado debe llevar mucha más información para uso interno (por ejemplo, las horas por tarea y persona) y poder escoger qué se exporta.
- **Versión en la query del informe:** `?version=interno|cliente` (sin ella o con otro valor, la interna). Va en el `ReportRequest`, así que la descarga (Excel, CSV y PDF), la impresión, Google Sheets, el envío por correo y los envíos programados la llevan sin más y **sin migración**: un envío programado de antes es el interno. Solo el informe de proyecto la tiene (`ReportVersion::supports`); en otro informe, enviarla es un error de validación.
- **Interno y completo** (por defecto): todo lo de la página más el resumen del proyecto (estado, facturación, fechas, presupuesto y su consumo, horas del periodo y de toda la vida, estimado frente a real y desviación; con `view-financials`, precio cerrado, tarifa, precio de las bolsas, ingreso, coste y margen), las horas por persona, el estimado frente a real por tarea con sus subtareas debajo (D-170 y D-171) y sus horas del periodo, la matriz tarea principal × persona, la evolución por semana y por mes, las horas por tipo, las bolsas con su consumo, exceso, saldo y estado de toda la vida (lo que guarda `HourBankLedger`) y sus horas del periodo, el listado de entradas (fecha, persona, tarea y tarea principal, franja de D-172 en hora de Madrid, duración, descripción, facturable y estado) y, **solo con `view-financials`**, los costes y el margen por persona (coste medio por hora de las instantáneas de coste, ingreso según la facturación del proyecto con `RevenueCalculator`, rentabilidad y %). Sin `view-financials` sale todo menos esa sección y los importes.
- **Excel:** sin `?tabla=` es un libro con una hoja por sección (Resumen, Tareas, Estimado por tipo, Personas, Tarea x persona, Semanas, Meses, Tipos de tarea, Bolsas, Entradas y Costes y margen); con `?tabla=` (también las nuevas `resumen`, `matriz`, `meses`, `bolsas`, `entradas` y `costes`), solo esa tabla. El CSV es siempre una tabla: sin `?tabla=`, la de tareas, como antes. Google Sheets sube el libro entero.
- **PDF:** apaisado; el estimado frente a real llega a 400 filas sin partir una tarea de sus subtareas, la matriz enseña las 8 personas con más horas (el resto en «Otros») y 150 tareas, y el listado, 1.500 entradas: lo demás, en Excel. La portada dice que es la versión interna.

### D-241 · Versión del informe de proyecto «Para el cliente» **[amplía SPEC §10.3 y §11]**
- **Lo que vería en el portal:** solo las horas en los estados que ve su cliente (`portal_entry_visibility`: por defecto aprobadas y bloqueadas; nunca borradores), con los filtros del informe; las personas con el nombre, las iniciales o «Equipo» según `portal_person_display` (con «Equipo», sin reparto por persona); y las bolsas con las cifras del portal (`PortalBankFigures`: dentro, exceso, saldo y estado repartidos solo entre las horas que ve, D-092 y D-093). Un proyecto interno usa lo que el portal tiene por defecto y no enseña bolsas.
- **Nunca** costes, tarifas, precios, importes, márgenes, estimaciones, el estado de las entradas ni si son facturables.
- **Contenido:** cifras clave (horas del periodo, acumuladas, consumo del presupuesto y saldo de las bolsas abiertas), bolsas, horas por tarea (cada tarea principal con las de sus subtareas y, debajo, las subtareas), por persona, por tipo, por mes y, en periodos de hasta 14 semanas, por semana, y el detalle de las horas con su franja. Excel: el mismo libro con esas hojas. Estilo de documento de Audax, en vertical.
- No depende de `portal_show_task_hours`: lo genera y lo envía la plantilla a propósito; ese ajuste sigue mandando en el portal.
- Para filtrar por estados, `ReportFilters` admite `statuses` (no sale de la URL) y `ReportScope::entries()` los aplica: la versión del cliente usa las mismas métricas (`Metrics`) que el resto, sin datos económicos.

### D-242 · Quién saca cada versión del informe de proyecto **[concreta D-044 y D-141]**
- **Interno:** quien ve el informe del proyecto (`viewReport`), con lo que puede ver (un responsable que no lo gestiona, las horas de su equipo) y los importes solo con `view-financials`.
- **Para el cliente:** además, solo quien ve **todas** las horas del proyecto (un admin o quien lo gestiona). Así nadie manda al cliente un informe al que le faltan horas sin saberlo. Lo comprueban el documento al generar y `ReportAccess` al enviar y programar (403); un envío programado cuyo propietario pierde ese acceso se pausa, como los demás (D-141). En la página, el selector solo aparece a quien puede elegir.
- **Selector:** en «Exportar ▾» del informe de proyecto, arriba, «Interno (completo)» / «Para el cliente» (elegirlo no cierra el menú); «Enviar por correo» y «Programar envío» parten de la versión elegida y la dejan cambiar. La lista y el detalle de los envíos programados enseñan la versión.

### D-243 · El dictado de la weekly se transcribe con Gemini **[cambia D-152 y D-146; como WeeklySync]**
Pedido por el propietario el 06/10 tras probarlo: un dictado de 7 s llevaba más de 6 minutos «Transcribiendo…». La CPU virtual del servidor solo tiene SSE2/SSE3 (D-116): Whisper `small` rellena cada audio a 30 s y, con 2 núcleos de baja prioridad y el servidor cargado, un audio corto tarda minutos.
- **Motor:** el dictado de la weekly (y las notas de tareas) se transcribe con Gemini (`GeminiDictationTranscriber`), con el prompt de la primera pasada de `ws:transcribe-audio` (JSON `hasMeaningfulSpeech` / `transcription` / `reason`; sin voz útil, `no_speech`). La limpieza (D-146, F-172) sigue igual, después.
- **Cola:** `ai-high` de Horizon (segundos), no la de Whisper, para no esperar detrás de los audios largos del chat. El motor se decide al encolar (`TranscribeDictation::$viaGemini`); `ai_usage` lo apunta como «Transcripción del dictado».
- **Audio:** viaja en la petición (`inlineData`, máx. 14 MB) y se borra del servidor al transcribir, como antes. Google actúa como encargado del tratamiento; el borrador del texto RGPD lo dice.
- **Interruptor:** `DICTATION_TRANSCRIPTION_DRIVER=gemini|whisper` (por defecto `gemini`). Sin clave de Gemini se usa Whisper.
- **Los audios del chat siguen con Whisper** en el servidor (elección del propietario).

### D-260 · Barra lateral con secciones plegables **[amplía D-239; cambia el orden de la navegación del SPEC §3]**
Pedido por el propietario el 06/10: «que los menús principales se puedan colapsar: Proyectos, Weekly, Audax Woffu (le buscaremos otro nombre), Facturación…».
- **Bloques, en este orden:** Inicio y Chat **fijos arriba**, sin encabezado (lo más usado; la búsqueda global sigue en la cabecera). Después, las secciones plegables, separadas por la línea fina de D-239:
  - **Proyectos:** Mis tareas, Calendario, Proyectos, Clientes, Bolsas, Horas, Carga e Informes (con Envíos programados),
  - **Weekly:** Mi espacio, Weeklies, Equipo, Asistente IA y Ayuda (D-239),
  - **Personas** (nombre provisional del futuro módulo de RR. HH., el «Audax Woffu»): de momento, Ausencias y Ausencias del equipo,
  - **Facturación:** preparada para el futuro módulo; hoy sin entradas, así que no se pinta,
  - **Administración** (solo admins): Panel (`/admin`, activo solo en su URL), Usuarios (`manageUsers`) y Ajustes (`manageSettings`).
- **Permisos y módulos:** cada entrada sigue saliendo según el rol y los módulos (D-134, D-145, D-151 y D-239). Una sección sin entradas visibles no se pinta, ni su línea: un colaborador externo solo ve Inicio, Chat y la sección Proyectos (Mis tareas, Calendario, Proyectos y Horas).
- **Plegar:** el encabezado de cada sección es un botón con chevron, `aria-expanded` y `aria-controls` (el contenido plegado lleva `hidden`, fuera del orden de tabulación); Intro y espacio, como cualquier botón. El `<nav>` «Navegación principal» sigue siendo uno; cada sección, un grupo con nombre.
- **Estado por persona y persistente:** por defecto, todas desplegadas. Se guarda en el servidor (`users.nav_collapsed`, lista de ids; `PUT /menu/secciones`, 204, abierta también al colaborador externo; prop compartida `navCollapsed`), para que la acompañe en cualquier dispositivo, y en el navegador (localStorage por usuario, con try/catch) por si el servidor falla. Lista blanca compartida en `tests/fixtures/nav-sections.json` (`App\Domain\Navigation\NavSections` y `NAV_SECTION_IDS` de `resources/js/hooks/use-nav-sections.ts`).
- **Auto-despliegue:** al entrar en una página de una sección plegada (por la búsqueda, un enlace o la URL), la sección se despliega sola y se guarda así. Plegar la sección de la página en la que estás se respeta mientras sigas dentro de ella.
- **Barra reducida a iconos:** sin encabezados ni nada que plegar; se ven todas las entradas con su tooltip y, al volver a desplegar la barra, cada sección recupera su estado. En el móvil (hoja lateral) las secciones funcionan igual.

### D-261 · Sin «Inicio» en el menú y secciones plegadas por defecto **[cambia D-260]**
Pedido por el propietario el 06/10: «no hace falta ese icono de inicio porque nos ocupa espacio» (el logotipo ya lleva a Inicio) y que todo nazca plegado salvo Proyectos.
- **Inicio:** fuera de la barra; se llega con el logotipo de arriba (también con la barra en iconos y en el móvil). Arriba, fijo, solo queda Chat.
- **Plegadas por defecto:** Weekly, Personas, Facturación y Administración; Proyectos, desplegada. Es lo que ve quien aún no ha tocado nada (`users.nav_collapsed` null, `NavSections::DEFAULT_COLLAPSED`). Lo que cada persona pliegue o despliegue se sigue recordando, también «todo desplegado» (lista vacía), y entrar en una página de una sección plegada la despliega.
- Una migración olvidó lo guardado con el comportamiento anterior (solo del 06/10) para que a todos les llegue el nuevo.

## 06/10/2026: Plan del día (Nivel 1 de las cargas, entregas C1 a C3)
Diseño en `docs/PLAN-CARGAS.md` (§4, §6.1, §7.1, §8 a §10 y §12) con las respuestas del propietario (§15, que mandan). La Previsión (Nivel 2) no entra aquí.

### D-250 · El plan del día: módulo propio `day_plan`, «Mi día» y la tarjeta de Inicio **[amplía D-151; concreta PLAN-CARGAS §4 y §7]**
- **Módulo propio** `day_plan` en `AppModule` (no es parte de la Weekly), **activado por defecto** (un módulo que falta en el ajuste `modules` está encendido). Se apaga en `/admin/ajustes` → «Weekly y módulos». Apagado: sus rutas dan 404, sin entrada en la barra lateral, ni tarjeta en Inicio, ni búsqueda, ni recordatorio. En modo de prueba (D-239) lo ven los admins y el recordatorio no sale (`AppModules::enabled`).
- **Tablas** `day_plans` (cabecera por persona y día, `published_at` = la primera línea, `note`, `reminded_at`), `day_plan_items` (las líneas, con `carry_count` desnormalizado para la marca «↻ ×N» sin recorrer la cadena) y `day_plan_comments`; enlaces opcionales `active_timers.day_plan_item_id` y `time_entries.day_plan_item_id`. Migración solo aditiva.
- **Escritura:** `App\Domain\DayPlan\DayPlanWriter` es el único punto (como `TimeEntryWriter`): añadir, editar, borrar (lógico), ordenar, cerrar, pasar, nota y «adoptar» la tarea. Tarea → fija proyecto y cliente; proyecto → fija cliente; nada = «General / Interno». Máximo 60 líneas al día.
- **Pantallas:** «Mi día» (`/dia?fecha=`), con Intro para la siguiente línea, `@cliente`, `#proyecto` y `~1:30` (el parser de duración de siempre), arrastrar o «Subir/Bajar» para ordenar, «Desde mis tareas» y la nota del día. La entrada «Mi día» va en la barra lateral tras «Mis tareas»; la tarjeta «Mi día» de Inicio va tras el temporizador (las de siempre conservan su orden).
- **Cifras de Mi día** (§6.1): previsto = horas previstas de las líneas no pasadas; imputado = todas las entradas de la persona ese día (también sin línea); hechas / líneas del día.

### D-251 · Quién ve qué del plan del día **[concreta PLAN-CARGAS §8 y P1 a]**
- **Lo usan** admin, responsables y empleados (gate `use-day-plan` = los de la Weekly, D-147, con el módulo visible para esa persona). Nunca un colaborador externo (sus rutas tampoco están en `config/collaborators.php`) ni un cliente.
- **Textos y checks:** toda la plantilla ve los de todos (Equipo hoy y Semana).
- **Cifras** (horas previstas e imputadas, jornada, cumplimiento, hora de publicación, temporizador en marcha) y **comentarios**: solo la propia persona, su responsable y los admins (`canSeeAbsencesOf`, D-088). Para el resto llegan a `null` en las props, no solo ocultas. El motivo de una ausencia, igual (D-088); el festivo, para todos.
- **Nadie edita la línea de otro**, tampoco un admin (`DayPlanItemPolicy`). Sin ranking ni puntuación.

### D-252 · Hora límite a las 8:30 y recordatorio **[concreta PLAN-CARGAS §9 y P2 (8:30, no 10:00)]**
- **Un solo ajuste, `day_plan_deadline` (08:30, hora de Madrid):** es la hora del recordatorio y la hora desde la que «Equipo hoy» marca «Sin plan» y las líneas escritas después («añadida a las 12:40»). Se cambia en `/admin/ajustes` → «Plan del día», junto a `day_plan_reminder_enabled` (sí) y `day_plan_editable_days`.
- **A quién:** la plantilla del plan del día sin ninguna línea ese día, solo en **sus días con jornada** (`Capacity` > 0: ni fin de semana según su horario, ni festivo, ni ausencia aprobada de día completo) y sin «Estoy fuera» (D-228). Una ausencia parcial o solo solicitada no lo quita.
- **Cómo:** `day-plan:remind` cada 5 minutos; envía desde la hora límite y durante 3 horas (si el servidor estuvo parado a las 8:30, sale al volver, pero nunca a media tarde). **Una vez por persona y día local de Madrid**, aunque el comando se repita o cambie la hora: se reclama con `day_plans.reminded_at` en una actualización atómica (si el envío falla, se libera). Aviso `day_plan.reminder` del catálogo (grupo «Plan del día»): app y navegador por defecto, email opcional; sin canal activado no se reclama.
- **«Recordar» a mano** en «Equipo hoy»: su responsable o un admin, con su nombre en el aviso y el mismo registro (tampoco dos el mismo día; el de las 8:30 ya no sale). Nada se avisa al responsable (P2 a).
- **Modo de prueba o módulo apagado:** no sale nada (`AppModules::enabled`), tampoco el «Recordar» («Modo de prueba: no se avisa a nadie.»).

### D-253 · Cerrar y pasar líneas: hoy y el último día con jornada **[concreta PLAN-CARGAS §4.4 y P3]**
- **Escribir** (añadir, texto, cliente o proyecto, horas, borrar, ordenar, nota): hoy y cualquier día hasta el domingo de la semana que viene. El pasado no se reescribe.
- **Cerrar** (hecha, no hecha con motivo, pendiente otra vez, pasar a otro día, imputar): además, los `day_plan_editable_days` últimos días **con jornada** (1 por defecto): el lunes aún se cierra el viernes; con el viernes de vacaciones, el jueves. Lo anterior es de solo lectura.
- **«Pasar a hoy» con un clic** (P3 a): al abrir hoy, «Tienes 3 pendientes del lunes» con «Pasar todas a hoy», «Elegir…» y «Marcar como no hechas», con las pendientes de esos días que aún se cierran. Pasar crea una copia en el destino (`carried_from_id`, `carry_count` + 1, la marca «↻ ×N») y deja la original como «pasada». Nunca es automático. Borrar la copia la deshace (la original vuelve a pendiente). Una hecha no se pasa.
- **Check con tarea:** si la línea tiene una tarea abierta, al marcarla hecha se pregunta «¿Marcar también la tarea como hecha?» («Solo la línea» o «También la tarea», con `TaskPolicy::update` y `TaskWriter`). Nunca al revés ni en silencio.

### D-255 · Equipo hoy, la semana y los comentarios **[concreta PLAN-CARGAS §4.2 y §4.3]**
- **Equipo hoy** (`/dia/equipo?fecha=&departamento=`): una fila por persona de la plantilla, desplegada, con su estado («Plan a las 09:12», «Sin plan» en rojo pasada la hora límite, «Aún no», «Ausente», «Festivo», «No trabaja»), su nota del día y sus líneas. A quien puede ver sus cifras: previsto frente a jornada (en rojo si se pasa), imputado, hechas, arrastradas y «Ahora: …» con el temporizador. Resumen del departamento: con plan, sin plan, fuera y, solo si se ven las cifras de todos, hechas y arrastradas.
- **Filtros:** departamento en la URL (por defecto el de quien mira; `todos` para toda la plantilla) y persona en el navegador.
- **Semana** (`/dia/semana?semana=2026-W41`): personas × días (de lunes a viernes, y el fin de semana si alguien trabaja o planifica); clic en un día para ver sus líneas. «Hechas/planificadas» por celda y el total de la semana, solo con las cifras.
- **Comentarios** (`day_plan_comments`): los dejan su responsable y los admins; la persona contesta desde Mi día. Los ven solo ellos. Avisan a la dueña de la línea (`day_plan.commented`, en la app). Cada uno borra los suyos. Auditados.

### D-254 · Las horas de una línea: siempre en una tarea **[concreta PLAN-CARGAS §6.1.4 y §10; amplía D-035 y D-172]**
- **Nunca se relaja `task_id`** (R8): `active_timers.task_id` y `time_entries.task_id` siguen obligatorios. La línea resuelve una tarea y las horas llevan además el enlace opcional `day_plan_item_id` (en `TimeEntryData`; `TimeEntryWriter` solo lo guarda, sin tocar ninguna regla de bolsas, aprobación ni bloqueo).
- **▶ desde la línea** (`App\Domain\DayPlan\DayPlanTime`): con tarea, esa; si no, «¿En qué tarea?» con las tareas del proyecto de la línea (las abiertas y las mías primero: `/horas/tareas?project_id=`) o las de siempre (incluido el proyecto interno), y «Crear la tarea «<texto>» en <proyecto>» (`TaskWriter`, asignada a quien la crea; en un proyecto de bolsas, la bolsa abierta de su departamento, si no la única sin departamento o la única abierta; si no se puede decidir, se pide elegir una tarea). Todo en una transacción: si el temporizador no puede arrancar (reglas de siempre), no se crea la tarea. La tarea queda en la línea (con su proyecto y cliente) para la próxima vez.
- **`TimerService`** guarda `day_plan_item_id` y lo copia a las entradas al parar (a las dos si cruza la medianoche). La misma tarea desde otra línea para y vuelve a empezar (cada línea con sus horas); sin línea, como siempre.
- **Al parar** (cabecera, Inicio o Mi día) una línea aún pendiente: aviso «¿Das por hecha «…»?» con «Marcar como hecha» (prop flash `day_plan_prompt`), sin bloquear. La cabecera enseña «en «<línea>»».
- **Imputar a mano** desde la línea: el diálogo de horas de siempre (D-172) con la tarea, el día y lo previsto rellenos; la línea sin tarea se queda con la de sus horas. **«Imputar lo previsto»**: una línea hecha con tarea y horas previstas y sin horas imputa esas horas en su día (borrador, `TimeEntryWriter`); en bloque, «Imputar lo previsto de N líneas hechas sin horas» (cada línea por su cuenta: si una no se puede, las demás sí). **«Vincular horas»**: mis entradas de ese día sin línea (`TimeEntryWriter::linkDayPlanItem`, sin tocar minutos; nunca las bloqueadas al facturar).
- **Integración:** «Añadir a mi día» (o a mañana) en Mis tareas y «Desde mis tareas» en Mi día; el «Autocompletar» de «Mi weekly» trae primero las líneas de mi plan de la semana por cliente («Creatividades campaña otoño (hecha, 2 h 10 min)», sin las pasadas; sus tareas no se repiten) con el módulo visible; la vista Día por personas del calendario del equipo lleva un desplegable «Plan del día» de cada persona con los permisos de D-251.

### D-256 · RGPD, retención y auditoría del plan del día **[concreta PLAN-CARGAS §10 y R2; amplía D-075 y D-074]**
- **Es un dato de desempeño.** Exportación de datos personales: «plan-del-dia» (mis líneas, con la nota de cada día y las borradas) y «comentarios-plan-del-dia» (los de mis líneas y los que he escrito).
- **Retención** `retention_day_plans_months` (12 meses por defecto, de 1 a 120, en `/admin/privacidad`): `app:prune-data` borra los días anteriores con sus líneas y comentarios; las horas no se borran nunca, solo pierden el enlace.
- **Auditoría:** `day_plans`, `day_plan_items` y `day_plan_comments` (entidad «Plan del día» en `/admin/auditoria`), sin el orden de las líneas ni la hora del recordatorio.
- **Texto RGPD pendiente del asesor (D-030):** el borrador ya lo menciona (para qué, qué datos y quién ve qué: textos para la plantilla; cifras y comentarios, la persona, su responsable y la administración; sin clasificaciones). **Pendiente del propietario:** que el asesor revise esos tres párrafos con el resto del texto.

## 06/10/2026: Canales del chat e importación del chat de ClickUp
Pedido por el propietario: traer el chat de ClickUp (canales, mensajes, hilos, reacciones y adjuntos) y reunir todos los chats en la página Chat. Detalle y procedimiento en `docs/PLAN-CHAT-CLICKUP.md`.

### D-270 · Canales del chat: ver no es participar **[amplía SPEC §12 y D-068]**
- **Dos tipos nuevos de conversación** (`ConversationType::Client` y `Team`), además de proyecto, directa y grupo. Columnas nuevas en `conversations`: `client_id` (único), `icon` y `archived_at`.
- **Quién ve** cada conversación lo dice una sola regla, `ConversationAccess`, que usan la política y todas las consultas (lista, búsqueda, contadores, Inicio, avisos y tiempo real): directa → sus participantes; grupo y proyecto → sus participantes y el admin, que modera; canal de cliente → toda la plantilla y los colaboradores con proyectos de ese cliente; canal de equipo → toda la plantilla y los colaboradores que un admin añada.
- **Participar** (no leídos, avisos de `@todos`, «leído por» y actividad en tiempo real) es aparte: `ChannelMembership` añade a quien le toca **solo si nunca ha estado** (quien sale no vuelve a entrar solo); lo anterior a su entrada cuenta como leído. Se hace al crear el canal, al entrar en un proyecto del cliente y, como red de seguridad, al pedir la lista del chat.
- **Entrar y salir** de un canal es libre («Unirme» y «Dejar el canal» en la cabecera, sin mensaje de sistema). Quien escribe en un canal que ve pasa a participar; a quien se menciona y lo ve sin participar se le hace entrar para que le llegue el aviso.
- Quien pasa a colaborador sale también de los canales de equipo (`CollaboratorOffboarding`).

### D-271 · Canal del cliente **[amplía D-021 y D-134]**
- Uno por cliente, creado la primera vez que se abre: desde la ficha del cliente («Canal del cliente», para toda la plantilla) o desde la lista del chat (`/chat/clientes/{id}`, abierta también al colaborador con proyectos de ese cliente).
- Participan los miembros de sus proyectos activos; el resto de la plantilla lo ve y puede entrar.
- Título y emoji, los del cliente. Con el cliente desactivado queda de solo lectura («El cliente está desactivado: su canal es de solo lectura»).

### D-272 · Canales de equipo **[amplía D-119]**
- Los crean, renombran, cambian de emoji (uno del selector, D-117) y archivan los **admins** («Nuevo → Canal de equipo» y «Ajustes del canal»). Archivado, se lee pero no se escribe.
- Participa toda la plantilla interna activa; un colaborador externo solo si un admin lo añade (o lo era en ClickUp).
- Cada cambio deja un mensaje de sistema (`channel.renamed`, `channel.archived`, `channel.unarchived`) y su entrada en la auditoría (log `chat`, acción «Cambios en los canales del chat»: `channel_created`, `channel_updated`, `channel_members_added`, `channel_member_removed`).
- RGPD: los mensajes de los canales siguen la retención del chat (D-130) y salen en la exportación de datos personales como «Canal «…»» o «Canal del cliente …» (D-131).

### D-273 · La página Chat reúne todos los chats en tres niveles plegables **[cambia D-110 y D-121]**
Pedido por el propietario el 06/10.
- **Niveles:** (1) **Canales**, los de equipo; (2) **Proyectos y clientes**, cada cliente con su canal como cabecera (si aún no existe, un enlace que lo abre y lo crea) y, sangrados debajo, los chats de sus proyectos; los proyectos internos, en «Proyectos internos»; (3) **Directos**, directas y grupos.
- La lista trae las conversaciones en las que se participa **y todos los canales que se ven** (`ConversationDirectory::listable`): desde ahí se entra a cualquier chat sin pasar por el proyecto (el chat del proyecto sigue también en su pestaña).
- Cada nivel con su número y sus **no leídos** (sin las silenciadas; los de C2 en vivo), y todo de la **última actividad** a la más antigua.
- **Filtros:** buscador (nombre, código o cliente, sin tildes), «Solo los míos» (canales en los que se participa y clientes con algo propio) y «Ocultar archivados». Por defecto lo archivado (proyectos y canales archivados, clientes desactivados) va **al final** de su grupo.
- **Plegado y filtros persistentes** por persona en este navegador (`localStorage`, clave `audax.chat.list.{id}`, con `try/catch`: sin almacenamiento vale lo de por defecto).
- Botones de nivel con `aria-expanded`/`aria-controls`, recuento y no leídos dichos en texto; emojis decorativos (`aria-hidden`). A 375 px sin scroll horizontal (E2E).
- **Rendimiento:** la lista sigue sin N+1 (consultas fijas: canales que faltan, conversaciones, proyectos, clientes, lo suyo, directas, últimos mensajes, menciones, personas y no leídos). Presupuestos de `C1PerformanceTest`: `chat.show` 35 y `chat.show.focus` 37 (+3).

### D-274 · Descarga del chat de ClickUp **[concreta D-135]**
- `scripts/clickup/descargar-chat.py` (API v3 de chat; solo lectura): canales con su ubicación (`parent`) y miembros, mensajes, respuestas, reacciones, metadatos y ficheros de los adjuntos (las URL de `clickup-attachments.com` son públicas) y `users.json` (miembros y personas del export v2).
- **Despacio y reanudable:** 40 peticiones por minuto por defecto (el límite del token es 100 y se comparte), espera en los 429, guarda cada página al momento y sigue donde lo dejó. Las reacciones (una petición por mensaje) van al final.
- El volcado y el token nunca entran en Git; el volcado se crea con permisos 700/600.

### D-275 · Adónde va cada canal de ClickUp **[concreta D-135]**
- Canal de una **lista** → el chat de su proyecto (o del proyecto de su bolsa). Las listas de **Audax Interno** (proyectos internos, sin cliente: Marketing, Innovación, Web…) → **canal de equipo**, como pidió el propietario.
- Canal de una **carpeta** → el canal de su cliente.
- Canales **generales** (del workspace, de un espacio o sueltos: Daily, Diseño, Audax Studio…) → canal de equipo, con el emoji del nombre como icono.
- Canales de listas o carpetas sin correspondencia en la app → canal de equipo, con aviso. Los **vacíos** no se importan.
- `clasificacion.json` en el volcado manda sobre todo lo anterior (`team`, `group`, `skip`, `project:<id>`, `client:<id>`).

### D-276 · Mensajes importados
- Conservan **fecha y autor**; las ediciones de ClickUp se marcan como editadas. Las **respuestas** de un hilo son respuestas al mensaje padre (el hilo del chat, D-069). El título de una publicación (`post`) va en negrita al principio.
- **Texto:** el Markdown de ClickUp pasa al del chat: menciones `<@id>` (con su fila en `message_mentions`), `@followers`/`@channel`/`@here` → `@todos`, tareas `☑`/`☐`, viñetas `•`, títulos en negrita, escapes quitados, tarjetas de enlace en varias líneas → la URL; como mucho 10.000 caracteres.
- **Adjuntos:** los descargados de tipos admitidos se guardan como adjuntos del mensaje (con `AttachmentStorage`, misma validación de tipo real); el resto (vídeos, HEIC…) queda como enlace «📎» a ClickUp.
- **Reacciones:** por su nombre corto (`+1`, `heart`, `tada`…) al emoji del selector; las que el chat no tiene se avisan y no entran.
- **Sin avisos ni tiempo real:** no pasa por `MessageWriter`; todo lo importado queda **leído**. Una sola entrada en la auditoría, «Importación del chat de ClickUp» (log `import`, evento `clickup_chat_import`).
- **Idempotente** con `import_refs` (fuente `clickup`; tipos `chat_channel`, `chat_message` y `chat_attachment`).

### D-277 · Los canales privados de ClickUp son grupos
- Un canal **privado** de ClickUp (Estratégico, AudaxIA, «Melodía | Cliente»…) no se abre a toda la plantilla: se importa como **grupo** con sus miembros. Decisión tomada en autonomía para no exponer conversaciones restringidas.

### D-278 · Personas del chat de ClickUp **[concreta D-136]**
- Cada autor se casa por su correo de ClickUp con `personas.json` (el de `app:import-clickup`) y, por él, con su cuenta, activa o de antiguo empleado; si su correo no está o esa ficha no se importa (cuentas antiguas de la misma persona), por el **nombre**.
- Lo que no casa (ClickBot, invitados que ya no están) lo firma **«Usuario de ClickUp»**, una cuenta desactivada (`usuario-clickup@antiguos.audaxstudio.invalid`) que solo sirve para eso. Las cuentas las crea `app:import-clickup`: este comando va después.
- En los grupos, las personas desactivadas quedan como antiguas (con su histórico). Los directos «contigo mismo» (notas) no se importan.

### D-279 · Mensajes directos: solo los del dueño del token, y opt-in para el resto
- La API solo deja leer los directos y grupos de quien es dueño del token: se importan los del propietario, con sus participantes.
- Cualquier otra persona puede traer **los suyos** después: descarga con su token (`descargar-chat.py --solo-directos --token-file …`) e importación con `app:import-clickup-chat <volcado> --solo-directos` (solo directas y grupos). Ningún admin ve las directas (D-071).

## 06/10/2026: Previsión (Nivel 2 de las cargas: entregas P1, P2 y el dominio de P4)
Diseño en `docs/PLAN-CARGAS.md` (§5, §6.2 a §6.7, §7.2 a §7.5, §8 a §10 y §12) con las respuestas del propietario (§15, que mandan: P4 a, P5 b, P6, P7 c y P8 b). Esta entrega es el backend y el contrato; las pantallas definitivas saldrán del diseño de los gráficos (rama `prevision-diseno`). Rama `prevision-datos`.

### D-280 · Módulo `forecast`, apagado por defecto, y páginas provisionales **[amplía D-151 y D-239]**
- **Módulo propio** `forecast` en `AppModule` y en `/admin/ajustes` → módulos. **Apagado por defecto** también en una instalación nueva (`Setting::DEFAULTS`): las pantallas son provisionales. La migración `add_forecast_off_to_stored_modules` lo añade **apagado** donde el ajuste `modules` ya está guardado (si faltara, contaría como activo y se abriría a toda la plantilla al desplegar). Apagado: todas sus rutas dan 404; en modo de prueba (D-239), los admins lo ven.
- **Guardar los ajustes ya no enciende módulos por omisión:** los módulos que no llegan en el formulario conservan su valor (antes, uno que faltaba se guardaba encendido).
- **Páginas provisionales** (`forecast/index`, `forecast/projects/index`, `forecast/projects/show` y `projects/planning`): tablas sencillas con los datos del contrato, sin enlace en la barra lateral ni pestaña en el proyecto. Se rehacen con el diseño.

### D-281 · El proyecto previsto **[concreta PLAN-CARGAS §5.1, §5.2 y §7.2; cambia §6.5 por P5 b]**
- **Tabla `forecast_projects`:** nombre, **cliente existente o nombre libre** (`prospect_name`; uno de los dos es obligatorio: con cliente, el nombre libre se borra), color de la paleta de proyectos, descripción, responsable (por defecto quien lo crea), fechas, estimación global opcional en minutos e importe estimado (`decimal`, solo con `view-financials`: sin el permiso ni se guarda ni se ve).
- **Seguridad solo «segura» (`firm`) o «posible» (`tentative`)**, sin probabilidad ni ponderación (P5 b): se quitan del diseño `probability`, `forecast_default_probability` y el interruptor «Ponderar».
- **Estados:** abierto → **confirmado** (se ha ganado; pasa a «segura» y ya no puede volver a «posible») · **perdido** (con motivo y fecha; deja de contar; «Reabrir» lo devuelve a abierto) · **vinculado** (tiene proyecto real). Solo se editan los abiertos y los confirmados; un vinculado no se borra.
- **Sin horas estimadas por departamento en el previsto:** lo que se espera de cada departamento son sus huecos (asignaciones sin persona); la línea base lo resume por departamento (D-286).
- Auditado (`LogsDomainActivity`, entidad «Previsión»), sin la foto de la línea base campo a campo.

### D-282 · Asignaciones y su reparto **[concreta PLAN-CARGAS §5.1, §6.2 y §7.2]**
- **Tabla `allocations`:** de un proyecto real **o** de un previsto; de una persona **o** de un departamento sin persona (**hueco**); modo, minutos o porcentaje, desde y hasta, nota y `copied_from_allocation_id`. Las reglas «de uno u otro» van como `CHECK` en PostgreSQL y, siempre, en `App\Domain\Forecast\AllocationWriter`, el único punto de escritura (crear, editar, borrar, «Asignar a…» y copiar). Se audita.
- **Personas asignables:** solo la plantilla activa (admin, responsables y empleados). Nunca un colaborador externo ni un cliente. Como mucho tres años por asignación y el 200 % de dedicación.
- **Reparto** (`AllocationPlanner`, por días): «día laborable» = capacidad > 0 en `Capacity` (jornada − festivos − ausencias aprobadas; las solicitadas no restan); de un hueco, un día en el que trabaja alguien de su departamento (sin nadie, la jornada por defecto sin festivos).
  - `total`: a partes iguales entre los días laborables; los minutos que sobran, a los primeros días; sin días laborables, todo al primero (marcada «sin días»).
  - `per_day`: esos minutos cada día laborable, aunque la jornada sea menor.
  - `percent`: el % de la capacidad de ese día de la persona (una ausencia parcial la baja); de un hueco, el % de la jornada por defecto («0,5 personas»).
  - `monthly`: cada mes natural, sus minutos repartidos como un total; un mes partido, a prorrata de días laborables. Sin fin: hasta el horizonte de quien lo mira (en un previsto o un proyecto sin fecha de fin, 12 meses desde el inicio).
- **Restante en proyectos reales** (solo `total` de una persona): max(total − lo que esa persona imputó en ese proyecto dentro del rango, 0), desde max(hoy, inicio); con el fin pasado y restante, todo a hoy y «vencida» (como una tarea, D-051). Los demás modos, los huecos y los previstos son de plan fijo.
- **Más allá de un año**, la jornada semanal vigente en el tope, sin festivos ni ausencias (D-051).
- Casos compartidos en `tests/fixtures/allocations.json`.

### D-283 · Qué cuenta en la carga de la previsión **[cambia PLAN-CARGAS §6.4 y §6.5 por P6 y P7]**
- **Solo asignaciones** (P6 y P7): ni las horas estimadas de las tareas, ni las bolsas, ni los fees cuentan (para que cuenten se les crea una asignación, p. ej. «20 h al mes de Marketing»). Por eso no hace falta `projects.load_source` ni la regla «la asignación manda»: no se crea.
- **Tres capas** (`LoadCombiner`): **real** (asignaciones de proyectos planificados o activos; los en pausa, completados, archivados o borrados no suman), **seguro** (previstos «seguros» abiertos o confirmados) y **posible** (previstos «posibles» abiertos). Los perdidos y los vinculados no cuentan nunca (el vinculado ya está en su real).
- **Capacidad:** la de `Capacity` por persona; la de un departamento, la suma de su plantilla activa de hoy (R6). Los huecos suman a la carga de su departamento y salen aparte (`gaps`). Quien no tiene departamento va en «Sin departamento».
- **`/carga`, el Calendario e Inicio no cambian todavía:** siguen con la carga por tareas (D-051). Integrarlos es parte de las pantallas (D-289).

### D-284 · Quién ve y quién toca la previsión **[concreta PLAN-CARGAS §8 con P4 a y P8 b]**
- **Permiso nuevo `manage-forecast`** (admins y responsables por defecto; migración para las instalaciones en uso): crear, editar, confirmar, dar por perdido, reabrir, borrar y vincular previstos y sus asignaciones. Un admin puede dárselo a otra persona.
- **Gates:** `view-forecast` (la previsión global y los previstos: admins, todos los responsables, que ven a toda la plantilla, y quien tenga `manage-forecast`) y `use-forecast` (su propia carga, `/prevision/mi-carga`: toda la plantilla, también con los previstos posibles, P8 b). Los empleados no ven la previsión global.
- **Pestaña Planificación de un proyecto real** (`ProjectPolicy::viewPlanning` y `manageAllocations`): quien gestiona el proyecto (admin, responsables y sus gestores, D-022), si no está archivado. No se limita a «personas de su departamento» (§8): un responsable ya gestiona cualquier proyecto (D-022). Un gestor de proyecto no toca los previstos.
- **Crear el proyecto real** desde un previsto exige además poder crear proyectos (D-022); **vincular**, gestionar ese proyecto; **desvincular**, solo un admin.
- **Siempre fuera:** colaboradores externos (D-134: sus rutas no están en `config/collaborators.php` y las gates los niegan) y clientes. Todo detrás del módulo `forecast`.

### D-285 · Periodo, «desde hoy» e impacto «sin / con» **[concreta PLAN-CARGAS §5.3 y §6.3]**
- **Periodo** de `/prevision`: de 1 a 12 meses (3 por defecto) desde `?desde=` (hoy), por meses naturales o por semanas ISO de lunes a domingo (`?por=semanas|meses`), y `?departamento=`.
- **Cuenta de hoy en adelante:** los días pasados del periodo no tienen ni carga ni capacidad, para que la ocupación del mes en curso no salga baja (`counts_from` en el contrato).
- **Impacto** de un previsto abierto o confirmado (`ForecastImpact`): por mes, de hoy hasta su última asignación (o 3 meses si no tiene fin; como mucho 12), la capacidad y la carga **sin** él (todas las capas: «la pregunta incómoda primero») y **con** él de cada departamento y de cada persona que toca. La ocupación y el semáforo (D-052) los calcula la interfaz (`lib/forecast.ts`).

### D-286 · Vincular, copiar y congelar la línea base **[concreta PLAN-CARGAS §6.6 con P6]**
- **Vincular** con un proyecto real (`ForecastLinker`): no archivado, que quien vincula gestione y sin otro previsto (uno por proyecto, índice único). Se toma la **foto congelada** (`forecast_projects.baseline`, `ForecastBaseline`): el plan completo de sus asignaciones en total, por persona, por departamento (el de la persona en ese momento o el del hueco), por mes y por departamento y mes, con las fechas previstas. El previsto pasa a «vinculado» y «seguro», y sus asignaciones quedan de solo lectura.
- **Copiar las asignaciones** (por defecto, sí): el real recibe copias idénticas (`copied_from_allocation_id`), que son su plan vivo; las personas asignadas que no eran miembros entran como miembros (para poder imputar).
- **Crear el proyecto real desde el previsto:** el alta de siempre (`StoreProjectRequest`, `ProjectCreator`, sin plantilla) con las personas asignadas como miembros, y se vincula. Si el previsto es de un cliente nuevo, primero se crea el cliente.
- **Desvincular** (admin): vuelve a «confirmado», se borran el vínculo y la foto, y las copias del real se quedan.

### D-287 · Estimado frente a real **[concreta PLAN-CARGAS §6.7 con P6]**
- **Estimado:** la línea base congelada, nunca las asignaciones vivas ni las horas estimadas de las tareas. **Real:** todas las horas imputadas en el proyecto vinculado, sea cual sea su estado (el criterio del consumo y de D-081/D-084).
- **Por persona, por departamento** (el real, con el departamento de la persona hoy; v1) **y por mes**, con los acumulados para la curva.
- **Fechas:** inicio real = primera entrada; fin real = la última si el proyecto está completado; en curso, la proyección al ritmo de los últimos 28 días (días naturales).
- **Desviación** = (real − estimado) / estimado, en % con un decimal (redondeo de PHP, igual en la interfaz); por debajo de medio punto, «Igual que lo estimado». Casos compartidos en `tests/fixtures/forecast-deviation.json`.
- Se ve en la ficha del previsto vinculado y en la Planificación del real (petición aparte). El informe «Precisión de previsiones» queda para después (D-289).

### D-288 · Contrato y rendimiento **[concreta PLAN-CARGAS §7.5 y R7]**
- **Rutas** (`routes/app/forecast.php`, nombres en inglés): `GET /prevision` (`forecast.index`), `GET /prevision/mi-carga` (JSON), `/prevision/proyectos` (lista, alta), `/prevision/proyectos/{id}` (ficha, edición, borrado) y sus acciones `confirmar`, `perdido`, `reabrir`, `vincular`, `crear-proyecto`, `vinculo` y `asignaciones`; `PUT|DELETE /prevision/asignaciones/{id}` y `asignar`; `GET /proyectos/{id}/planificacion` y `POST /proyectos/{id}/asignaciones`.
- **Props de cada página** en `resources/js/types/forecast.ts` (`ForecastIndexPageProps`, `ForecastProjectsPageProps`, `ForecastProjectPageProps`, `ProjectPlanningPageProps`, `MyForecastResponse`), con `AllocationResource` y `ForecastProjectResource`. El impacto, estimado frente a real y los selectores llegan diferidos (grupos `analysis` y `options`). Todo en minutos enteros.
- **Rendimiento:** 30 personas × 12 meses en unos 120 ms (< 300 ms en el test) y las mismas consultas sea cual sea la plantilla: una de personas, una de departamentos, una de asignaciones, una de horas imputadas y las de `Capacity`. Los días laborables de cada persona se calculan una vez y nunca se lee un atributo de Eloquent por día.

### D-289 · Lo que queda para las pantallas y P3, P4 y P5 **[concreta PLAN-CARGAS §9, §10 y §12]**
- **Con el diseño:** las pantallas definitivas, la entrada en la barra lateral, la pestaña Planificación en la ficha del proyecto, los formularios de previstos y asignaciones y los E2E.
- **Integración (P1 y P3):** «Mi carga» de Inicio, `/carga` y el Calendario con las asignaciones; la caché por versión (D-086).
- **P4 y P5:** el informe «Precisión de previsiones», la exportación, los avisos de §9 (asignación nueva y previsto sin vincular a 7 días), el resumen de los lunes, la búsqueda global y los previstos abiertos en la ficha del cliente.

## 06/10/2026: Diseño de la Previsión (Nivel 2 de las cargas)
Diseño completo en `docs/DISENO-PREVISION.md`; maquetas y capturas en `docs/diseno-prevision/`. Parte de las respuestas P4 a P8 (PLAN-CARGAS §15).

### D-290 · `/prevision` es una matriz de ocupación
- **Forma elegida:** la alternativa A. Departamentos × semanas (o meses): la fila del departamento son columnas apiladas frente a su capacidad y, al desplegarla, las personas en celdas con el semáforo de D-052 y una barra de capas. Debajo van los huecos sin persona y los previstos abiertos.
- **Descartadas como vista principal:** B (una gráfica de barras por departamento), que queda como posible modo «Resumen», y C (cronograma por persona), que se reutiliza en «Mis asignaciones» y en las tablas de asignaciones.
- **Las maquetas** están en `docs/diseno-prevision/`, con sus fuentes y su script de capturas, y fuera de lint y formato en `vite.config.ts` porque no son código de la app.

### D-291 · Codificación de las capas: el color dice el tipo, la trama dice «posible»
- Real `--chart-1`, previsto `--chart-3`, previsto posible `--chart-3` **con trama** a 45° e imputado `--chart-2`. La capacidad va con una línea de 2 px en tinta y el hueco, con borde discontinuo. Sin colores nuevos (D-012).
- **Validador de la skill `dataviz`:**
  - en claro pasa todo (peor par para el daltonismo: 15,6; en visión normal: 19,7);
  - en oscuro, aviso de daltonismo de 7,6 entre previsto y real, legal porque hay codificación secundaria (separación de 2 px, orden fijo de apilado, trama, leyenda, tooltip y tabla);
  - todas las marcas pasan 3:1 sobre la tarjeta.
- **La trama va siempre puesta** (no es opcional) porque es un dato y el único canal que separa seguro de posible sin depender del color.

### D-292 · Sobrecarga, festivos y ausencias en la previsión
- Se usan los umbrales, tintes, iconos y textos del semáforo de D-052. Las barras no se pintan de rojo: el exceso se ve porque la columna cruza la capacidad, y el % lleva icono y texto.
- Los festivos van en la cabecera de la columna; la ausencia parcial, como una muesca en la esquina de la celda (con su tipo solo para quien puede verlo, D-088); la semana entera ausente, como celda gris «Ausencia».

### D-293 · Huecos sin persona
- Una fila «Sin persona» por departamento (solo si hay huecos en el horizonte) con borde discontinuo y las horas, sin % ni capacidad. Suman a la fila del departamento.
- La lista «Huecos sin persona» tiene «Asignar a…», que enseña la ocupación de cada candidato en esas fechas.

### D-294 · Horizonte, agrupación y capas como filtro
- **Horizonte:** 2, 3, 6 o 12 meses. Por semanas hasta 3 meses y por meses desde 6 (se puede cambiar).
- **Capas:** tres casillas con su muestra, leyenda y filtro a la vez, todas marcadas por defecto. El % y el nivel los calcula el cliente sobre las capas marcadas.
- Sin «Ponderar %» (P5). Por defecto se despliegan los departamentos con alguna semana alta o en sobrecarga; en móvil, ninguno.

### D-295 · Impacto «sin / con» de un previsto
- Una frase con el peor caso (departamento y persona), seguida de una rejilla de las semanas del proyecto por departamento afectado y por persona con nombre.
- En cada celda, «sin este proyecto» en gris de contexto y lo que añade con la capa del previsto, frente a la capacidad, con «91 → 101 %» y el icono del nivel resultante. «Sin» incluye todas las capas marcadas.

### D-296 · Plan frente a imputado en la Planificación
- Por semana, el plan es una línea escalonada (las asignaciones) y lo imputado son columnas, en un solo eje. La semana en curso va atenuada, y una semana pasada en la que alguien con plan no imputó nada se marca con su nombre.
- Por persona, una barra de bala (lo imputado frente al plan hasta hoy) y la desviación con flecha, con aviso por encima de ±10 %. No usa el semáforo de carga.
- No hay selector de «fuente de la carga» (P6).

### D-297 · Estimado frente a real
- El estimado va en violeta (el color del previsto), lo real en turquesa (el de lo imputado) y la previsión en turquesa discontinuo.
- **Previsión al cerrar** = lo real más lo que **queda asignado** en el proyecto real (P6), en lugar del «ritmo de las últimas 4 semanas» de PLAN-CARGAS §6.7. La fecha de fin prevista es la última con asignación.
- Hay cuatro vistas: acumulado con etiquetas directas, barras de bala por departamento, columnas emparejadas por mes y tabla por persona.

### D-298 · Cifras de la previsión
- Horas redondeadas a la hora («24 h», «1.240 h») en lugar de h:mm. Los minutos exactos van en las exportaciones (D-081).
- Espacio duro antes de «h» y de «%».
- Columnas sin esquinas redondeadas (D-137). Se propone pasar `BAR_RADIUS` a 0 en todas las gráficas (pendiente del propietario).

### D-299 · «Mi carga» del empleado
- La tarjeta de Inicio añade las próximas 12 semanas en % de la jornada (con la trama de lo posible) y «Lo que viene», con los previstos posibles dichos en texto («puede no salir», P8).
- `/carga` del empleado: columnas por semana frente a su jornada y «Mis asignaciones» en cronograma.
- **Abierto:** si la tarjeta deja de contar las tareas en el corto plazo (P6) o enseña las dos cifras. Pregunta 2 de `DISENO-PREVISION.md` §8.

### Numeración
- Fase 2: D-078 a D-087.
- Fase 3: D-088 y D-091.
- Fase 4: D-089 y D-090.
- Fase 5: D-092 a D-109.
- Fase 6: D-110 a D-121.
- Fase 7: D-122 a D-133.
- Fase 8: D-134 a D-138 (D-138: paneles de Inicio reordenables).
- Fase 9: D-139 a D-142.
- Tareas y calendario: D-143 y D-144.
- Fase 10 (la Weekly): D-145 a D-161 y D-180 a D-236 (D-151 a D-154: contrato 10.1; D-155 a D-161: 10.2a; D-180 a D-186: 10.2b; D-187 a D-193: 10.3; D-194 a D-198: 10.4; D-199 a D-202: 10.5; D-203 a D-206: 10.6; D-207 a D-212: 10.7; D-213 a D-220: 10.8; D-221 a D-226: 10.9a, seguridad; D-227 a D-236: 10.9b, paridad; D-239: modo de prueba de los módulos).
- Acceso con Google: D-165 a D-168.
- Barra lateral con secciones plegables: D-260 y D-261.
- Mejoras de tareas: D-170 a D-173.
- Informe de proyecto interno y para el cliente: D-240 a D-242 (D-239, en otra rama).
- Dictado de la weekly con Gemini: D-243.
- Plan del día: D-250 a D-256.
- Canales del chat e importación del chat de ClickUp: D-270 a D-279.
- Previsión: D-280 a D-289.
- Diseño de la previsión: D-290 a D-299.
- Libres sin usar: D-162 a D-164, D-169, D-174 a D-179 y D-244 a D-249.

La siguiente libre es **D-244** (reservadas: D-257 a D-259 para el plan del día y la previsión; D-262 a D-269 y D-300 en adelante, sin usar).
