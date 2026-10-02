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
