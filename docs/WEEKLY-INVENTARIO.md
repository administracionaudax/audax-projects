# WeeklySync → Audax Proyectos: inventario y propuesta de fusión

_Análisis del 05/10/2026, en solo lectura. Repos: WeeklySync (`scratchpad/weekly`, commit `b8f6f63`) y Audax Proyectos (worktree `fase-7-contrato`; **ojo: la rama es `fase-10`**, no `fase-9`). Supabase `mhihyqnjopsduttmebdm`: consultado el esquema y los recuentos, sin leer contenido personal._

Las rutas `ws:` son del repo de WeeklySync. Las demás, de Audax.

> **Dos avisos antes de empezar**
> 1. **Gemini 2.5 Flash se retira el 16/10/2026** (`ws:supabase/functions/_shared/vertexAI.ts:8-13`). Todas las funciones usan ese modelo por defecto y el comentario dice que el proyecto de GCP solo tiene acceso a la familia 2.5. **Si no se cambia el secreto `GEMINI_MODEL` (y se habilita un modelo más nuevo en GCP), dentro de 11 días WeeklySync deja de transcribir y de generar weeklies**, antes de que dé tiempo a migrar.
> 2. **El SPEC choca con WeeklySync.** El SPEC §2 y §12 dicen «sin IA externa ni APIs de pago» y que «ningún audio sale del servidor». WeeklySync manda el audio y los textos de los clientes a Gemini (Vertex AI). Hace falta una decisión del propietario (pregunta G1) y anotarla como D-145 o siguiente.

---

## A. Inventario funcional
La lista exhaustiva y numerada está en **A.4**. A.1 a A.3 dan el contexto.

### A.1 Pantallas
Hay dos roles: **ADMIN** (8 de los 13 usuarios) y **MEMBER** (5). Un superadmin de plataforma, aparte.

| Ruta | Pantalla (componente) | Qué hace | Quién la ve |
|---|---|---|---|
| `/resumen` | Resumen admin (`AdminDashboard.tsx`) | Estado global de la weekly activa (quién ha enviado, quién falta y quién está excusado), bloque «Gestión de weekly actual» (cerrar), Mis clientes, «Unirme a proyectos» y «Marcar ausencia» (se marca uno mismo VACATION/ABSENT con fecha de fin, `:58`, `:249-258`) | ADMIN |
| `/resumen` | Resumen de miembro (`MemberDashboard.tsx`) | Estado de mi weekly, racha (`:40-80`), mis clientes (responsable o colaborador, `:98-99`) y unirme a clientes | MEMBER |
| `/mi-espacio` | Mi espacio (`MySpace.tsx`) | **Reporte semanal** (`WeeklyReportingInterface.tsx`): una caja por cliente, texto o dictado, borrador autoguardado y enviar. **Tareas** (`TaskView.tsx`): crear, prioridad, fecha, notas, archivar, dictar y «Generar tareas con IA» a partir de la última weekly cerrada. **Mis envíos** (`ReportList.tsx`) | Todos |
| `/weeklys` | Histórico (`WeeklysList.tsx`) | Semanas (activa y cerradas), última cerrada, «Configurar día límite» y borrar semana | Todos (gestión, ADMIN) |
| `/weeklys` → pestaña | **Estado de proyectos** (`ProjectStatusView.tsx`, 2.400 líneas) | Sube capturas de pantalla del estado de los proyectos y las pasa por OCR con Gemini. Muestra un preview editable y los pasos A-D: clientes nuevos o ambiguos, ocultos que reaparecen y activos que faltan. Al aplicar, sustituye la «foto» actual (presupuesto y horas consumidas por proyecto, con los códigos BH/FE/WE/PR/EC/AD/AM/AT/BR/GE) | Ver: todos. Subir: ADMIN (`:264`) |
| `/weeklys/:id` | Informe (`ReportView.tsx`) | Informe estructurado: resumen global, riesgos y, por cliente, estado On Track/Risk/Blocked, resumen ejecutivo, próximos pasos, hitos, etiquetas y satisfacción. Además: estado del equipo, reportes originales, audio por secciones, exportar a HTML (`weeklyHtmlExport.ts`) y pantalla completa. ADMIN: generar o actualizar el texto, generar el audio, editar el informe, día límite, cerrar y borrar | Todos (acciones, ADMIN) |
| `/clientes`, `/clientes/:id` | Clientes (`ClientView.tsx`) | Cartera ordenable (nombre, último reporte, satisfacción) y alta, edición y baja (ADMIN). Ficha con pestañas: resumen, historial (timeline semanal), equipo (actividad por persona con IA) y satisfacción (gráfica con los `satisfactionScore` de cada weekly, `:777-841`). Incluye un «Resumen del cliente (IA)». Unirse o salir como colaborador | Todos (gestión, ADMIN) |
| `/equipo`, `/equipo/:id` | Equipo (`TeamView.tsx`) | Alta (`create-user`, con invitación por Resend), edición (rol, departamento, puesto y estado), baja y asignar clientes. Ficha de persona: histórico de reportes, «Resumen de desempeño (IA)» y actividad por cliente (IA) | Todos (gestión, ADMIN) |
| `/ia` | Asistente IA (`KnowledgeBaseView.tsx`) | Chat de preguntas y respuestas sobre weeklies, clientes, tareas y estado de proyectos, con un contexto recortado (6 semanas y de 20 a 80 filas) | Todos |
| `/ayuda` | Centro de ayuda (`HelpView.tsx`, 2.500 líneas) | Pestañas: **General** (manual PDF, enlace de soporte y novedades, automáticas por versión y manuales, con «me gusta»), **Tutoriales** (vídeos de hasta 200 MB), **FAQ** por secciones y **Sugerencias** | Todos (contenido, ADMIN) |
| `/ayuda` → Sugerencias | `HelpSuggestionsView.tsx` (4.200 líneas) | Tableros y categorías, propuestas con estado (open → future → planned → building_now → beta → completed), votos, comentarios anidados con adjuntos y reacciones, roadmap, historial de estados y modo «bug» | Todos (estados, ADMIN) |
| `/notificaciones` | Ajustes de avisos (`NotificationSettingsView.tsx`) | Recordatorios por email y web (día y hora), plantillas `automatic`, `manual` y `weekly_closed` con variables `{nombre}`, `{semana}` y `{weekly_url}`, envío manual a personas concretas y registro de envíos | ADMIN |
| `/perfil` | Perfil (`ProfileView.tsx`) | Editar el perfil y ver estadísticas: envíos, a tiempo y racha (`:36-83`) | Uno mismo |
| — | Login y onboarding | Google OAuth de Supabase (`LoginScreen`). `resolve-user-access` vincula identidades por email canónico. `CompleteProfileView` obliga a poner nombre, departamento y puesto | — |
| `/plataforma` | Consola multi-tenant (`PlatformView.tsx`) | Tenants, planes, módulos, admins de plataforma, salud y uso de IA | Superadmin (**se descarta**) |

**Tiempo real:** Supabase Realtime, con canales para users, clients, client_projects, client_team_members, project_status_*, week_cycles, submissions, entries, drafts y tasks (`ws:App.tsx:922-980`).

### A.2 Flujo de una semana
1. **Apertura.** Solo puede haber una semana `ACTIVE` (índice único, `ws:supabase/migrations/001_initial_schema.sql:95`). No hay cron que la abra: **al cerrar una semana se crea la siguiente** (+7 días; número `Wnn-aa` y etiqueta «Semana nn (Lun dd/mm - Vie dd/mm)», `ws:close-week-and-update-satisfaction/index.ts:366-395`). La semana va de lunes a viernes.
2. **Plazo.** `deadline_date` es por defecto el viernes (`end_date`, migración 025). Un ADMIN puede ampliarlo mientras la semana está activa (`ws:App.tsx:2222`).
3. **Borrador.** Cada persona escribe por cliente, solo en los clientes activos de los que es responsable o colaboradora. Se autoguarda con debounce en `weekly_submission_drafts` y sus `*_entries` (`ws:WeeklyReportingInterface.tsx:151-160`).
4. **Audio.** Se graba en el navegador y `transcribe-audio` hace dos pasadas con Gemini: (a) detecta si hay voz útil y transcribe literalmente, y (b) limpia el texto corrigiendo los nombres con el catálogo de clientes y personas. **El audio no se guarda**: en la base, `audio_url` está vacío en todas las filas.
5. **Envío.** Va a `weekly_submissions` (una por persona y semana) y a `client_report_entries` (una por cliente). Al reenviar se sobrescribe el texto y se rehacen las entradas, pero `submitted_at` se conserva (`ws:App.tsx:2011-2100`). Después se borra el borrador. Se puede editar mientras la semana está `ACTIVE`, también fuera de plazo (RLS en `009_…sql`). Con la semana cerrada, la pantalla queda en solo lectura (`WeeklyReportingInterface.tsx:27`).
6. **Excusas.** Una persona está exenta si su estado es VACATION o ABSENT y su fecha de fin es igual o posterior al plazo, o no tiene fecha (`ws:src/lib/weekExcusal.ts`). Al cerrar se congela `excused_user_ids` (`close-week…:24`). Además, solo cuentan las cuentas ACTIVE cuyo `joined_at` es anterior o igual al final de la semana (`AdminDashboard.tsx:18-31`).
7. **Recordatorios.** Una GitHub Action llama cada 5 minutos a `check-scheduled-reminders`. Esta busca las reglas `email_reminders` del día y la hora (Madrid, 10 minutos hacia atrás) y llama a `send-email-reminder` (Resend, `noreply@audaxstudio.com`) para quien no ha enviado, está AVAILABLE y tiene la cuenta ACTIVE. Deduplica por `trigger_key` en `email_log`. Los recordatorios **web** son el `Notification` del navegador con un `setInterval`: solo funcionan con la pestaña abierta (`ws:src/lib/notificationScheduler.ts:85-147`).
8. **Informe (ADMIN).** `generate-weekly-report` agrupa por cliente activo, hace lotes de 9.000 caracteres, procesa 3 clientes a la vez, fusiona por cliente, genera el resumen global y devuelve el JSON. El front lo guarda en `structured_report`, `final_report_text` y `submission_count_at_generation`. Si hay más envíos que al generar, el informe sale como «desactualizado» (`ReportView.tsx:112`). Un cliente sin reportes recibe la nota «Sin novedades» y su estado se calcula con el consumo: más del 100 % es Blocked y desde el 85 % es Risk; en los fees, un exceso sobre lo esperado lo sube a Risk, o a Blocked si son 4 h o más (`ws:generate-weekly-report/pipeline.js:6-7, 73-135`).
9. **Audio TTS (ADMIN).** `generate-audio-tts`: Gemini escribe el guion por secciones (intro, clientes y cierre) y Google Cloud TTS lo locuta (voz `es-ES-Journey-F`, MP3, trozos de 4.500 bytes). Se sube a `audio-submissions/weekly-reports/<week>/sections/…` y se guarda en `structured_report.audioSections` y `final_report_audio_url` (`ws:App.tsx:2318-2440`).
10. **Cierre (ADMIN).** Se puede cerrar con **texto y audio generados**. Las personas pendientes no lo impiden; solo se avisa (`ReportView.tsx:171-182`). `close-week-and-update-satisfaction` congela los excusados, marca la semana `CLOSED`, crea la siguiente y, en segundo plano: (a) recalcula la **satisfacción** de cada cliente con reportes, (b) la escribe en `clients.current_satisfaction` y en `structured_report.clientUpdates[].satisfactionScore`, y (c) envía el email `weekly_closed` a todos los activos.
11. **Satisfacción.** Gemini propone un delta y una regla determinista lo amortigua (`ws:_shared/satisfaction.js:108-221`):
    - el delta va de −8 a +8,
    - es 0 con menos de 12 palabras, sin señal de dirección ni impacto explícito, o en una semana de rutina,
    - el tope es 2, 4 o 6 según la evidencia (LOW, MEDIUM o HIGH) y baja con señales mixtas o poca confianza,
    - desde 82 o 92 se frena la subida,
    - el resultado se acota a 0-100 (`close-week…:208`).
12. **Después.** «Generar tareas con IA» (`extract-tasks`) a partir de la última weekly cerrada, con deduplicación por cliente y descripción parecida (`ws:App.tsx:2674-2710`). Las **rachas** cuentan semanas seguidas enviadas a tiempo. Las semanas excusadas no la rompen, y la semana activa tampoco hasta que pasa el plazo (`MemberDashboard.tsx:40-80`).
13. **Borrar una semana (ADMIN).** Borra en cascada sus envíos y tareas. Si era la última, crea la siguiente (`ws:App.tsx:2127-2195`).

### A.3 Otras reglas que hay que conservar
- La «foto» de proyectos sustituye entera a la anterior: `is_current=false` en **todas** las filas (`apply-project-status-upload/index.ts:260-263`). Lo esperado de un fee se calcula con los días laborables del mes, de lunes a viernes y sin festivos (`ws:src/lib/projectStatus.ts:231-268`).
- Las funciones de IA de análisis (`generate-performance-summary`, `analyze-*`, `query-knowledge-base`) **solo piden estar autenticado, no ser ADMIN**: cualquier miembro puede pedir el resumen de desempeño de otra persona. No hay que replicarlo así.
- **Fallos de WeeklySync que no hay que copiar:**
  - «Iniciar ciclo semanal» solo crea la semana en memoria, no en la base (`ws:App.tsx:2198-2220`),
  - «Eliminar persona» dice que conserva el historial, pero borra `users` y, en cascada, sus envíos (`ws:App.tsx:1635-1650`, FK `ON DELETE CASCADE`),
  - el recordatorio web no tiene en cuenta las exenciones (`ws:notificationScheduler.ts:60-105`),
  - los ajustes de módulos y de mantenimiento de la consola se guardan, pero la app no los aplica.

### A.4 Lista de comprobación (requisito: no se pierde ninguna funcionalidad)
Sale de recorrer `components/` (los 51), `App.tsx`, `src/`, `services/`, las Edge Functions y las rutas de `src/lib/routes.ts`. Las rutas de fichero son de WeeklySync.

**Destino:**
- **Existe:** Audax ya lo tiene.
- **Adaptar:** Audax tiene la base y hay que completarla.
- **Nueva:** hay que construirla.
- **Sustituida:** un equivalente nativo cubre la misma necesidad (lo confirma el propietario, G3).
- **No aplica:** solo tenía sentido en Supabase.
- **Descartado:** consola de plataforma.

La entrega es la de F.

**Navegación y comportamiento general**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-001 | Barra lateral: Resumen, Mi espacio, Weeklys, Clientes y Equipo | `Layout.tsx:78-83` | Adaptar: entradas nuevas en la barra de Audax (10.2). **Pantalla (10.2):** «Mi espacio» y «Weeklies» (D-180); «Equipo» llega con la 10.4. **Hecho (10.4): «Equipo», tras Clientes (D-195).** |
| F-002 | Barra plegable con tooltips de cada entrada | `Layout.tsx:229-290` | Existe |
| F-003 | Contador rojo de reportes pendientes en «Mi espacio» | `Layout.tsx:295-300` | Nueva (10.2). **Servidor hecho (10.2a):** prop compartida `weeklies.pending` (D-160). **Hecho (10.2): contador de «Mi espacio» en la barra lateral (D-180).** |
| F-004 | Cabecera y menú móviles | `Layout.tsx:191-200` | Existe |
| F-005 | Modo oscuro o claro con conmutador, guardado, y favicon según el tema | `Layout.tsx:323-382`, `index.html:26` | Existe (claro, oscuro y sistema) |
| F-006 | Botón «Asistente AI», que abre `/ia` | `Layout.tsx:511-513`, `App.tsx:435` | Nueva (10.6) **Hecho (10.6): «Asistente IA» en la barra lateral, tras Chat, con el módulo `assistant` (D-206).** |
| F-007 | Mi estado (Disponible, Vacaciones o Ausente/Baja, con fecha de vuelta) desde el avatar | `Layout.tsx:441-608` | Adaptar: solicitar una ausencia en Audax + exención de weekly (10.2). **Servidor hecho (10.2a):** exención y renuncia; la ausencia es la de Audax. **Hecho (10.2): la ausencia es la de Audax; la exención y la renuncia, en «Mi weekly» y en el resumen.** **Hecho (10.9b): «Estoy fuera» (vacaciones o ausente/baja con fecha de vuelta) desde el menú del avatar y «Mi weekly», con efecto inmediato e insignia en el avatar; la ausencia de Audax se puede pedir a la vez (D-228).** |
| F-008 | Acceso al perfil con nombre y rol en el pie de la barra | `Layout.tsx:436-460` | Existe **Hecho (10.9b): el pie de la barra enseña el rol (D-234).** |
| F-009 | Cerrar sesión, limpiando el almacenamiento local | `Layout.tsx:471-478`, `App.tsx:1347-1374` | Existe |
| F-010 | Accesos a Ayuda y a Notificaciones (este, solo ADMIN) | `Layout.tsx:350-404` | Nueva: Ayuda (10.7). Adaptar: ajustes (10.5). **Hecho (10.5): «Avisos», cuarta pestaña de /weeklies para quien gestiona, con enlace desde /admin/ajustes (D-199); Ayuda, en la 10.7.** **Hecho (10.7): «Ayuda» en la barra lateral, tras el asistente, con el módulo `help`.** |
| F-011 | Avisos emergentes de éxito, error e información | `Layout.tsx:172`, `App.tsx` | Existe |
| F-012 | Aviso de bienvenida al entrar | `App.tsx:831-833` | Nueva (trivial, 10.2). **Servidor hecho (10.2a):** aviso al entrar (D-161). **Hecho (10.2): el aviso sale con los avisos emergentes de siempre.** **Hecho (10.9b): también al entrar con Google.** |
| F-013 | Detecta una versión nueva y recarga sola cuando no hay nada editándose ni grabándose | `App.tsx:325-400`, `src/lib/appUpdateGuard.ts`, `buildMeta.ts` | Adaptar: versión de Inertia + aviso (10.2). **Servidor hecho (10.2a):** `GET /version` (D-161). **Hecho (10.2): aviso «Hay una versión nueva» con «Recargar» (D-184).** |
| F-014 | Recarga al volver a la pestaña o recuperar la conexión; aviso «Conexión inestable» y reintento | `App.tsx:378-395, 852, 1011` | Adaptar (10.2). **Hecho (10.2): aviso «Sin conexión» y recarga de los datos al volver (D-184).** |
| F-015 | Tiempo real de personas, clientes, semanas, envíos, borradores y tareas | `App.tsx:922-980` | Adaptar: Reverb o recarga (10.2). **Hecho (10.2): dictado en vivo por Reverb y recarga de los datos al volver a la pestaña o a la conexión (D-184).** **Hecho (10.9b): evento `weekly.changed` por Reverb; el resumen, el histórico y el informe se actualizan solos (D-229).** |
| F-016 | Rutas en español con enlace directo (`/resumen`, `/mi-espacio`, `/weeklys/:id`, `/clientes/:id`, `/equipo/:id`, `/ia`, `/ayuda`, `/perfil`, `/notificaciones`) | `src/lib/routes.ts` | **Hecho (10.1):** `routes/app/weeklies.php` con `/weeklies`, `/weeklies/{id}`, `/mi-espacio`, `/equipo`, `/equipo/{id}`, `/ia`, `/ayuda` y `/admin/uso-ia` (clientes, perfil y notificaciones ya existían). Las pantallas, en su entrega |
| F-017 | Pantalla de error con «recargar» | `ErrorBoundary.tsx`, `index.tsx` | Existe (`error.tsx`) **Hecho (10.9b): `ErrorBoundary` con «Recargar» e «Ir al inicio» si una página falla en el navegador (D-234).** |
| F-018 | «¿Tarda demasiado? Forzar entrada» en la carga inicial | `App.tsx:~2895` | No aplica (Inertia no tiene esa carga) |
| F-019 | PWA: manifest e iconos claro y oscuro | `public/site.webmanifest`, `index.html` | Existe |
| F-020 | Cabeceras de tabla fijas al desplazar | `src/lib/useFloatingTableHeader.ts` | Adaptar (10.4) **Hecho (10.4): cabecera fija en las tablas de clientes, equipo y estado de proyectos (D-197).** |
| F-021 | Fechas de semana compactas en móvil | `src/lib/mobileWeekFormat.ts` | Adaptar (10.2). **Hecho (10.2): «Sem. 41 · 05/10 - 09/10» en el móvil.** |

**Acceso, onboarding y perfil**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-022 | Entrar con Google | `LoginScreen.tsx:115-134` | Sustituida por el login de Audax (contraseña y 2FA). ¿Añadir «Entrar con Google»? (G3) **Hecho (D-165): «Entrar con Google».** |
| F-023 | Aviso de entorno: localhost apuntando a producción | `LoginScreen.tsx:22, 105` | No aplica |
| F-024 | Vincular identidades de Google y fusionar duplicados | `resolve-user-access`, migración 022 | No aplica (un email por cuenta) |
| F-025 | Invitación que se activa en el primer acceso | `create-user`, `account_status` | Existe (`UserInviter`) |
| F-026 | Completar el perfil obligatorio (nombre, departamento y puesto) | `CompleteProfileView.tsx` | Adaptar: añadir «Puesto» (`job_title`). **10.1:** columna `users.job_title`; falta el campo en el perfil y en el alta (10.2). **Servidor hecho (10.2a):** `job_title` en el perfil y en el alta. **Hecho (10.2): «Puesto» en el perfil y en el alta y edición de personas.** |
| F-027 | Editar el perfil (nombre, puesto y departamento) | `ProfileView.tsx:253-305` | Existe (+ puesto). **Servidor hecho (10.2a):** `job_title` en el perfil. **Hecho (10.2).** **Hecho (10.9b): el departamento y el rol se ven en el perfil de solo lectura; cambiarse el departamento uno mismo no se permite porque decide quién aprueba horas y ausencias (D-234).** |
| F-028 | Estadísticas del perfil: reportes, a tiempo y racha | `ProfileView.tsx:36-155` | Nueva (10.4). **Servidor hecho (10.2a):** `weeklyStats` en el perfil, adelantado de 10.4 (D-161). **Hecho (10.2): enviadas, a tiempo y racha en el perfil.** **Hecho (10.4): también en la ficha de persona.** **Hecho (10.9b): con el mapa «Constancia (últimas 12 semanas)» (D-233).** |
| F-029 | Avatar | `users.avatar_url` | Existe (avatar subido) **Hecho (10.9b): subir la foto recortada, reducida a 256 px y sin metadatos, servida con URL firmada; en la exportación RGPD (D-234).** |

**Resumen (paneles)**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-030 | Estado de mi weekly (pendiente, con retraso, enviada, enviada con retraso, «¡Todo listo!») con botón para reportar | `AdminDashboard.tsx:330-365`, `MemberDashboard.tsx:187-223` | Nueva: tarjeta de Inicio (10.2). **Servidor hecho (10.2a):** tarjeta `weekly` de Inicio y `me` en /weeklies. **Hecho (10.2): resumen de /weeklies y tarjeta «Weekly» de Inicio.** |
| F-031 | Racha en el resumen | `AdminDashboard.tsx:294`, `MemberDashboard.tsx:40-80, 150` | Nueva (10.2). **Servidor hecho (10.2a):** `streak`. **Hecho (10.2).** |
| F-032 | Aviso de weekly exenta (vacaciones o ausencia) y «tu racha no se verá afectada» | `AdminDashboard.tsx:300-308` | Nueva (10.2). **Servidor hecho (10.2a):** `me.exemption_reason`. **Hecho (10.2).** |
| F-033 | Mis clientes como responsable y como colaborador, con insignias y enlace a la ficha | `AdminDashboard.tsx:502-555`, `MemberDashboard.tsx:98-310` | Adaptar: clientes de mis proyectos (10.2). **Servidor hecho (10.2a):** `my_clients`. **Hecho (10.2).** |
| F-034 | «Unirme a proyectos» (varios a la vez) y «Dejar proyecto» | `AdminDashboard.tsx:528-730`, `MemberDashboard.tsx:277-375`, `App.tsx:1661-1830` | Adaptar: miembros de proyecto (10.2). **Servidor hecho (10.2a):** `weeklies.projects.join` y `leave` (D-156). **Hecho (10.2).** |
| F-035 | ADMIN: «Gestión de weekly actual» con acceso al informe y «Cerrar semana» (confirmación y motivo del bloqueo) | `AdminDashboard.tsx:381-430, 208-210` | Nueva (10.3) **Hecho (10.3): «Cerrar semana» en la gestión del resumen y en el informe, con el motivo del bloqueo (D-191).** |
| F-036 | ADMIN: estado global con el progreso de envíos y la lista de pendientes | `AdminDashboard.tsx:389-490` | Nueva (10.2). **Servidor hecho (10.2a):** `active.team`. **Hecho (10.2).** |
| F-037 | ADMIN: recordar por email a una persona pendiente (plantilla «manual») | `AdminDashboard.tsx:229-239, 472`, `emailHelpers.ts:102` | Nueva (10.5). **Hecho (10.5): «Recordar» en las pendientes del resumen, con la plantilla manual y los canales de la persona (D-199).** |
| F-038 | ADMIN: marcar a otra persona como ausente o de vacaciones, con fecha de fin | `AdminDashboard.tsx:464-622` | Adaptar: ausencia aprobada o exención manual (10.2). **Servidor hecho (10.2a):** exención manual (D-159). **Hecho (10.2): «Eximir» con nota y «Quitar exención».** **Hecho (10.9b): «Eximir» elige «Solo esta semana» o «De vacaciones»/«Ausente o de baja» hasta una fecha (varias semanas), con enlace a Ausencias del equipo (D-228).** |
| F-039 | «¡Todo el equipo disponible ha reportado!» | `AdminDashboard.tsx:490` | Nueva (10.2). **Servidor hecho (10.2a):** `active.team.counts`. **Hecho (10.2).** |
| F-040 | Estado vacío sin semana activa, con «Iniciar ciclo semanal» (ADMIN) | `App.tsx:2930-2955, 2198` | Nueva: apertura automática + botón (10.2). **Servidor hecho (10.2a):** `weeklies:open-week` y «Iniciar la semana» (D-155). **Hecho (10.2).** |

**Mi espacio: reporte semanal**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-041 | Pestañas «Reportes» y «Tareas» | `MySpace.tsx:89-145` | Nueva (10.2). **Servidor hecho (10.2a):** `tab`. **Hecho (10.2): la pestaña «Tareas» enlaza a Mis tareas hasta la 10.6.** **Hecho (10.6): la pestaña «Tareas» es la de WeeklySync (D-203).** |
| F-042 | Mis weeklies con su estado: Pendiente, Enviado, Enviado con retraso, Próximamente, Con retraso, No enviada, Exento, Cierre sin reporte y Semana activa | `ReportList.tsx:50-152` | Nueva (10.2). **Servidor hecho (10.2a):** `weeks`. **Hecho (10.2).** |
| F-043 | Abrir el reporte de cualquier semana (solo lectura si está cerrada) | `MySpace.tsx:45-80`, `WeeklyReportingInterface.tsx:27, 484` | Nueva (10.2). **Servidor hecho (10.2a):** `?semana=` con `editor.read_only`. **Hecho (10.2).** |
| F-044 | Una caja por cliente activo mío + «General / Interno» | `WeeklyReportingInterface.tsx:17, 555` | Nueva (10.2). **Servidor hecho (10.2a):** `editor.clients.proposed` (D-157). **Hecho (10.2).** |
| F-045 | Añadir otro cliente al reporte con un buscador | `WeeklyReportingInterface.tsx:297-305, 659-691` | Nueva (10.2). **Servidor hecho (10.2a):** `editor.clients.catalog`. **Hecho (10.2).** |
| F-046 | Plegar o desplegar un cliente o todos | `WeeklyReportingInterface.tsx:427, 495-501, 572` | Nueva (10.2). **Hecho (10.2).** |
| F-047 | Guía «cómo reportar» (consejos) | `WeeklyReportingInterface.tsx:461-477` | Nueva (10.2). **Hecho (10.2).** |
| F-048 | «Autocompletar desde Mis tareas»: hechas y pendientes, con sus notas, por cliente | `WeeklyReportingInterface.tsx:308-360` | Adaptar: tareas y horas de Audax de la semana (10.2). **Servidor hecho (10.2a):** `editor.autofill`. **Hecho (10.2).** **Hecho (10.9b): con las notas de cada tarea (D-231).** |
| F-049 | Dictado por cliente con transcripción y limpieza; aviso si no hay voz; «Transcribiendo y limpiando audio…» | `WeeklyReportingInterface.tsx:199-279, 622-643`, `transcribe-audio`, `src/lib/audioTranscription.js` | Adaptar: Whisper + limpieza (10.2). **Servidor hecho (10.2a):** `dictations.store` y `show` con Whisper (D-158). **Hecho (10.2): grabadora del chat, «Transcribiendo…» con el evento y sondeo de respaldo (D-182).** |
| F-050 | Descarta los audios demasiado cortos sin llamar a la IA | `audioTranscription.js` (`shouldSkipAudioTranscription`) | Nueva (10.2). **Servidor hecho (10.2a):** `too_short`. **Hecho (10.2).** |
| F-051 | Borrador autoguardado con estado (Guardando, Guardado, Error) y recuperación | `WeeklyReportingInterface.tsx:126-164, 707-709`, `supabaseHelpers.ts:536-618` | Nueva (10.2). **Servidor hecho (10.2a):** `my-weekly.draft`. **Hecho (10.2): autoguardado a los 700 ms con su estado y reintento (D-181).** |
| F-052 | Enviar o actualizar el reporte: se edita hasta el cierre, también fuera de plazo, y conserva la fecha de envío | `WeeklyReportingInterface.tsx:362-381, 712-718`, `App.tsx:2011-2100` | Nueva (10.2). **Servidor hecho (10.2a):** `my-weekly.submit`. **Hecho (10.2).** **Hecho (10.9b): se puede enviar sin apuntes, tras confirmarlo (D-230).** |
| F-053 | Ver mi exención en el formulario y quitármela | `WeeklyReportingInterface.tsx:407-419, 525-537` | Adaptar (10.2). **Servidor hecho (10.2a):** `weeklies.exemptions.waive` (D-159). **Hecho (10.2).** |
| F-054 | No se puede editar mientras estoy exento | `WeeklyReportingInterface.tsx:486` | Nueva (10.2). **Servidor hecho (10.2a):** `WeeklyRuleViolation::EXEMPT`. **Hecho (10.2).** |

**Mi espacio: tareas**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-055 | Tareas agrupadas por cliente («Tareas Generales» sin cliente) | `TaskView.tsx:132-138, 441` | Adaptar: Mis tareas, agrupadas por cliente (10.6) **Hecho (10.6): mis tareas asignadas, por cliente del proyecto, con «Tareas generales» para los internos (D-203).** |
| F-056 | Filtro Pendientes o Completadas | `TaskView.tsx:305-311` | Existe (D-143) **Hecho (10.6): también en «Mi espacio», Todas, Pendientes o Completadas.** |
| F-057 | Ver u ocultar archivadas; archivar y recuperar | `TaskView.tsx:153-163, 317-370, 506-517` | Adaptar: archivado personal (10.6) **Hecho (10.6): `task_archives`, solo para quien archiva (D-203).** |
| F-058 | Crear y editar (descripción y cliente obligatorio); Intro guarda | `TaskView.tsx:263-300, 621-665` | Existe (tarea en un proyecto del cliente) **Hecho (10.6): «Nueva tarea» y «Editar» en «Mi espacio», con proyecto (y bolsa) obligatorio, por `tasks.store` y `TaskWriter` (D-203).** **Hecho (10.9b): buscador con más de 8 clientes o proyectos, aviso al crear y explicación de qué clientes salen (D-231).** |
| F-059 | Marcar hecha o pendiente con un clic | `TaskView.tsx:141, 463` | Existe **Hecho (10.6): la casilla de «Mi espacio».** |
| F-060 | Notas de la tarea editables en línea, con dictado | `TaskView.tsx:148, 538-555` | Adaptar: descripción o comentario + dictado con Whisper (10.6) **Hecho (10.6): la descripción en texto plano, con autoguardado y dictado (`task_note`); con formato, se edita en la tarea (D-203).** **Hecho (10.9b): aviso de que la nota la ve el equipo del proyecto (D-231).** |
| F-061 | Eliminar para siempre | `TaskView.tsx:158, 524` | Existe **Hecho (10.6): «Eliminar» en «Mi espacio», con confirmación y solo sin horas (D-037).** **Hecho (10.9b): con horas, el botón queda desactivado y explicado (D-231).** |
| F-062 | Generar tareas con IA a partir de la última weekly cerrada, sin duplicar | `TaskView.tsx:331`, `App.tsx:2674-2760`, `extract-tasks` | Nueva, con revisión antes de crear (10.6) **Hecho (10.6): Job en la cola `ai`, prompt y deduplicación portados; propuestas que se revisan y se crean con `TaskWriter` (D-204).** **Hecho (10.9b): al terminar, aviso y la revisión a la vista para crear con un clic (D-231).** |
| F-063 | Prioridad y fecha de entrega (en el modelo; la interfaz no las muestra) | `types.ts` (`Task`) | Existe **Hecho (10.6): se ven y se ponen en «Mi espacio».** |

**Weeklys: histórico y ciclo**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-064 | Pestañas «Histórico» y «Estado de proyectos» | `WeeklysContainer.tsx` | Nueva (10.2 y 10.4). **Servidor hecho (10.2a):** pestaña «Histórico» (`tab`). **Pantalla (10.2):** pestaña «Histórico»; «Estado de proyectos», en la 10.4. **Hecho (10.4): pestaña «Estado de proyectos» (`/weeklies/estado-proyectos`, D-196).** |
| F-065 | Weekly actual destacada; la última cerrada sigue destacada hasta que se cierra la siguiente | `WeeklysList.tsx:211, 522-523` | Nueva (10.2). **Servidor hecho (10.2a):** `active` y `latest_closed`. **Hecho (10.2).** |
| F-066 | Tabla del histórico: weekly, fecha límite, participación, estado (Finalizada, Próximamente, Con retraso, Completada o Por completar) e informe generado o pendiente | `WeeklysList.tsx:92-123, 371, 537-560` | Nueva (10.2). **Servidor hecho (10.2a):** `cycles` con `progress` y `participation`. **Hecho (10.2).** |
| F-067 | Tira de avatares del equipo (enviado, pendiente o exento) con enlace a la persona | `WeeklyTeamStatusStrip.tsx`, `WeeklysList.tsx:354`, `App.tsx:3034` | Nueva (10.2). **Servidor hecho (10.2a):** `team.members`. **Hecho (10.2): sin enlace a la persona hasta la ficha de la 10.4.** **Hecho (10.4): cada avatar lleva a la ficha de la persona.** |
| F-068 | Configurar el día límite (ADMIN, solo con la semana activa) | `WeeklysList.tsx:182-195, 637-681`, `App.tsx:2222-2251` | Nueva (10.2). **Servidor hecho (10.2a):** `weeklies.deadline.update`. **Hecho (10.2).** |
| F-069 | Eliminar una weekly (ADMIN, irreversible; si era la última, crea la siguiente) | `WeeklysList.tsx:177, 699-710`, `App.tsx:2127-2195` | Nueva (10.2). **Servidor hecho (10.2a):** `weeklies.destroy` (D-155). **Hecho (10.2).** |
| F-070 | Una sola semana activa; al cerrar se crea la siguiente (`Wnn-aa` y etiqueta) | `001_…sql:95`, `close-week…:366-395` | Nueva (10.2). **Servidor hecho (10.2a):** `WeeklyCycleOpener` y `afterClose()` para 10.3. **Pantalla (10.2):** «Iniciar la semana»; abrir la siguiente al cerrar llega con el cierre de la 10.3. **Hecho (10.3): al cerrar se abre la siguiente (`afterClose`, D-191).** |
| F-071 | Participa quien tiene la cuenta activa y se dio de alta antes del final de la semana | `AdminDashboard.tsx:18-31` | Nueva (10.2). **Servidor hecho (10.2a):** `WeeklyEligibility`. **Hecho (10.2).** |

**Informe de la weekly**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-072 | Generar, actualizar o regenerar el texto con IA, con aviso de que tarda y «Hay nuevos reportes» si está desactualizado | `ReportView.tsx:112, 703-729`, `App.tsx:2255-2316`, `generate-weekly-report` | Nueva (10.3) **Hecho (10.3): Job en la cola `ai` con progreso por Reverb, «Hay nuevos reportes» y regenerar (D-188 y D-190).** |
| F-073 | Informe estructurado: resumen global, riesgos del equipo y, por cliente, estado, resumen, próximos pasos, hitos y etiquetas | `ReportView.tsx:1057-1180`, `ClientReportCard.tsx` | Nueva (10.3) **Hecho (10.3): `LlmWeeklyReportGenerator` y la página del informe; además «General / Interno» (D-189).** |
| F-074 | Estado de los proyectos en la tarjeta de cada cliente: barra de progreso, desviación frente a lo esperado y tipo | `ClientReportCard.tsx:60-105`, `Project*Bar/Delta/KindChip` | Adaptar con datos nativos (10.3) **Hecho (10.3): `WeeklyProjectStatus` con bolsas, fees y presupuestos de Audax; barra, esperado y desviación en la tarjeta (D-188).** |
| F-075 | Riesgo por consumo (desde 85 %, Risk; más de 100 %, Blocked; fees por exceso) y nota para los clientes sin reportes | `pipeline.js:6-7, 73-135` | Nueva (10.3) **Hecho (10.3): `ReportPipeline` (D-188).** |
| F-076 | El informe sale siempre en español (traducción forzada) | `generate-weekly-report/index.ts:90-123` | Nueva (10.3) **Hecho (10.3): reescritura en español del texto final de cada cliente y del resumen global (D-188).** |
| F-077 | Editar el informe por cliente: estado, resumen, pasos e hitos con fecha | `ReportView.tsx:1478-1580`, `App.tsx:2478` | Nueva (10.3) **Hecho (10.3): «Editar informe», también el resumen global y los riesgos (D-190).** |
| F-078 | Ver los reportes originales de un cliente (ventana) | `ReportView.tsx:1642` | Nueva (10.3) **Hecho (10.3).** |
| F-079 | Índice de clientes con salto, también en móvil | `ReportView.tsx:198, 682, 779, 1196` | Nueva (10.3) **Hecho (10.3): índice lateral y, en el móvil, en un panel.** |
| F-080 | Filtro «Solo mis proyectos» | `ReportView.tsx:787-793` | Nueva (10.3) **Hecho (10.3): también en el PDF (`?mios=1`).** |
| F-081 | Pantalla completa | `ReportView.tsx:661, 846` | Nueva (10.3) **Hecho (10.3): Escape vuelve.** |
| F-082 | Copiar el texto de la weekly | `ReportView.tsx:628-649, 869-877` | Nueva (10.3) **Hecho (10.3): el texto final (`report_text`).** |
| F-083 | Descargar en HTML (respeta el filtro; selector de dónde guardar) | `ReportView.tsx:471-510`, `weeklyHtmlExport.ts` | Adaptar: PDF con Gotenberg, imprimir y HTML (10.3) **Hecho (10.3): PDF e impresión con la hoja de Audax (`ReportKind::Weekly`), Excel, CSV y HTML (D-192).** |
| F-084 | Generar o regenerar el audio por secciones | `ReportView.tsx:736-743`, `App.tsx:2318-2440`, `generate-audio-tts` | Nueva (10.3) **Hecho (10.3): guion con Gemini y locución por secciones en la cola `ai` (D-190).** |
| F-085 | Reproductor: reproducir y pausar, barra, velocidad, reiniciar e ir a la sección de un cliente | `ReportView.tsx:370-463, 936-1042` | Nueva (10.3) **Hecho (10.3).** |
| F-086 | Reproductor en cada tarjeta de cliente: solo suena uno a la vez y Escape cierra los menús | `InlineAudioPlayer.tsx`, `weeklyAudioSync.ts` | Nueva (10.3) **Hecho (10.3).** |
| F-087 | Descargar el audio | `ReportView.tsx:526-564` | Nueva (10.3) **Hecho (10.3).** |
| F-088 | Estado del equipo dentro del informe | `ReportView.tsx:918-923` | Nueva (10.3) **Hecho (10.3): la tira del equipo.** **Hecho (10.9b): en tiempo real (D-229).** |
| F-089 | Cerrar la semana: exige texto y audio y avisa de los pendientes | `ReportView.tsx:150-182, 902` | Nueva (10.3) **Hecho (10.3) (D-191).** |
| F-090 | Menú de acciones en móvil («Estructurales» y «Generativas y descargas») | `ReportView.tsx:800, 1235-1349` | Nueva (10.3) **Hecho (10.3).** |
| F-091 | Pinchar un cliente abre su ficha | `ReportView.tsx:1118` | Nueva (10.3) **Hecho (10.3): enlace a la ficha del cliente.** |

**Cierre y satisfacción**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-092 | Congelar los exentos al cerrar | `close-week…:24-40, 356` | Nueva (10.3) **Hecho (10.3): `freeze()` al cerrar (D-191).** |
| F-093 | Satisfacción por cliente con IA + estabilizador determinista | `close-week…:80-215`, `_shared/satisfaction.js` | Nueva (10.3) **Hecho (10.3): `SatisfactionUpdater` con el prompt del original y el estabilizador (D-191).** |
| F-094 | Foto de la satisfacción en cada weekly (para la gráfica) | `close-week…:230-255` | Nueva (10.3) **Hecho (10.3): en `client_satisfaction_snapshots` y en el informe.** |
| F-095 | Email «weekly cerrada» con enlace a todo el equipo activo | `close-week…:265`, `send-email-reminder` | Nueva (10.5) **Preparado (10.3): el evento `WeeklyCycleClosed` sale tras la satisfacción.** **Hecho (10.5): `weeklies.closed` a todo el equipo activo, una vez, con la plantilla y el enlace al informe (D-199).** |
| F-096 | Satisfacción por defecto de 50, con columna y orden en la lista de clientes | `clients.current_satisfaction`, `ClientView.tsx:204, 415` | Nueva (10.4). **10.1:** columna `clients.satisfaction_score` (50 por defecto) e histórico `client_satisfaction_snapshots` **Hecho (10.4): columna con la tendencia frente al cierre anterior y orden en `/clientes`.** |

**Exenciones, puntualidad y rachas**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-097 | Exención automática por vacaciones o ausencia que cubre el plazo | `src/lib/weekExcusal.ts` | Adaptar: ausencias aprobadas (10.2). **Servidor hecho (10.2a):** `WeeklyEligibility`. **Hecho (10.2).** **Hecho (10.9b): también «Estoy fuera» (D-228).** |
| F-098 | Al cambiar el estado de alguien, se actualiza su exención de la semana activa | `App.tsx:1460-1510` | Adaptar (10.2). **Servidor hecho (10.2a):** al vuelo; el contador se renueva (D-160). **Hecho (10.2).** |
| F-099 | Racha: semanas seguidas a tiempo; una semana exenta no la rompe, y la activa tampoco hasta el plazo | `MemberDashboard.tsx:40-80`, `ProfileView.tsx:36-83`, `TeamView.tsx:1272` | Nueva (10.2). **Servidor hecho (10.2a):** `WeeklyStreaks`. **Hecho (10.2).** |
| F-100 | A tiempo = enviado antes del final del día límite | `src/lib/weekTiming.ts:88-102` | Nueva (10.2). **Servidor hecho (10.2a):** `WeeklyTiming`. **Hecho (10.2).** |

**Recordatorios y notificaciones**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-101 | Reglas de recordatorio por email: varias, con día y hora, activables | `NotificationSettingsView.tsx:241-412`, `email_reminders` | Adaptar: grupo `weeklies` (10.5). **Hecho (10.5): reglas con día, hora de Madrid y canal (app, email o navegador) en «Avisos» (D-199).** |
| F-102 | Reglas de aviso en el navegador (día y hora) | `NotificationSettingsView.tsx:264-516`, `notificationScheduler.ts` | Adaptar a Web Push (10.5). **Hecho (10.5): reglas con el canal «Navegador» (Web Push, desde el servidor, sin la pestaña abierta).** |
| F-103 | Activar el permiso del navegador y ver su estado (no compatible o bloqueado) | `NotificationSettingsView.tsx:460-471` | Existe (Web Push). **10.5: «Avisos» avisa si Web Push no está configurado y enlaza a las preferencias de notificación.** |
| F-104 | Plantillas editables «automatic» y «manual»: asunto, cuerpo, variables `{nombre}` y `{semana}` y vista previa | `NotificationSettingsView.tsx:97-113, 578-690` | Nueva, en ajustes (10.5). **Hecho (10.5): con vista previa en vivo y «Restaurar por defecto» (D-199).** |
| F-105 | Plantilla «weekly_closed» con `{weekly_url}` | migración 023 | Nueva (10.5). **Hecho (10.5): también `{week_label}`, como el original.** |
| F-106 | Se recuerda solo a quien no ha enviado y está disponible y activo; nunca a los exentos | `send-email-reminder:174-207`, `NotificationSettingsView.tsx:709-713` | Nueva (10.5). **Hecho (10.5): con `WeeklyEligibility`, también en el envío manual y en el de los viernes (D-199).** **Hecho (10.9b): tampoco a quien está fuera ese día aunque vuelva antes del plazo (D-228).** |
| F-107 | Se envía desde el servidor aunque la app esté cerrada, cada 5 minutos y sin duplicados | `check-scheduled-reminders`, `.github/workflows/check-scheduled-reminders.yml` | Adaptar: scheduler (10.5). **Hecho (10.5): `weeklies:remind` cada 5 minutos, con la deduplicación del registro y los cambios de hora (D-201).** |
| F-108 | Registro de envíos (enviado o fallido, con el error) | `email_log` | Adaptar: auditoría (10.5). **Hecho (10.5): registro con en cola, enviado, fallido (con el error) y omitido (con el motivo), filtros y plazo de conservación; los envíos manuales, también en la auditoría (D-201 y D-202).** |
| F-109 | Envío manual a personas concretas, a todas o a las pendientes | `send-email-reminder:17, 174`, `emailHelpers.ts` | Nueva (10.5). **Hecho (10.5): a todas las pendientes o a las elegidas, con plantilla y canales (D-199).** |
| F-110 | Recordatorio individual desde Equipo | `TeamView.tsx:359-378, 738` | Nueva (10.5). **Hecho (10.5): «Recordar» en la lista de Equipo y en la ficha de persona.** |

**Estado de proyectos (OCR)**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-111 | Subir una o varias capturas (se procesan en orden, con espera si se agota la cuota) | `ProjectStatusView.tsx:469-540, 1285-1307` | Sustituida por datos nativos (G3) |
| F-112 | OCR por imagen con auditoría de cobertura y «reanalizar» priorizando lo que falta | `ProjectStatusView.tsx:548-585, 1393-1459`, `extract-project-statuses`, `audit-project-status-coverage` | Sustituida (G3) |
| F-113 | Preview editable: filtros (todos, faltantes o por revisar; tipo; cliente), orden (OCR, A-Z o Z-A), «revisado» y avisos de ilegible o inconsistente | `ProjectStatusView.tsx:1484-1700` | Sustituida (G3) |
| F-114 | Añadir un proyecto a mano, convertir un faltante en manual o ignorarlo | `ProjectStatusView.tsx:1011-1044, 1523-1572` | Sustituida (G3) |
| F-115 | Paso B: clientes nuevos, sugeridos o ambiguos (elegir, crear con icono, responsable y equipo, o ignorar) | `ProjectStatusView.tsx:1188-1240, 1844-1883`, `prepare-project-status-upload` | Sustituida: se crean en Audax (G3) |
| F-116 | Paso C: reactivar los clientes ocultos que reaparecen | `ProjectStatusView.tsx:1928-1938` | Sustituida (G3) |
| F-117 | Paso D: ocultar los clientes activos que no salen en la foto | `ProjectStatusView.tsx:1966-1981` | Sustituida: archivar el cliente (G3) |
| F-118 | Aplicar la foto (se bloquea si hay faltantes, filas sin revisar o duplicados) y resumen | `ProjectStatusView.tsx:1053-1175`, `apply-project-status-upload` | Sustituida (G3) |
| F-119 | Portfolio actual: cliente, código, tipo, presupuesto, consumido, esperado, desviación y «En línea con lo esperado», con filtros, orden y vista | `ProjectStatusView.tsx:2124-2366`, `projectStatus.ts` | Nueva: vista nativa «Estado de proyectos» con proyectos, bolsas y horas (10.4) **Hecho (10.4): `ProjectStatusBoard`, por cliente o en tabla, con filtros y orden (D-196).** |
| F-120 | Insignias de tipo de proyecto con recuento en clientes, equipo y resumen | `ProjectBadgeChip.tsx`, `projectStatus.ts:118-182` | Adaptar: tipo de facturación y código (10.4) **Hecho (10.4): `ProjectKindCode`; insignias en la lista y la ficha de clientes, el estado de proyectos y la ficha de persona.** |
| F-121 | Consumo esperado de un fee según los días laborables del mes | `projectStatus.ts:231-268` | Nueva, con `Capacity` y festivos (10.4) **Hecho (10.4): días laborables del mes sin fines de semana ni festivos de Audax.** **Hecho (10.9b): el fee se reconoce también por el código FE.** |
| F-122 | Bolsas leídas por OCR (antiguo, sin uso) | `extract-hour-banks` | Sustituida: bolsas reales (existe) |

**Clientes**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-123 | Cartera con buscador, «Mis proyectos», filtros por tipo de proyecto y por persona, y limpiar filtros | `ClientView.tsx:1494-1591` | Adaptar: lista de clientes de Audax + filtros (10.4) **Hecho (10.4): buscador, estado, tipo de proyecto, persona y «Mis proyectos» (D-197).** |
| F-124 | Orden por nombre, último reporte o satisfacción | `ClientView.tsx:121, 204-225, 410-418` | Adaptar (10.4) **Hecho (10.4).** |
| F-125 | Sección de clientes inactivos | `ClientView.tsx:1647` | Existe |
| F-126 | Alta y edición (icono emoji, nombre, responsable, miembros y etiquetas), solo ADMIN | `ClientView.tsx:245-320, 644-703` | Existe; Adaptar: añadir el icono (10.4) **Hecho (10.4): el icono (un emoji) en el alta y la edición.** **Hecho (10.9b): el responsable del cliente se puede elegir (D-232).** |
| F-127 | Activar, desactivar y eliminar un cliente | `ClientView.tsx:265-277, 718-722` | Existe |
| F-128 | Ficha: responsable con enlace a su perfil y estado | `ClientView.tsx:1019-1078` | Adaptar (10.4) **Hecho (10.4): quien gestiona más proyectos abiertos, con enlace a su ficha (D-195).** **Hecho (10.9b): el elegido a mano manda sobre el deducido (D-232).** |
| F-129 | Resumen IA estructurado (estado actual, satisfacción y tendencia, trabajo reciente, equipo, riesgos y estado de proyectos); generar o regenerar | `ClientView.tsx:352-380, 873, 1159-1175`, `StructuredAiSummary.tsx`, `generate-client-summary` | Nueva (10.4) **Hecho (10.4): pestaña «Resumen», en la cola `ai` y guardado hasta regenerarlo (D-194).** |
| F-130 | Historial: línea de tiempo semanal (resumen, estado, pasos e hitos) y detalle de los reportes de cada semana | `ClientView.tsx:893, 1227` | Nueva (10.4) **Hecho (10.4): pestaña «Historial».** **Hecho (10.9b): por páginas de medio año, sin tope (D-233).** |
| F-131 | Equipo: miembros, responsable, historial de cada uno en el cliente y «Analizar actividad del equipo» (IA) | `ClientView.tsx:329-350, 931, 1306-1402`, `analyze-team-activity` | Nueva (10.4) **Hecho (10.4): pestaña «Equipo», con el análisis de cada persona en su tarjeta.** **Hecho (10.9b): el histórico de cada persona, completo y por páginas (D-233).** |
| F-132 | Satisfacción: actual, semanas analizadas, tendencia semanal, mensual y trimestral, y gráfica | `ClientView.tsx:777-841, 1116, 1451-1478` | Nueva (10.4) **Hecho (10.4): pestaña «Satisfacción», con la gráfica (`--chart-1`) y su tabla.** |
| F-133 | Unirse o salir como colaborador | `App.tsx:1661-1830` | Adaptar (igual que F-034). **Servidor hecho (10.2a):** igual que F-034. **Pantalla (10.2):** «Unirme a proyectos» y «Dejar proyecto» en /weeklies; en la ficha de cliente, con la 10.4. **Hecho (10.4): también en la pestaña «Equipo» de la ficha.** |

**Equipo**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-134 | Lista con buscador y filtros (departamento, rol, estado del reporte y cliente) | `TeamView.tsx:1688-1769` | Adaptar: personas de Audax + filtros (10.4) **Hecho (10.4): `/equipo` (D-195).** **Hecho (10.9b): los filtros se conservan al volver de una ficha (D-233).** |
| F-135 | Columnas ordenables (nombre, email, puesto y estado del reporte) | `TeamView.tsx:392-434, 594-617` | Adaptar (10.4) **Hecho (10.4): también por departamento.** |
| F-136 | Estado del reporte por persona (Enviado, Enviado con retraso, Pendiente, Con retraso o No requerido) e insignias VACACIONES y AUSENTE | `TeamView.tsx:480-481, 607-612` | Nueva (10.4) **Hecho (10.4): el tipo de ausencia solo para quien puede saberlo (D-088).** |
| F-137 | Copiar el email | `TeamView.tsx:262, 698` | Nueva (trivial, 10.4) **Hecho (10.4).** |
| F-138 | Alta (nombre opcional, email, departamento, puesto y rol) con invitación | `TeamView.tsx:211, 1824-1890`, `create-user` | Existe |
| F-139 | Editar persona (nombre, estado hasta una fecha, departamento y rol) | `TeamView.tsx:228, 1477-1576` | Existe, con ausencias |
| F-140 | Eliminar persona conservando su historial | `TeamView.tsx:241, 1046` | Existe (baja = desactivar; corrige el fallo de A.3) |
| F-141 | Asignar proyectos (clientes) a una persona | `TeamView.tsx:327, 1589-1641` | Existe (miembros de proyecto) **Hecho (10.9b): «Asignar clientes» (varios a la vez) en la ficha, con la suscripción de la Weekly (D-233).** |
| F-142 | Ficha: racha y hábitos de envío (hora media, mañana, tarde o noche, y día más habitual) | `TeamView.tsx:485-530, 1272-1277` | Nueva (10.4) **Hecho (10.4): en la hora de Madrid (D-198).** |
| F-143 | Ficha: último reporte por cliente e historial completo por semanas (plegable) | `TeamView.tsx:168-175, 915-934, 1338-1430`, `get-user-report-history` | Nueva (10.4) **Hecho (10.4).** **Hecho (10.9b): «Ver histórico» por cliente e historial por páginas de un año, sin tope (D-233).** |
| F-144 | Ficha: «Resumen de desempeño (IA)» | `TeamView.tsx:268-290, 1026, 1310-1326`, `generate-performance-summary` | Nueva (10.4; quién lo ve, G2) **Hecho (10.4): solo el admin y sus responsables (D-147 y D-194).** |
| F-145 | Ficha: «Actividad por cliente (IA)», más los clientes que lidera y en los que colabora | `TeamView.tsx:292-320, 1440-1465`, `analyze-user-client-activity` | Nueva (10.4; G2) **Hecho (10.4): solo el admin y sus responsables (D-147 y D-194).** |

**Asistente IA**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-146 | Chat de preguntas con historial en la sesión, Intro para enviar y preguntas sugeridas | `KnowledgeBaseView.tsx:20-121, 189`, `query-knowledge-base` | Nueva (10.6) **Hecho (10.6): `/ia`, en la cola `ai`, con Reverb o sondeo y la conversación en la sesión (D-206).** |
| F-147 | Contexto: últimas semanas, reportes, clientes, personas, tareas y estado de proyectos | `query-knowledge-base:52-130` | Nueva, con datos de Audax (10.6) **Hecho (10.6): solo con lo que ve quien pregunta (D-205).** |

**Centro de ayuda**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-148 | Pestañas General, Tutoriales, Preguntas frecuentes y Sugerencias (en la URL) | `HelpView.tsx:1407-1420` | Nueva (10.7) **Hecho (10.7): `/ayuda?pestana=general|tutoriales|preguntas|sugerencias`; cada pestaña trae solo sus datos (D-208).** |
| F-149 | «Reportar bug»: abre una sugerencia en la categoría Bugs | `HelpView.tsx:847-856, 1423` | Nueva (10.7) **Hecho (10.7): botón en la cabecera de /ayuda que abre el formulario fijado en la categoría Bugs (`?nueva=bug`, D-210).** |
| F-150 | Novedades: una versión automática por semana (`V.serie.mes.semana`) con su lista de cambios; crear, editar y ocultar versiones; reordenar cambios | `HelpView.tsx:182-188, 859-896, 2049-2118`, `helpCenter.ts:636-660, 867-990` | Nueva (10.7) **Hecho (10.7): la versión de la semana se crea sola al abrir la ayuda (`V.serie.mes.semana`); crear, editar con sus cambios en orden (subir, bajar, añadir y quitar), ocultar y volver a mostrar (D-208).** |
| F-151 | Actualizaciones puntuales a mano (fecha, título, resumen y contenido) | `HelpView.tsx:1111-1154, 1837-1907` | Nueva (10.7) **Hecho (10.7): fecha, título, descripción breve y contenido con formato (D-208).** |
| F-152 | Estados de las novedades (nueva, en proceso o anterior), buscador y filtro | `HelpView.tsx:382-386, 1434-1494` | Nueva (10.7) **Hecho (10.7): nueva, en curso o anterior con la regla de WeeklySync; buscador en título, resumen, versión, contenido y cambios; filtros de tipo y estado.** |
| F-153 | «Me gusta» en las novedades y quién lo ha dado | `HelpView.tsx:1310-1321`, `help_update_likes` | Nueva (10.7) **Hecho (10.7): alternar, con los avatares y la lista de quién lo ha dado (D-208).** |
| F-154 | Editor de texto con formato (negrita, cursiva, título, subtítulo y divisor) | `HelpView.tsx:284-320`, `AppEditorToolbar.tsx` | Existe (`RichText`) **Hecho (10.7): el editor de Audax (RichText) en las actualizaciones, las respuestas de las preguntas frecuentes y las sugerencias.** **Hecho (10.9b): botones Título, Subtítulo y Divisor en la barra del editor.** |
| F-155 | Tutoriales en vídeo (hasta 200 MB, subida con progreso y ligados a una versión): vista previa, editar, sustituir el vídeo, eliminar y reordenar arrastrando | `HelpView.tsx:724-1100, 1602-1671, 1920-2150` | Nueva (10.7) **Hecho (10.7): hasta 200 MB, subida por trozos de 8 MB con progreso y reintento, ligado a una versión, reproductor con Range y URL firmada, editar, sustituir, eliminar y reordenar arrastrando (D-207).** |
| F-156 | Preguntas frecuentes por secciones: crear, editar, eliminar y reordenar secciones y preguntas; desplegar la respuesta | `HelpView.tsx:1160-1300, 1686-1810, 2170-2282` | Nueva (10.7) **Hecho (10.7): secciones y preguntas con crear, editar, eliminar y reordenar (arrastrar o subir y bajar), respuesta desplegable y buscador en todas (D-208).** |
| F-157 | Manual en PDF y enlace de soporte | `helpCenter.ts:828`, migración 026 | Nueva (10.7) **Hecho (10.7): PDF en el disco privado con URL firmada y enlace de soporte, editables por quien gestiona (D-208).** |
| F-158 | Solo ADMIN gestiona el contenido | `HelpView.tsx:445` | Nueva, con permiso (10.7) **Hecho (10.7): `manage-help` (admin y responsables, D-147); lo ve la plantilla; nunca un colaborador externo.** |

**Sugerencias**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-159 | Vistas Feedback y Roadmap | `HelpSuggestionsView.tsx:3088, 3368-3369` | Nueva (10.7) **Hecho (10.7): Roadmap (por defecto) y Feedback, en la URL (`?vista=`, D-210).** |
| F-160 | Tableros y categorías: crear, editar, ocultar y reordenar (ADMIN) | `HelpSuggestionsView.tsx:2401-2465, 2603-2757, 3742-3793, 4019-4035` | Nueva (10.7) **Hecho (10.7): «Gestionar categorías» con tableros y categorías: crear, editar, ocultar, eliminar (un tablero con sugerencias no) y reordenar arrastrando (D-210).** |
| F-161 | Crear sugerencia (título, categoría, detalle con formato, adjuntos y pegar archivos) con «sugerencias similares» | `HelpSuggestionsView.tsx:2205-2234, 3875-3967` | Nueva (10.7) **Hecho (10.7): tablero y categoría, detalle con formato y menciones, adjuntos elegidos o pegados y «sugerencias similares» al escribir el título (D-210).** **Hecho (10.9b): también vídeos MP4, MOV y WebM (D-235).** |
| F-162 | Feedback: buscador, filtro de categoría, orden Trending, Top o Nuevo, «cargar más» y resultados globales | `HelpSuggestionsView.tsx:278, 3498-3608`, `helpSuggestions.ts:30-42` | Nueva (10.7) **Hecho (10.7): búsqueda global (ignora tablero y categoría), Trending, Top, Nuevas o un estado del roadmap, categoría y «Cargar más» (D-210).** |
| F-163 | Votar y ver quién ha votado | `HelpSuggestionsView.tsx:714, 2155, 3285-3287` | Nueva (10.7) **Hecho (10.7): un voto por persona (índice único), alternando al momento, y la lista de quién ha votado.** |
| F-164 | Detalle con actividad, contexto y categoría; editar o eliminar (autor o ADMIN) | `HelpSuggestionsView.tsx:3069-3307` | Nueva (10.7) **Hecho (10.7): `/ayuda/sugerencias/{id}` con la actividad, el tablero y la categoría; editar o eliminar su autor o quien gestiona (con los adjuntos de sus comentarios).** |
| F-165 | Comentarios con respuestas anidadas, edición, borrado, adjuntos y menciones @ con autocompletado | `HelpSuggestionsView.tsx:1062, 1235-1300, 2247-2330, 2779-2983` | Nueva (10.7) **Hecho (10.7): respuestas anidadas, editar (autor), eliminar con sus respuestas (autor o quien gestiona), adjuntos y menciones @ con el editor de Audax; avisos de respuesta y mención (D-209).** **Hecho (10.9b): también vídeos (D-235).** |
| F-166 | Reacciones a los comentarios (Me gusta, Impulso, Siguiendo y Me encanta) | `HelpSuggestionsView.tsx:410-413, 2361` | Nueva (10.7) **Hecho (10.7): Me gusta, Impulso, Siguiendo y Me encanta; una por persona (la misma la quita, otra la cambia) y quién las ha puesto.** |
| F-167 | Moderación: estado (open, future, planned, building_now, beta o completed) con nota oficial e historial | `HelpSuggestionsView.tsx:2545-2554, 3307-3327`, `suggestion_status_events` | Nueva (10.7) **Hecho (10.7): estado con nota oficial e historial en la actividad; un cambio sin estado nuevo ni nota no hace nada; avisa a quien la propuso (D-209).** |
| F-168 | Roadmap por columnas de estado: arrastrar entre columnas y reordenar, filtro de estados, buscador y «cargar más» | `HelpSuggestionsView.tsx:803, 1930-1990, 3625-3727` | Nueva (10.7) **Hecho (10.7): columnas por estado ordenadas en el servidor (`position`); arrastrar entre columnas (cambia el estado) y dentro de una, con teclado y el menú «Mover a…»; estados visibles, categoría, buscador y «Cargar más» (D-210).** |
| F-169 | Categoría «Bugs» precargada | migración 034 | **Hecho (10.1):** la migración de sugerencias precarga el tablero «Sugerencias» y la categoría `bugs` |
| F-170 | Tiempo real en ayuda y sugerencias | `helpCenter.ts:814`, `helpSuggestions.ts:1925` | Adaptar (10.7) **Hecho (10.7): evento `help.changed` por el canal privado `help` (Reverb); la página recarga solo la pestaña abierta (D-212).** |

**IA transversal**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-171 | Transcripción conservadora: detecta silencio y muletillas y no inventa | `transcribe-audio:80-135` | Adaptar: Whisper + filtros (10.2). **Servidor hecho (10.2a):** `no_speech` (D-158). **Hecho (10.2).** |
| F-172 | Corrige los nombres de clientes y personas en la transcripción | `transcribe-audio:150-200` | Nueva: limpieza (10.2). **Servidor hecho (10.2a):** `CleanDictation`, ajuste `weekly_dictation_cleanup` (D-158). **Hecho (10.2): interruptor en los ajustes.** **Hecho (10.9b): encendida por defecto, solo en la weekly, con aviso si falla (D-227).** |
| F-173 | Telemetría de IA (modelo, tokens y coste) | `_shared/aiTelemetry.ts`, `ai_usage_events` | Nueva (10.3). **10.1:** tabla `ai_usage` y `AiUsageRecorder`; GeminiClient y GoogleTtsSynthesizer registran cada llamada con tokens, caracteres y coste. Falta la página (10.3) **Hecho (10.3): página «Uso de IA» (D-193).** |
| F-174 | Modelo configurable sin tocar código | `vertexAI.ts:8-13, 192` | **Hecho (10.1):** `GEMINI_MODEL` (y `GOOGLE_TTS_VOICE`) en `.env`, leídos por `config/services.php`; sin desplegar |

**Consola de plataforma**
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-175 | Tenants: alta, estado, plan, región y referencias de Supabase y Vercel; membresías | `PlatformView.tsx`, `platform-provision-tenant`, `-set-tenant-status`, `-get-tenants`, `-get-tenant-users` | **Descartado** |
| F-176 | Superadmins de plataforma (owner, admin y support; revocar) | `platform-save-admin` | **Descartado** |
| F-177 | Módulos activables por tenant (weeklys, tareas, presupuestos, estado de proyectos, ayuda y asistente) | `platform-set-modules`, `PlatformView.tsx:91-96` | **Descartado**. Útil con un solo tenant: ajuste «módulos activos». **Hecho (10.1):** ajuste `modules` (weeklies, estado de proyectos, ayuda, sugerencias y asistente), validado en `PUT /admin/ajustes`, middleware `module:` (404) y `config.modules` en las props; el interruptor en la pantalla de ajustes llega con 10.2. **Hecho (10.2): interruptores en /admin/ajustes (D-185).** |
| F-178 | Modo mantenimiento con ventana programada y banner global | `platform-set-maintenance`, `PlatformView.tsx:775-811` | **Descartado**. Útil: un banner global en ajustes; el mantenimiento ya lo cubre `artisan down`. **10.1:** ajuste `global_banner` ({message, tone}) validado y en `config.global_banner`; falta pintarlo y editarlo (10.2). **Hecho (10.2): aviso global en todas las páginas internas, editable en los ajustes (D-185).** |
| F-179 | Salud de Supabase, Vercel e IA | `platform-health` | **Descartado** (Audax tiene `/health` y el estado de las copias) |
| F-180 | Coste y tokens de IA de 30 días | `PlatformView.tsx:564-565`, `platform_ai_usage_summary` | **Descartado** como consola. Útil: página «Uso de IA» para el admin (con F-173, 10.3) **Hecho (10.3): `/admin/uso-ia`, de 7, 30 o 90 días, por función y modelo (D-193).** |
| F-181 | Registro de auditoría de la plataforma | `platform_audit_logs` | **Descartado** (Audax tiene auditoría) |

**No inventariadas (revisión de paridad 10.9b)**
Las encontraron los siete revisores de paridad del 06/10 (informes en el plan, 10.9b).
| ID | Funcionalidad | Origen | Destino |
|---|---|---|---|
| F-182 | Mapa «Constancia (últimas 12 semanas)» en el perfil y la ficha de persona | `ProfileView.tsx:87-216`, `TeamView.tsx:847-858, 1210-1267` | **Hecho (10.9b) (D-233).** |
| F-183 | Pantalla de error del navegador con «Recargar aplicación» | `ErrorBoundary.tsx:30-52` | **Hecho (10.9b) (D-234).** |
| F-184 | Pedir permiso de notificaciones del navegador al entrar | `App.tsx:857-873` | **Hecho (10.9b), distinto:** aviso amable con «Activar avisos» y «Ahora no», nunca el permiso sin un gesto (D-230). |
| F-185 | Insignia de estado (vacaciones o ausencia) sobre el avatar y «Cambiar estado» | `Layout.tsx:137-151, 437-447` | **Hecho (10.9b) (D-228).** |
| F-186 | Texto «tus reportes se marcarán automáticamente como completados» al marcarse fuera | `Layout.tsx:598-600` | **Hecho (10.9b) (D-228).** |
| F-187 | El estado vence solo al pasar la fecha de fin; sin fecha, indefinido | `App.tsx:224-277` | **Hecho (10.9b) (D-228).** |
| F-188 | Redirección y aviso con una ruta de detalle que no existe | `App.tsx:2825-2880` | **Distinto:** 404 de Audax con «Inicio» y «Volver». Pendiente P3 (ver el plan). |
| F-189 | Desplegables de filtro con buscador a partir de 10 opciones | `DesktopFilterSelect.tsx:28` | **Parcial:** buscador en cliente y proyecto de las tareas (D-231); los filtros de `/equipo` son nativos (se puede escribir para saltar). P3. |
| F-190 | Analítica de uso (Umami y Clarity) | `index.html:56-63` | **Descartado (10.9b):** sin terceros por el SPEC y el RGPD. |
| F-191 | Envío vacío para dar la semana por hecha sin texto | `WeeklyReportingInterface.tsx:362-384` | **Hecho (10.9b) (D-230).** |
| F-192 | Notas de la tarea en «Autocompletar» | `WeeklyReportingInterface.tsx:322-331` | **Hecho (10.9b) (D-231).** |
| F-193 | Nombres de las personas pendientes en la confirmación de cierre | `AdminDashboard.tsx:643-647` | **Hecho (10.9b).** |
| F-194 | Fecha de fin de la exención en el aviso del editor | `WeeklyReportingInterface.tsx:529` | **Hecho (10.9b) (D-228).** |
| F-195 | Un solo dictado a la vez | `WeeklyReportingInterface.tsx:624` | **Hecho (10.9b).** |
| F-196 | Nombres de clientes y personas para corregir la transcripción, siempre | `transcribe-audio:184-204` | **Hecho (10.9b):** limpieza encendida por defecto (D-227). |
| F-197 | Aviso «la limpieza falló, se insertó la transcripción literal» | `transcribe-audio:243-255` | **Hecho (10.9b) (D-227).** |
| F-198 | Selector de cliente con búsqueda con más de 8 clientes en «Nueva tarea» | `TaskView.tsx:650` | **Hecho (10.9b) (D-231).** |
| F-199 | Color de la participación (verde, naranja y rojo) | `WeeklysList.tsx:129-133` | **Hecho (10.9b).** |
| F-200 | Avisos «Tarea creada» y al marcarla hecha | `App.tsx:2570, 2622` | **Hecho (10.9b) (D-231).** |
| F-201 | Informes antiguos solo de texto, por secciones y con índice, también en el HTML | `ReportView.tsx:212-221, 1167-1176`, `weeklyHtmlExport.ts:169-235` | **Hecho (10.9b):** página, PDF/HTML y CSV por secciones con su índice. El audio de una semana así no se regenera (P3, ver el plan). |
| F-202 | Página del informe en tiempo real (equipo, «Hay nuevos reportes», reportes y aviso de cierre) | `App.tsx:952-973` | **Hecho (10.9b) (D-229).** |
| F-203 | Color de la satisfacción por tramos | `ClientView.tsx:386-391` | **Hecho (10.9b) (D-232).** |
| F-204 | Responsable y avatares del equipo en cada fila de la cartera | `ClientView.tsx:433-488` | **Hecho (10.9b) (D-232).** |
| F-205 | «Ver histórico» por cliente en la ficha de persona | `TeamView.tsx:874-904` | **Hecho (10.9b) (D-233).** |
| F-206 | Avatares del equipo en la cabecera de la ficha de cliente | `ClientView.tsx:1033-1056` | **Parcial:** el responsable en la cabecera; el equipo, en su pestaña y en la cartera. P3. |
| F-207 | Acciones en la fila de la cartera y del equipo (editar, ocultar, eliminar) | `ClientView.tsx:497-633`, `TeamView.tsx:2123-2167` | **Distinto:** en la ficha y en `/admin/usuarios` («Recordar» sí en la fila). P3. |
| F-208 | Vista en tarjetas en el móvil para clientes y equipo | `ClientView.tsx:533-637` | **Distinto:** tablas con desplazamiento horizontal. P3. |
| F-209 | Resumen IA en tarjetas por sección con icono | `StructuredAiSummary.tsx` | **Parcial:** el mismo contenido en Markdown. P3. |
| F-210 | Insignia «Pendiente activación» en el equipo | `TeamView.tsx:438-445` | **Parcial:** en `/admin/usuarios`. P3. |
| F-211 | Vídeos y cualquier fichero en las sugerencias y bugs | `helpSuggestions.ts:253-268` | **Hecho (10.9b):** vídeos MP4, MOV y WebM (D-235); el resto de tipos, los de D-037. |
| F-212 | Imágenes dentro de las novedades y el texto con formato importado | `HelpView.tsx:256-292` | **Distinto (D-208):** sin imágenes incrustadas; al importar, una imagen queda como enlace o su texto alternativo y no se pierde texto (10.9b). |
| F-213 | Reordenar las columnas del roadmap | `HelpSuggestionsView.tsx:446-498` | **Descartado (D-210).** |
| F-214 | Fecha de cada voto, «N comentarios» en la cabecera y última actividad en las tarjetas | `HelpSuggestionsView.tsx:727, 3121, 3295, 3595` | **Parcial:** fecha de creación. P3. |
| F-215 | Estados de «similares» (Buscando…, No hemos encontrado…) | `HelpSuggestionsView.tsx:3913-3954` | **Parcial.** P3. |
| F-216 | El administrador edita comentarios ajenos | `HelpSuggestionsView.tsx:2773` | **Descartado (D-210):** solo los borra. |
| F-217 | Recarga en vivo de la configuración de avisos y plantillas plegadas | `NotificationSettingsView.tsx:213-238, 581-603` | **Parcial:** gana quien guarda último; plantillas abiertas. P3. |
| F-218 | Reintento de un disparo de recordatorio fallido en la pasada siguiente | `check-scheduled-reminders:96-103` | **Parcial:** la cola reintenta; el disparo no se repite. P3. |
| F-219 | Número de factura de la bolsa y frase de la desviación a la vista en la cartera de proyectos | `ProjectStatusView.tsx:2053-2106` | **Parcial.** P3. |
| F-220 | Clientes archivados con proyectos abiertos en la cartera de proyectos | `ProjectStatusView.tsx:1923-1960` | **No.** P3. |

**Recuento:** 181 funcionalidades.
- Ya existen: 25.
- Se adaptan: 35.
- Nuevas: 101.
- Sustituidas por un equivalente nativo: 10 (F-022, F-111 a F-118 y F-122). Las confirma el propietario en G3.
- No aplica: 3.
- Descartadas: 7, todas de la consola de plataforma. Tres (F-177, F-178 y F-180) tienen un uso aprovechable con un solo tenant.

**Avance de la Fase 10** (05/10/2026):
- Hechas en la 10.1: F-016, F-169 y F-174.
- Hechas en la 10.2 (servidor y pantalla): 47, las marcadas «Hecho (10.2)».
- Hechas en la 10.3: 27, las marcadas «Hecho (10.3)» (F-035, F-070, F-072 a F-094, F-173 y F-180). F-095 queda preparada para la 10.5.
- Hechas en la 10.4: 22 (F-020, F-064, F-096, F-119 a F-121, F-123, F-124, F-126, F-128 a F-132, F-134 a F-137 y F-142 a F-145), y se completan F-001, F-028, F-067 y F-133. Todas llevan «Hecho (10.4)».
- Ya no queda ninguna con la pantalla a medias.
- Hechas en la 10.5: 12, las marcadas «Hecho (10.5)» (F-037, F-095, F-101, F-102 y F-104 a F-110), y se completa F-010 con «Avisos». F-103 ya existía (Web Push).
- Hechas en la 10.6: las marcadas «Hecho (10.6)» (F-006, F-055 a F-063, F-146 y F-147), y se completa F-041.
- Hechas en la 10.7: 21, las marcadas «Hecho (10.7)» (F-148 a F-153, F-155 a F-168 y F-170); F-154 se usa en las nuevas pantallas y se completa F-010 con «Ayuda». Ya no queda ninguna F pendiente de una entrega de pantallas: solo la migración (10.8) y el cierre (10.9).
- **Revisión de paridad (10.9b):** las parciales pasan a hechas (las marcadas «Hecho (10.9b)», D-227 a D-235) y se añaden 39 funcionalidades que no estaban en la lista (F-182 a F-220): 22 hechas, 3 descartadas a propósito (analítica de terceros, reordenar columnas del roadmap y editar comentarios ajenos) y 14 distintas o parciales de prioridad baja (P3), con su motivo en el plan.

---

## B. Edge Functions (30 en el repo y 32 desplegadas; `platform-get-tenants` y `platform-get-tenant-users` solo están desplegadas)
Toda la IA pasa por Vertex AI con una cuenta de servicio (`GCP_SERVICE_ACCOUNT_JSON`, `us-central1`) y el modelo por defecto **`gemini-2.5-flash`** (o el secreto `GEMINI_MODEL`). Ninguna fija su propio modelo. La locución usa **Google Cloud TTS** con `GOOGLE_CLOUD_API_KEY`.

En la tabla, **Destino** dice qué es cada función en Audax: Job (trabajo de la cola de Laravel), Servicio (clase de dominio), Ya existe, Sobra (no se porta) o Nada (se descarta).

| Función | Qué hace | IA (uso) | Entrada → salida | Quién o cuándo | Destino |
|---|---|---|---|---|---|
| transcribe-audio | Detecta voz y transcribe literalmente; en el modo `weekly-report` limpia y corrige nombres | Gemini con audio y JSON, y Gemini de texto para la limpieza | `audioBase64, mimeType, mode, clientNames, userNames` → `rawTranscription, cleanedText, warning` | Al dictar el reporte o una tarea | **Whisper local**, con limpieza opcional por Gemini |
| generate-weekly-report | Informe por lotes por cliente, fusión y resumen global, con traducción forzada al español | Gemini JSON (lotes, fusión y global) | `weekId` → `report` | ADMIN, al pulsar «Generar» | Job |
| close-week-and-update-satisfaction | Cierra, congela los excusados, crea la siguiente semana y, en segundo plano, satisfacción y email | Gemini JSON (delta de satisfacción) | `weekId` → `nextWeekNumber` | ADMIN, al pulsar «Cerrar» | Servicio + Job |
| generate-audio-tts | Guion por secciones y TTS; modos `scripts`, `synthesize-section` y `full` (antiguo) | Gemini (guion y traducción) + Cloud TTS `es-ES-Journey-F` | Informe estructurado → MP3 en base64 por sección | ADMIN, al pulsar «Generar audio» | Job |
| extract-tasks | Tareas accionables para la persona a partir de la weekly completa | Gemini JSON | `weekId` → `tasks[]` | Persona, en Mi espacio | Job, opcional (10.6) |
| generate-client-summary | Resumen histórico del cliente | Gemini | `clientId` → `summary` | Ficha de cliente | Job (10.4) |
| analyze-team-activity | Qué ha hecho cada miembro en un cliente | Gemini | `clientName, memberIds` → `summaries` | Ficha de cliente (pestaña Equipo) | Opcional (G3) |
| analyze-user-client-activity | Actividad de una persona por cliente | Gemini | `userId` → `summaries` | Ficha de persona | Opcional (G3) |
| generate-performance-summary | Resumen del desempeño de una persona en sus últimas 8 weeklies | Gemini | `userId` → `summary` | Ficha de persona | Opcional (G3, RGPD) |
| get-user-report-history | Histórico de reportes de una persona | No | `userId` → historial | Ficha de persona | Consulta normal |
| query-knowledge-base | Asistente de preguntas con contexto recortado | Gemini | `query` → `response` | `/ia` | Opcional (G3) |
| extract-project-statuses | OCR de las capturas de estado de proyectos | Gemini con imagen y JSON | Imagen → filas | Estado de proyectos | **Sobra** (Audax tiene los datos reales) |
| audit-project-status-coverage | Comprueba que el OCR no se deja filas | Gemini con imagen | Imagen → filas que faltan | Estado de proyectos | **Sobra** |
| prepare-project-status-upload | Empareja los clientes del OCR (los 3 mejores candidatos) | No | `projects, snapshotDate` → preview | Estado de proyectos | **Sobra** |
| apply-project-status-upload | Crea o reactiva clientes y guarda la foto | No | Preview confirmada → upload | Estado de proyectos | **Sobra** |
| extract-hour-banks | OCR de bolsas (antiguo; el front ya no lo llama) | Gemini con imagen | Imagen → bolsas | Nadie | **Nada** |
| analyze-client-satisfaction | Satisfacción con un solo reporte (antiguo, sin uso) | Gemini | — | Nadie | **Nada** |
| generate-audio-summary | TTS antiguo de un solo bloque (sin uso) | Gemini y Cloud TTS | — | Nadie | **Nada** |
| check-scheduled-reminders | Comprueba las reglas de recordatorio y lanza los emails | No | — | **GitHub Actions cada 5 minutos** (`ws:.github/workflows/check-scheduled-reminders.yml`) | Scheduler de Laravel |
| send-email-reminder | Envía con Resend las plantillas automatic, manual o weekly_closed a destinatarios por modo | No | `templateId, weekId, recipientMode, userIds, triggerKey` | Lo llaman check-scheduled-reminders, el cierre y el envío manual del ADMIN | Notificaciones + cola mail |
| save-notification-settings | Guarda reglas y plantillas (RPC) | No | — | `/notificaciones` | Ajustes |
| create-user | Alta de persona con invitación por Resend | No | — | ADMIN, en Equipo | Ya existe (`UserInviter`) |
| resolve-user-access | Vincula la identidad de Google con el usuario y deduplica | No | — | Al iniciar sesión | Sobra |
| complete-user-profile | Completa el perfil obligatorio | No | — | Onboarding | Sobra |
| platform-* (8) | Tenants, módulos, mantenimiento, admins y salud (APIs de Supabase y Vercel) | No | — | Superadmin | **Nada** |

**Uso real de IA** (`ai_usage_events`, 1.976 llamadas): sobre todo transcripción (unas 725), generación de la weekly (unas 625 entre lotes y resumen global), OCR de estado de proyectos (unas 380) y TTS (unas 240 secciones).

---

## C. Modelo de datos (Supabase `public`, sin las tablas de plataforma)
Recuentos de `pg_stat_user_tables` del 05/10/2026.

| Tabla | Filas | Columnas clave y relaciones |
|---|---|---|
| users | 13 | `id`, `name`, `email` (único), `canonical_email`, `role` (ADMIN/MEMBER), `department` (enum: Marketing, Diseño, Desarrollo, Gestión, RRHH, Dirección), `position`, `status` (AVAILABLE/VACATION/ABSENT; ahora 10 y 3), `status_end_date`, `joined_at`, `account_status`, `auth_user_id`, `google_subject` |
| user_identities | 33 | `user_id`→users, `provider` y `provider_subject`, `email_normalized` |
| clients | 50 (30 activos) | `name`, `owner_id`→users, `icon` (emoji), `active`, `current_satisfaction` (0-100, por defecto 50) |
| client_team_members | 136 | (`client_id`, `user_id`): colaboradores |
| client_projects | 37 | (`client_id`, `tag`, `count`): insignias de tipo de proyecto, derivadas de la foto |
| week_cycles | 28 (27 cerradas y 1 activa; del 23/03 al 09/10/2026) | `number` (`Wnn-aa`), `label`, `start_date`, `end_date`, `deadline_date`, `status`, `is_generated`, `submission_count_at_generation`, `final_report_text` (Markdown), `structured_report` (JSONB: `globalSummary`, `teamRisks`, `clientUpdates[]` con `clientId`, `satisfactionScore`…, y `audioSections[]`), `final_report_audio_url`, `excused_user_ids` (uuid[]) |
| weekly_submissions | 267 | (`user_id`, `week_id`) únicos, `text_content`, `audio_url` (siempre vacío), `submitted_at` |
| client_report_entries | 1.695 | `submission_id`→submissions, `client_id`→clients, `text`, `audio_url` (siempre vacío) |
| weekly_submission_drafts / `_entries` | 27 / 196 | Borrador por persona y semana, y por cliente |
| tasks | 36 (todas DONE) | `description`, `status`, `priority`, `due_date`, `assignee_id`, `assigner_id`, `client_id` (**sin proyecto**), `week_id`, `archived`, `notes` |
| hour_banks | 13 (de 9 clientes) | `client_id`, `name`, `total_capacity`, `billable_consumed`, `total_consumed` (antiguo, del OCR; el front no lo lee) |
| project_status_uploads / `_entries` | 64 / 1.738 (49 actuales) | Foto: `client_id`, `project_code` (`BH1`, `FE2`, `WE3`…: 20 BH, 10 WE, 6 FE, 4 PR, 3 EC, 2 AD, 2 AM, 1 GE y 1 BR), `project_kind`, `budget_hours`, `consumed_hours`, `expected_consumed_*`, `invoice_number` (código F, en 10 BH), `is_current` |
| email_reminders / web_notification_reminders | 2 / 3 | `day_of_week` (0 es domingo), `time` (`HH:MM`), `enabled` |
| email_templates / email_log | 3 / 602 | Plantillas `automatic`, `manual` y `weekly_closed`; registro con `trigger_key` y `week_id` |
| help_settings, help_tutorials, help_faq_sections, help_faqs, help_releases, help_release_changes, help_manual_updates, help_update_likes | 0, 4, 7, 28, 7, 5, 1, 0 | Centro de ayuda |
| suggestion_boards, `_categories`, `_posts`, `_votes`, `_comments`, `_comment_reactions`, `_post_attachments`, `_comment_attachments`, `_status_events` | 2, 4, **1**, 1, 0, 0, 0, –, 5 | Sugerencias: casi sin uso |
| ai_usage_events | 1.976 | Telemetría: modelo, función, tokens y coste |
| user_merge_audit | 0 | — |

**Storage:**
- `audio-submissions/weekly-reports/…`: 993 MP3 de TTS, **238 MB**,
- `help-content/tutorials`: 4 vídeos, **135 MB**,
- `suggestion-attachments`: 2 ficheros, 103 kB.

No hay audios de personas guardados. **No hay pg_cron**: lo programado va por GitHub Actions.

---

## D. Correspondencia con Audax Proyectos

### D.1 Entidad por entidad
| WeeklySync | Audax | Qué hacer |
|---|---|---|
| users | `User` (único por `lower(email)`) | **Ya existe.** Casar por email (D.3). No se migran `role`, `department`, `position` ni `status`: mandan los de Audax. |
| Rol ADMIN/MEMBER | `Role` (admin, department_manager, employee, collaborator, client) + `Permission` | Permiso nuevo **`manage-weeklies`** (generar, editar, cerrar, plazo y recordatorios). Por defecto solo para admin; ¿también responsables? (G2). Participan los internos activos (admin, responsable y empleado). El portal nunca ve weeklies. |
| departments (enum de 6) | `Department` (tabla; SPEC: Diseño, Desarrollo y Marketing) | **Conflicto:** Gestión, RRHH y Dirección no existen en Audax. Se usa el departamento de Audax y se descarta el de Weekly. `position` no existe en Audax: se añade **`users.job_title`** (F-026, F-027 y F-138). |
| status VACATION/ABSENT + `status_end_date` | `Absence` (aprobadas) + `Capacity` + `Holiday` | **Ya existe, y mejor.** Excusado = la capacidad entre el lunes y el plazo es 0, o una ausencia aprobada cubre el día del plazo. Más una **exención manual** (tabla `weekly_exemptions`) para lo puntual, que en Weekly se marcaba uno mismo sin aprobación. Al cerrar se guarda la foto. |
| `joined_at` | `users.created_at` o la fecha de alta | Participa si se dio de alta antes o igual al final de la semana y está activo. |
| clients | `Client` (`is_active`) | **Ya existe.** Casar por nombre normalizado (D.3). `icon` (emoji) pasa a una columna nueva, **`clients.icon`** (F-126). `current_satisfaction` pasa a la columna nueva **`clients.satisfaction_score`** (smallint, 50). |
| `owner_id` + client_team_members | `Project.owner_user_id` + `project_members` | **No se migra.** «Mis clientes» de la weekly = clientes de mis proyectos activos **más** los clientes donde he imputado horas esa semana (`TimeEntry`), con opción de añadir otro. «Unirme» y «dejar» (F-034 y F-133) son una suscripción de la Weekly (`weekly_client_subscriptions`, D-221, que sustituye a D-156), sin acceso a los proyectos; `client_team_members` se importa ahí. Responsable del cliente = gestor de su proyecto principal. |
| client_projects (insignias) | `Project` (`billing_type`, `code`) | Sobra: se derivan de los proyectos reales. |
| project_status_* (foto por OCR) | `Project.budget_minutes`, `HourBank` + `HourBankLedger`, `TimeEntry` | **Conflicto resuelto por diseño:** los códigos BH1, FE2… son los de las listas de ClickUp (D-135), que Audax ya importó como proyectos `CLIENTE-FE1` y bolsas con `invoice_reference`. El consumo real sale de `HourBankLedger` y de las horas. La **necesidad** se cubre con una vista nativa «Estado de proyectos» (F-119 a F-121). La **subida por OCR** (F-111 a F-118) se sustituye, a confirmar en G3; no se migra la foto. Lo esperado de un fee se calcula con `Capacity` (festivos reales). |
| hour_banks (13, del OCR) | `HourBank` (bolsa real por proyecto, en minutos) | **Conflicto:** nombres iguales, datos distintos y obsoletos. **Se descartan.** El riesgo y el bloqueo del informe usan `HourBankLedger` (consumo, exceso y umbrales) y `HourBanksAtRisk`. |
| tasks (36, todas hechas, por cliente y sin proyecto) | `Task` (exige proyecto; se escribe con `TaskWriter`) | **Conflicto:** la tarea de Weekly no tiene proyecto. Las 36 se migran como tareas hechas si el cliente tiene un proyecto que case; si no, a un CSV de archivo. Las funciones F-055 a F-063 se mantienen en Mis tareas, más un archivado personal. «Tareas sugeridas por IA» crea `Task` reales en un proyecto del cliente, **siempre revisadas** antes de crearlas (la persona elige el proyecto si hay varios). |
| week_cycles | — | **Nueva:** `weekly_cycles` (`iso_week` con `App\Domain\Time\Week`, fechas, `deadline_date`, estado, `generated_at` y `generated_by`, `submission_count_at_generation`, `report` jsonb, `report_edited_at`, `closed_at` y `closed_by`). Única activa con índice parcial. |
| weekly_submissions + drafts | — | **Nueva:** `weekly_submissions` (`user_id`, `weekly_cycle_id`, `submitted_at` nulo mientras es borrador). Se fusionan borrador y envío. |
| client_report_entries + draft_entries | — | **Nueva:** `weekly_entries` (`submission_id`, `client_id`, `project_id` nulo, `body`, `source` text o audio). |
| `excused_user_ids` | — | **Nueva:** `weekly_exemptions` (ciclo, persona, motivo `absence` o `manual`, quién). |
| `structured_report.audioSections` + MP3 | Almacenamiento de Laravel (como los adjuntos) | **Nueva:** `weekly_audio_sections` (ciclo, `key`, `kind`, `client_id`, guion, `path`, `duration_ms`). |
| satisfacción (actual e histórico en el JSON) | — | **Nueva:** `client_satisfaction_snapshots` (cliente, ciclo, puntuación, delta, regla, motivo y confianza) + `clients.satisfaction_score`. |
| transcripción con Gemini | `TranscriptionService` + `WhisperServerTranscriber` + `AudioTranscription` | **Ya existe.** Pero `audio_transcriptions.message_id` es único y obligatorio: hay que hacerlo **polimórfico** (`transcribable`) o crear un camino propio para la weekly que llame a `TranscriptionService` en un Job. Whisper es asíncrono: la interfaz muestra «Transcribiendo…» e inserta el texto con Reverb o consultas periódicas. |
| email_reminders / web_notification_reminders | `NotificationCatalog` (app, email y push), `PushSubscription`, `Setting`, scheduler | **Ya existe la base.** Grupo nuevo `weeklies` con los eventos `weekly.reminder`, `weekly.closed` y `weekly.deadline_extended`, y reglas día y hora en `/admin/ajustes`. Push real en lugar del `setInterval`. |
| email_templates / email_log | Notificaciones por la cola `mail` + auditoría | Las **plantillas editables** «automatic» y «manual» (asunto, cuerpo y variables con vista previa) y «weekly_closed» se guardan en `settings`, con textos por defecto en `lang/es/weeklies.php` (F-104 y F-105). El registro de envíos pasa a la auditoría, con deduplicación por `Cache::add` (F-108). |
| Exportar a HTML (`weeklyHtmlExport.ts`) | `Reports/Pdf` con Gotenberg + `audax-doc.css` (D-140), envíos programados (D-141) | **Ya existe.** PDF e impresión de la weekly; `ReportKind::Weekly` para enviarla por correo o programarla. |
| help_* / suggestion_* | — | **Nuevas** (requisito: no se pierde nada; F-148 a F-170): `help_*` y `suggestion_*` con los mismos campos. Adjuntos con `Attachment` y `AttachmentStorage`, texto con formato con `RichText` y menciones con el patrón de `TaskMentions`. Hoy casi sin uso: 1 propuesta, 28 FAQ y 4 vídeos. |
| ai_usage_events | — | **Nueva (ligera):** `ai_usage` (función, modelo, tokens y coste) para vigilar el gasto. |

### D.2 Conflictos y cómo resolverlos
| Conflicto | Propuesta |
|---|---|
| Bolsas de Weekly frente a bolsas reales | Se descartan las de Weekly. El informe lee `HourBankLedger`, que es la única fuente del consumo y el exceso (CLAUDE.md). |
| Tareas de Weekly sin proyecto | No se migran. Las sugeridas se crean con `TaskWriter`, en un proyecto elegido y revisadas. |
| Departamentos distintos | Manda Audax. Gestión, RRHH y Dirección no se crean, salvo que el propietario lo pida. |
| Ausencias sin aprobación en Weekly | Ausencias aprobadas de Audax + exención manual por la weekly (con permiso). |
| Reporte por cliente frente a trabajo por proyecto | Se mantiene **por cliente**, como ahora, con `project_id` opcional para afinar. Al prompt se le añade el contexto real: las horas de la semana por cliente y proyecto, y el estado de las bolsas. |
| Recordatorio de los viernes ya existente (`time:remind-week`, viernes a las 13:00, D-123) | Evitar dos avisos: un solo recordatorio de viernes con dos partes (horas y weekly) o reglas separadas. Se decide en el contrato (D-145 o siguiente). |
| «Sin IA externa» (SPEC §2 y §12) | Decisión del propietario (G1). Propuesta: el audio, siempre con Whisper local; solo el **texto** va a Gemini, con el plan de pago (Google no entrena con esos datos). |
| Resúmenes de desempeño de personas con IA (F-144 y F-145) | Se mantienen, pero solo los ven el admin y los responsables de esa persona (`canSeeAbsencesOf`, D-088), nunca un compañero; con aviso en el texto RGPD (G2). |

### D.3 Cómo casar usuarios y clientes
- **Usuarios:** `lower(trim(email))` de Weekly (`canonical_email` y también `user_identities.email_normalized`) contra el índice `lower(email)` de Audax.
  - **No casarían:**
    - personas de Weekly que ya no están o que aún no tienen alta en Audax (la **lista de empleados sigue pendiente**, D-030),
    - correos personales de Google que no coinciden con el corporativo (Weekly admite varias identidades por persona),
    - el admin de plataforma,
    - las cuentas `PENDING`.
  - **Solución:** un fichero de correspondencias (`weeklysync-people.json`, como `PeopleFile` del importador de ClickUp) y un informe de prueba (`--dry-run`). Los autores sin pareja se crean como **inactivos** o se descartan (G4).
- **Clientes:** nombre normalizado (`Str::ascii`, minúsculas, sin emoji ni signos, sin «S.L.» o «SL»).
  - **Segunda señal fuerte:** los proyectos actuales de la foto (`cliente + BH1/FE2…` e `invoice_number` F) contra los proyectos y bolsas importados de ClickUp (`import_refs`, `code CLIENTE-FE1`, `hour_banks.invoice_reference`).
  - **No casarían:** clientes creados solo en Weekly (prospectos, «Audax» interno), renombrados o duplicados (ya los hubo: hay `user_merge_audit` y el paso B del OCR).
  - **Solución:** fichero de correspondencias y, si no, crear un cliente inactivo sin proyectos.
- **Dentro del JSON:** al importar, `structured_report.clientUpdates[].clientId`, `audioSections[].clientId` y las rutas de audio se **reescriben** con los IDs de Audax.

---

## E. Qué sobra o se simplifica
| Hoy en WeeklySync | En Audax |
|---|---|
| Multi-tenant, `tenants`, `platform_*`, `PlatformView`, 8 funciones `platform-*`, health de Supabase y Vercel | **Fuera** (F-175 a F-181). Se aprovechan, para un solo tenant: módulos activos (ajuste), banner global y página «Uso de IA» |
| Auth de Supabase con Google OAuth, `user_identities`, `resolve-user-access`, `complete-user-profile`, merge de identidades | Login de Audax (contraseña y 2FA), invitaciones y `/admin` de personas |
| RLS (`002`, `007-010`, `014`, `015`) | `WeeklyPolicy`, `WeeklySubmissionPolicy` y `ClientPolicy` + gates. Se aprovecha para cerrar los análisis de IA, que hoy no exigen ser ADMIN |
| Edge Functions en Deno + Vertex con una cuenta de servicio firmada a mano | Servicios de `App\Domain\Weeklies` + Jobs de Horizon (cola `ai`, de 1 en 1, con `heavy`) + `GeminiClient` con clave en `.env` |
| GitHub Actions cada 5 minutos + `setInterval` del navegador | Scheduler de Laravel (`weeklies:remind` cada 5 minutos, Europe/Madrid) + Web Push |
| Resend + plantillas en Supabase + `email_log` | Notificaciones con la cola `mail` (SMTP pendiente, D-030) + plantillas editables en `settings` + auditoría |
| Supabase Realtime (10 canales) | Reverb solo donde aporta (transcripción lista, estado del equipo); el resto, recarga de Inertia |
| Supabase Storage | Disco de Laravel (privado, con URLs firmadas) |
| OCR de estado de proyectos (4 funciones, 2 vistas, `projectStatus*.ts`, unas 3.500 líneas) | **Sustituido** por una vista nativa con datos reales de proyectos, bolsas y horas (F-119). La subida de capturas no se porta salvo que G3 diga lo contrario |
| `hour_banks` y `extract-hour-banks`, `analyze-client-satisfaction`, `generate-audio-summary`, `analyzeClientWeeklyStatus` (vacía) | Código muerto: fuera |
| `jspdf` y `html2canvas` (en `package.json`, sin importar) y la exportación HTML | PDF con Gotenberg + imprimir (D-140) |
| Departamento, puesto, estado y unirse a clientes | Datos de Audax: departamentos, `job_title` nuevo, ausencias + exención y miembros de proyecto |
| Componentes propios `App*` (selector, fecha, tooltip…) y Tailwind propio | shadcn y los tokens de Audax (D-137), radio de 3 px y DM Sans |
| Borrador y envío en tablas separadas | Una sola tabla con `submitted_at` nulo |

---

## F. Propuesta de entregas
Numeradas como Fase 10, o la siguiente libre: la rama `fase-10` ya existe. Estimación relativa: S = 1, M = 2, L = 3 y XL = 5.

| Entrega | Contenido | Tamaño | Riesgos |
|---|---|---|---|
| **10.1 Contrato** | <ul><li>Decisiones D-145 y siguientes: IA, excusas, roles, recordatorio de los viernes y alcance.</li><li>Migraciones: `weekly_cycles`, `weekly_submissions`, `weekly_entries`, `weekly_exemptions`, `weekly_audio_sections`, `client_satisfaction_snapshots`, `clients.satisfaction_score` y `ai_usage`.</li><li>Modelos, enums, `Permission::ManageWeeklies` y políticas.</li><li>Interfaces: `WeeklyEligibility`, `WeeklySubmissionWriter`, `WeeklyReportGenerator`, `SatisfactionStabilizer` (puerto a PHP de `satisfaction.js` con fixtures compartidos en `tests/fixtures`), `LlmClient` con `GeminiClient` y `FakeLlm`, y `SpeechSynthesizer`.</li><li>Rutas `/weeklies…` y tipos TS.</li><li>`users.job_title`, `clients.icon` y ajuste de «módulos activos».</li></ul> F-016, F-026, F-174 y F-177 | M | Cerrar bien las reglas de excusa y de participación |
| **10.2 Semana y envío** | <ul><li>Ciclo: apertura automática el lunes por el scheduler, plazo ampliable y cierre manual.</li><li>«Mi weekly»: clientes sugeridos por proyectos y horas de la semana, borrador autoguardado, enviar, editar hasta el cierre y fuera de plazo.</li><li>Dictado con Whisper: `AudioTranscription` polimórfica + limpieza opcional.</li><li>Exenciones, estado del equipo, rachas y tarjeta en Inicio (D-138).</li></ul> F-001 a F-054 (las de 10.2), F-065 a F-071, F-097 a F-100, F-171 y F-172 | L | Latencia de Whisper (CPU sin AVX2, cola compartida con el chat); cambio de experiencia de síncrono a «Transcribiendo…» |
| **10.3 Informe, audio y cierre** | <ul><li>`GeminiClient` (HTTP, JSON con `responseSchema`, reintentos 429/5xx y telemetría).</li><li>Job del informe: lotes, fusión y global, con los prompts de `pipeline.js` y el contexto real de `HourBankLedger` y las horas; progreso por Reverb.</li><li>Editar, «desactualizado» y regenerar.</li><li>Job de TTS por secciones y reproductor.</li><li>Cierre con Job de satisfacción + `SatisfactionStabilizer`.</li><li>PDF e impresión con Gotenberg y `ReportKind::Weekly`.</li><li>Página «Uso de IA».</li></ul> F-035, F-072 a F-095, F-173 y F-180 | XL | Coste y cuotas de Gemini; tiempos largos (hoy, 10 minutos de límite) → Job con su límite y su memoria en Horizon |
| **10.4 Clientes, personas y estado de proyectos** | <ul><li>Ficha de cliente: pestañas Resumen IA, Historial, Equipo con análisis IA y Satisfacción con tendencias; columna, orden y filtros nuevos en la lista; icono.</li><li>Ficha de persona: racha, hábitos de envío, historial por semanas y resúmenes IA de desempeño y de actividad por cliente (restringidos).</li><li>Filtros de equipo y estado del reporte.</li><li>Vista nativa «Estado de proyectos»: presupuesto, consumo, esperado y desviación (F-064 y F-119 a F-121).</li></ul> F-020, F-028, F-096, F-120 a F-145 | L | Permisos: la plantilla ve todo (D-021) y los colaboradores no |
| **10.5 Avisos** | Grupo `weeklies` en el catálogo: recordatorio (app, email y push), weekly cerrada y plazo ampliado. Reglas en `/admin/ajustes`. Comando `weeklies:remind` cada 5 minutos con deduplicación. Unificación con `time:remind-week`. Plantillas editables con vista previa, envío manual e individual y registro. F-037, F-095 y F-101 a F-110 | M | **Hecho (10.5):** las reglas, en la pestaña «Avisos» de `/weeklies` (D-199); el SMTP ya funciona (relé de Google) |
| **10.6 Tareas y asistente** | Pestaña de tareas de Mi espacio agrupada por cliente, archivado personal, notas con dictado y tareas sugeridas por IA (revisadas antes de crearlas con `TaskWriter`). Asistente `/ia` con preguntas sugeridas y contexto de Audax (F-006, F-055 a F-063, F-146 y F-147) | M | Alucinaciones: siempre con revisión humana; el asistente solo con datos que la persona puede ver |
| **10.7 Centro de ayuda y sugerencias** | Todo F-148 a F-170: novedades con versiones automáticas y «me gusta», actualizaciones puntuales, tutoriales en vídeo (200 MB), FAQ por secciones, manual y soporte. Sugerencias con tableros, categorías, votos, comentarios anidados con adjuntos, menciones y reacciones, moderación de estados y roadmap con arrastrar | XL | Mucha interfaz (6.700 líneas en origen) para poco uso real; vídeos grandes (límite de subida y disco) |
| **10.8 Migración de datos** | <ul><li>`app:import-weeklysync --dry-run`: lee la base de Supabase en solo lectura (cadena de conexión o volcado JSON) y los ficheros de correspondencias de personas y clientes.</li><li>Idempotente con `import_refs` (fuente `weeklysync`).</li><li>Importa ciclos, envíos, entradas, exenciones, satisfacción (actual e histórico del JSON) y audios TTS (238 MB).</li><li>También el contenido de ayuda: FAQ, secciones, novedades, tutoriales (135 MB), la sugerencia con sus votos y estados, las reglas de recordatorio y las plantillas.</li><li>Las 36 tareas hechas, si casan con un proyecto.</li><li>Reescribe los IDs dentro del JSON.</li><li>Informe de diferencias y recuentos. Se ejecuta con `scripts/heavy.sh`.</li></ul> | M | Personas y clientes sin pareja; espacio en disco (238 MB de audio y 135 MB de vídeo); hay que hacerla después de la lista de empleados y del ClickUp definitivo. **Hecho (10.8, D-213 a D-220):** volcado en el Mac en solo lectura (`app:dump-weeklysync`, solo los ficheros que usan las filas) e importación en el servidor (`app:import-weeklysync`); las tareas sin proyecto claro y el uso de IA del OCR se listan en el informe en vez de un CSV |
| **10.9 Cierre y apagado** | <ul><li>Revisión global, despliegue (batería V y webs) y última importación con WeeklySync congelado.</li><li>Redirigir el dominio antiguo.</li><li>Baja de Supabase, Vercel, la GitHub Action, Resend y las claves de GCP: rotarlas y borrarlas.</li></ul> | S | Unos días de convivencia: que nadie escriba en WeeklySync después de la última importación |

**Total aproximado:** unos 25 puntos (2 + 3 + 5 + 3 + 2 + 2 + 5 + 2 + 1), unas 3 veces la Fase 9. Con el requisito de no perder nada, 10.6 y 10.7 son obligatorias. El orden propuesto deja la ayuda (10.7) para el final, porque no bloquea la operación semanal.

### F.1 IA: qué mantener con Gemini y qué cubre lo que ya hay
| Función | Propuesta |
|---|---|
| Informe semanal (lotes, fusión y global) | **Gemini.** Es el núcleo. |
| Delta de satisfacción al cerrar | **Gemini + regla determinista** en PHP. |
| Guion del audio | **Gemini**. Locución con **Google Cloud TTS** (`es-ES-Journey-F`, poco volumen: una weekly y unas 8 secciones a la semana). Alternativa local: Piper (MIT) en Docker con los núcleos 6-7, si G1 descarta Google. |
| Tareas sugeridas, resumen de cliente, actividad del equipo, desempeño por persona y asistente | **Gemini** (10.4 y 10.6), con los permisos de Audax. El asistente solo recibe el contexto que puede ver quien pregunta. |
| Transcripción | **Whisper local** (ya existe). Gemini solo para limpiar el texto y corregir nombres (opcional y barato). |
| OCR de proyectos y bolsas, histórico de persona, emails, altas e identidades | **Lo que ya hay** en Audax: no hace falta IA. |

**Cómo llamar a Gemini desde Laravel** (la clave nunca va en Git):
- `config/services.php` → `gemini.key = env('GEMINI_API_KEY')`, `gemini.model = env('GEMINI_MODEL')` y `gemini.timeout`.
- `GeminiClient` con `Http::withHeaders(['x-goog-api-key' => …])->timeout(…)->retry(3, backoff)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", ['contents' => …, 'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => …]])`.
- Siempre desde **Jobs** (cola `ai`, de uno en uno), nunca en la petición web. `FakeLlm` en los tests, y telemetría en `ai_usage`.
- Usar una clave de **AI Studio con facturación** (plan de pago: los datos no se usan para entrenar). Vertex con cuenta de servicio también sirve (`google/auth`, Apache-2.0), pero es más complejo.
- El modelo va en `.env` para cambiarlo sin desplegar, por las retiradas de Google.

---

## G. Preguntas abiertas para el propietario
1. **IA externa:** ¿autorizas enviar a Google (Gemini, de pago) el **texto** de las weeklies para generar el informe, la satisfacción y el guion, y la locución con Google TTS? Esto cambia el SPEC §2. ¿Y el **audio**: Whisper en el servidor (recomendado, como el chat) o seguir con Gemini?
2. **Permisos:** ¿generar, editar, cerrar y configurar los recordatorios y la ayuda lo hace solo el admin o también los responsables de departamento? (En WeeklySync hay 8 ADMIN de 13.) ¿Los colaboradores externos también hacen weekly? ¿Los resúmenes de desempeño por persona con IA los ve solo el admin y los responsables de esa persona? Hoy los ve cualquiera, y conviene que lo revise el asesor de RGPD.
3. **Sustituciones por equivalentes nativos:**
   - ¿Confirmas que la **subida de capturas con OCR** (F-111 a F-118 y F-122) se sustituye por la vista «Estado de proyectos» con los datos reales de Audax (F-119), o quieres conservar el OCR como importador?
   - ¿Y que el **login con Google** (F-022) pasa al login de Audax, o añadimos «Entrar con Google» (Workspace)?
4. **Histórico:** ¿se migra todo (28 semanas, 267 envíos, 1.695 entradas, 238 MB de audio y la satisfacción)? Con personas o clientes de WeeklySync que no existan en Audax, ¿se crean inactivos o se descartan?
5. **Calendario:** ¿cambiamos ya `GEMINI_MODEL` en WeeklySync (Gemini 2.5 Flash caduca el **16/10**) para que funcione hasta la migración? ¿Y en qué fecha quieres apagar WeeklySync? Propuesta: tras cerrar una weekly ya en Audax, con WeeklySync en solo lectura una semana.
