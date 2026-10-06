# Plan de la Fase 10: la Weekly dentro de Audax Proyectos

Pedida por el propietario el 05/10/2026. La agencia hace cada semana su **weekly** en WeeklySync, una app aparte (React y Vite sobre Supabase, con Google Gemini), solo para Audax. Se **fusiona** en Audax Proyectos:
- se reutilizan sus pantallas y su lógica,
- sobre los usuarios, clientes, proyectos, bolsas, horas y ausencias de Audax,
- con un solo inicio de sesión,
- migrando sus datos,
- y apagando después Supabase, Vercel, la GitHub Action y Resend.

Rama `fase-10`, que sale de `fase-9`.

**Requisito del propietario: no se pierde ninguna funcionalidad.** El inventario completo, con las 181 funcionalidades (F-001 a F-181), su origen y su destino, está en [`WEEKLY-INVENTARIO.md`](WEEKLY-INVENTARIO.md) (§A.4). Esa lista es la **definición de hecho** de la fase: cada F se marca como hecha y probada, y la fase no se cierra sin una revisión de completitud contra la app original. Solo se descarta la consola multi-tenant (F-175 a F-181). De ella se conservan, para una sola empresa, los módulos activos, el aviso global y la página «Uso de IA».

Decisiones: **D-145 a D-150**; las del contrato, **D-151 a D-154**.

## Entregas
| Entrega | Contenido | F | Tamaño |
|---|---|---|---|
| **10.1 Contrato** | Migraciones, modelos, enums, permiso `manage-weeklies` y políticas. Interfaces de dominio (`WeeklyEligibility`, `WeeklySubmissionWriter`, `WeeklyReportGenerator`, `SatisfactionStabilizer`, `LlmClient` con `GeminiClient` y `FakeLlm`, y `SpeechSynthesizer`), rutas `/weeklies…` y tipos TS | Base de todas | M |
| **10.2 Semana y envío** | Ciclo semanal, «Mi weekly» por cliente con borrador autoguardado, dictado con Whisper, exenciones, estado del equipo, rachas y tarjeta en Inicio | F-001 a F-054, F-065 a F-071, F-097 a F-100, F-171 y F-172 | L |
| **10.3 Informe, audio y cierre** | Informe estructurado con Gemini (lotes y fusión, con el contexto real de bolsas y horas), editar y regenerar, guion y locución por secciones, cierre con satisfacción estabilizada, PDF e impresión y «Uso de IA» | F-035, F-072 a F-095, F-173 y F-180 | XL |
| **10.4 Clientes, equipo y estado de proyectos** | Ficha de cliente (resumen IA, historial, equipo, satisfacción), ficha de persona (racha, hábitos, resúmenes IA restringidos) y vista nativa «Estado de proyectos» con datos reales | F-020, F-028, F-064, F-096, F-119 a F-145 | L |
| **10.5 Avisos** | Recordatorios (app, email y push) con reglas y deduplicación, «weekly cerrada», plazo ampliado, plantillas editables, envío manual y registro; un solo recordatorio de los viernes | F-037, F-095 y F-101 a F-110 | M |
| **10.6 Tareas y asistente** | Tareas de «Mi espacio», tareas sugeridas por IA (revisadas y creadas en un proyecto) y asistente `/ia` con los datos que puede ver quien pregunta | F-006, F-055 a F-063, F-146 y F-147 | M |
| **10.7 Ayuda y sugerencias** | Centro de ayuda (novedades con «me gusta», tutoriales en vídeo, FAQ, manual y soporte) y sugerencias (tableros, votos, comentarios con adjuntos y reacciones, estados y roadmap) | F-148 a F-170 | XL |
| **10.8 Migración** | `app:dump-weeklysync` (en el Mac, solo lectura) y `app:import-weeklysync` idempotente con `--dry-run`, ficheros de correspondencias y `import_refs` (fuente `weeklysync`). Migra ciclos, envíos, entradas, exenciones, satisfacción, audios, ayuda, sugerencias, reglas y plantillas | — | M |
| **10.9 Cierre y apagado** | Revisión de completitud y adversarial, despliegue, última importación con WeeklySync congelado, redirección del dominio antiguo y baja de los servicios (rotando y borrando las claves) | — | S |

Orden: 10.1 → 10.2 → 10.3 → 10.4 → 10.5 → 10.6 → 10.7 → 10.8 → 10.9. Un agente cada vez, con poca carga para el Mac: tests acotados con `nice`, Pest con 2 procesos y la batería completa solo al integrar.

## Urgente, fuera de la fase
Según su propio código, **Gemini 2.5 Flash se retira el 16/10/2026** y toda WeeklySync lo usa por defecto. Hasta la migración hay que cambiar el modelo, el secreto `GEMINI_MODEL` de Supabase, por uno habilitado en Google Cloud. Pendiente del visto bueno del propietario.

## Verificación
- **Local:** Pest, PHPStan nivel 7, Vitest, `tsc`, `vp check`, la compilación y E2E de cada entrega. Gemini y TTS con dobles (`FakeLlm`) en los tests, y los prompts portados con fixtures compartidos.
- **Paridad:** la lista F-001 a F-181 de `WEEKLY-INVENTARIO.md` con su estado al día. Al cerrar, un agente recorre WeeklySync (código y app) contra Audax y no se cierra con ninguna F pendiente.
- **Servidor** (cuando vuelva el SSH): `desplegar-dev.sh --tests`, clave de Gemini en `shared/.env` (la pone el propietario), importación con `heavy.sh` tras una copia, recuentos frente a Supabase, batería V y webs.

## Contrato 10.1 (hecho)
Hecho el 05/10/2026 en `fase-10`. Es la base que usan 10.2 a 10.8: **no se cambian estas firmas sin anotarlo aquí**. Decisiones nuevas: D-151 (datos), D-152 (dictado), D-153 (reglas portadas) y D-154 (cola `ai` y claves).

### Datos (migraciones del 05/10, `2026_10_05_1000xx`)
- **Columnas nuevas:**
  - `users.job_title`,
  - `clients.icon` y `clients.satisfaction_score` (50 por defecto),
  - permiso `manage-weeklies`, que tienen los roles admin y responsable (también en `Role::defaultPermissions()`).
- **Semana:** `weekly_cycles`.
  - `number` (único), `label`, `start_date` (único), `end_date`, `deadline_date` y `status` (`active`/`closed`, **una sola activa** por índice parcial).
  - El informe: `report` (JSON de `WeeklyReport`), `report_text`, `submission_count_at_generation`, `report_state` y `report_error`, `report_generated_at/by` y `report_edited_at/by`.
  - El audio: `audio_state`, `audio_error`, `audio_disk/path` y `audio_generated_at/by`.
  - El cierre: `expected_user_ids` (la foto al cerrar), `closed_at` y `closed_by`. Usa `LogsDomainActivity`, sin el informe.
- **Envíos:**
  - `weekly_submissions`: única por (semana, persona); borrador mientras `submitted_at` es null; `resubmitted_at` y `draft_saved_at`.
  - `weekly_entries`: `client_id` null = General; `project_id` opcional; `body`; `source` text/dictation; `position`. Única por (envío, cliente).
- **Lo demás de la semana:**
  - `weekly_exemptions`: `reason` absence/manual/waived, `absence_id`, `note` y `created_by`. Única por (semana, persona).
  - `weekly_audio_sections`: `key` "intro"/"client-{id}"/"outro", `kind`, `client_id`, `position`, `script`, `disk/path/mime/size`, `duration_ms`, `voice` y `generated_at`.
  - `client_satisfaction_snapshots`: única por (cliente, semana); `score`, `previous_score`, `requested_delta`, `delta`, `rule`, `reasoning`, `evidence_level`, `explicit_client_impact`, `confidence`, `metrics` y `model`.
- **Borrados:**
  - las FK de autoría a `users` son **restrict**: borrar a una persona nunca borra weeklies, y en Audax se desactiva,
  - lo de una semana cae en cascada al borrarla (F-069).
- **IA y avisos:**
  - `ai_usage`: `provider`, `model`, `feature`, `operation`, `status`, latencia, tokens, caracteres, `estimated_cost_usd` (decimal 12,6), `subject` (morph), `error` y `metadata`. Solo `created_at`.
  - `weekly_reminder_rules`: `channel` email/push, `day_of_week` **ISO 1-7** (WeeklySync usaba 0 = domingo), `time` "HH:MM" de Madrid, `enabled` y `position`.
  - `weekly_reminder_logs`: deduplicación por el único (`trigger_key`, `channel`, `user_id`), con `template`, `status` sent/failed/skipped, `error`, `sent_by` y copia del nombre y el email.
- **Mi espacio:**
  - `dictations` (D-152): `context` weekly_entry/task_note, `status` (TranscriptionStatus), `raw_text`, `text`, `warning` y el audio temporal,
  - `task_archives`: archivado personal, único por (persona, tarea).
- **Ayuda:** `help_releases` (único por versión, `is_hidden`), `help_release_changes`, `help_manual_updates`, `help_update_likes` (morph `likeable`), `help_tutorials` (el vídeo es un `Attachment`), `help_faq_sections` y `help_faqs` (restrict: una sección con preguntas no se borra).
- **Sugerencias:**
  - `suggestion_boards` y `suggestion_categories` (precargados «Sugerencias» y `bugs`),
  - `suggestion_posts`: `status`, `position` del roadmap, `vote_count`, `comment_count` y `last_activity_at`; los adjuntos, `Attachment`,
  - `suggestion_votes`, `suggestion_comments` (`parent_id`, `edited_at`; adjuntos `Attachment`), `suggestion_comment_reactions` (una por persona) y `suggestion_status_events`.
- **Ajustes** (`Setting::DEFAULTS`):
  - `modules` y `global_banner`, validados en `PUT /admin/ajustes` (opcionales),
  - `weekly_email_templates`,
  - `help_support_url` y `help_manual`.

### Modelos, enums y factorías
- **Modelos:**
  - la semana: `WeeklyCycle` (scopes `active()` y `closed()`, `isActive()`, `isClosed()` y `reportData(): ?WeeklyReport`), `WeeklySubmission` (scope `submitted()` e `isSubmitted()`), `WeeklyEntry`, `WeeklyExemption`, `WeeklyAudioSection` y `ClientSatisfactionSnapshot`,
  - la IA y los avisos: `AiUsage`, `WeeklyReminderRule`, `WeeklyReminderLog`, `Dictation` y `TaskArchive`,
  - la ayuda: `HelpRelease` (`versionLabel()`), `HelpReleaseChange`, `HelpManualUpdate`, `HelpUpdateLike`, `HelpTutorial` (`video()`), `HelpFaqSection` y `HelpFaq`,
  - las sugerencias: `SuggestionBoard`, `SuggestionCategory` (`BUGS_SLUG`), `SuggestionPost`, `SuggestionVote`, `SuggestionComment`, `SuggestionCommentReaction` y `SuggestionStatusEvent`.
  - `User::writesWeeklies()`, `User::WEEKLY_ROLES` y `User::weeklySubmissions()`; `Client::satisfactionSnapshots()`.
- **Enums** (`app/Enums`, con `label()` en `lang/es/weeklies.php`):
  - la semana: `WeeklyCycleStatus`, `WeeklyCycleProgress`, `WeeklyPersonStatus`, `WeeklyExemptionReason` (`exempts()`), `WeeklyEntrySource`, `WeeklyAudioSectionKind`, `WeeklyJobState` (`isBusy()`) y `WeeklyClientStatus` (`fromWeeklySync()` y `severity()`),
  - la IA: `AiProvider` y `AiFeature`,
  - los avisos: `WeeklyReminderChannel`, `WeeklyReminderTemplate` y `WeeklyReminderStatus`,
  - lo demás: `DictationContext`, `SuggestionStatus` (`isRoadmap()`), `SuggestionReaction` y `AppModule`.
- **Factorías:**
  - `WeeklyCycle`: por defecto, semanas **cerradas** cada vez más antiguas; `active('2026-10-05')`, `forWeekOf()`, `closed()` y `withDeadline()`,
  - `WeeklySubmission`: borrador por defecto; `submitted($at)`,
  - `WeeklyEntry` (`general()`), `WeeklyExemption` (`waived()` y `absence()`), `ClientSatisfactionSnapshot`, `AiUsage` (`failed()`), `HelpRelease`, `HelpFaqSection`, `SuggestionBoard`, `SuggestionCategory` y `SuggestionPost`.

### Permisos
- **Gates:**
  - `use-weeklies`: admin, responsables y empleados activos,
  - `manage-weeklies` (permiso) y `manage-help` (el mismo),
  - `view-ai-usage`: admins,
  - `view-person-ai-summary(User $subject)`: el admin y los responsables de esa persona, nunca ella misma.
  - Todas, denegadas a los colaboradores externos (`COLLABORATOR_DENIED`).
- **Props compartidas:** `auth.can.useWeeklies`, `auth.can.manageWeeklies`, `auth.can.viewAiUsage`, `config.modules` y `config.global_banner`.
- **Políticas:**
  - `WeeklyCyclePolicy`: `viewAny`, `view`, `create`, `update`, `generate`, `extendDeadline` (activa), `close` (activa), `delete` y `remind` (activa),
  - `WeeklySubmissionPolicy`: `create(User, WeeklyCycle)` (activa), `view` (la propia o una enviada), `update` (la propia con la semana activa) y `delete` (nunca),
  - `WeeklyExemptionPolicy`: `create(User, WeeklyCycle)`, `waive(User, WeeklyCycle)` y `delete`,
  - `DictationPolicy`: `create` y `view` (solo la propia persona),
  - `SuggestionPostPolicy`: `viewAny`, `view`, `create`, `vote`, `comment`, `update`/`delete` (autor o quien gestiona), `moderate` y `manageBoards`,
  - `SuggestionCommentPolicy`: `update` (autor), `delete` (autor o quien gestiona) y `react`.

### Dominio (`app/Domain/Weeklies`)
- **`WeeklyCalendar`** (puro):
  - `periodFor(date): WeeklyPeriod`, `current(?now)`, `next(period|date)` y `previous(…)`,
  - `number(date)`: «W41-26», con el año ISO (D-153),
  - `label(date)`: «Semana 41 (Lun 05/10 - Vie 09/10)»,
  - `WeeklyPeriod` tiene `start`, `end`, `deadline`, `number`, `label` y `toAttributes()`.
- **`WeeklyTiming`** (puro): `deadlineEnd()`, `pendingStart()`, `isUpcoming()`, `isOverdue()`, `isOnTime()`, `personStatus(status, deadline, ?submittedAt, exempt, required, now): WeeklyPersonStatus` y `cycleProgress(status, deadline, submitted, expected, now): WeeklyCycleProgress`.
- **`WeeklyEligibility`:**
  - `rosterFor(WeeklyCycle): WeeklyRoster`, en 3 consultas,
  - `freeze(WeeklyCycle): WeeklyRoster`: llamarla al cerrar, antes de marcarla `closed`,
  - `resolve(candidates, coveringAbsences, rows)`: la regla pura,
  - `WeeklyRoster` tiene `participants()`, `expected()`, `exemptions()`, `participates()`, `isExempt()`, `mustSubmit()`, `reasonFor()` y `absenceFor()`.
- **`StreakCalculator`** (puro):
  - `streak(iterable<StreakWeek>, now): int`,
  - `summary(…)`: `{submitted, on_time, streak}`,
  - `StreakWeek(endDate, deadlineDate, status, exempt, ?submittedAt, required)`.
- **`SatisfactionStabilizer`** (port exacto, D-153):
  - `stabilize(array $input): SatisfactionDecision`, con las claves de JS: `requestedDelta`, `currentSatisfaction`, `reportText`, `evidenceLevel`, `explicitClientImpact` y `confidence`; una clave ausente es `undefined`,
  - `SatisfactionDecision` tiene `finalDelta`, `rule` y `metrics`, y `applyTo(score)` (acotado a 0-100),
  - además `stableReasoning()`, `signalMetrics()`, `toneScore()` y `cleanJsonResponse()`.
  - Fixtures: `tests/fixtures/weeklies/satisfaction-cases.json`, que se regeneran con `node tests/fixtures/weeklies/generate-satisfaction-cases.mjs <ruta a satisfaction.js>`.
- **Informe** (`Report\`): `WeeklyReport`, `WeeklyClientUpdate`, `WeeklyMilestone` y `WeeklyProjectSnapshot` (minutos).
  - Cada uno con `fromArray()` y `toArray()`.
  - `WeeklyReport` y `WeeklyClientUpdate` tienen además `fromWeeklySync(array, callable(?string $wsId, string $name): ?int)`, para 10.8.
  - `WeeklyReportState::isStale(cycle, submittedCount)` y `closeBlockers(cycle, hasAudio)`, que devuelve `report`, `audio` o `not_active`.
- **Interfaces que se implementan después:**
  - `WeeklySubmissionWriter` (10.2): `saveDraft(User, WeeklyCycle, WeeklyDraftData): WeeklySubmission` y `submit(…)`. Sus reglas, numeradas en el docblock. `WeeklyDraftData::fromArray()` y `filled()`, `WeeklyEntryData` y `WeeklyRuleViolation` (`cycle_closed`, `not_participant`, `exempt` y `already_active`).
  - `WeeklyReportGenerator` (10.3): `generate(WeeklyCycle, ?User): GeneratedWeeklyReport`, que devuelve `report`, `text`, `submissionCount` y `model`.
- **Módulos:** `AppModules::enabled(AppModule)`, `map()` y `normalize()`, y el middleware `module:<nombre>`.

### IA (`app/Domain/Weeklies/Ai`)
- **`LlmClient`:**
  - `generate(LlmRequest): LlmResponse` y `model()`,
  - `LlmRequest(feature, prompt, ?system, ?responseSchema, json, ?temperature, ?maxOutputTokens, ?user, ?subject, operation, metadata)`,
  - `LlmResponse` trae `text`, `json`, `model`, los tokens, `latencyMs` y `finishReason`,
  - errores: `LlmNotConfigured`, `LlmUnavailable` y `LlmInvalidResponse` (de `LlmException`, con `userMessage()`).
- **`GeminiClient`** (D-154) y **`FakeLlm`:** `FakeLlm::bind()`, `push(array|string|Throwable …)`, `respondUsing()`, `assertSent()`, `assertSentCount()` y `assertNothingSent()`. Sin respuesta programada, falla.
- **Locución:**
  - `SpeechSynthesizer::synthesize(SpeechRequest): SynthesizedSpeech`, con los bytes MP3, `voice`, `characters` y `chunks`,
  - `GoogleTtsSynthesizer`, con `chunks()` y `mergeMp3()` portados,
  - `FakeSpeechSynthesizer::bind()` y `failWith()`.
- **Registro y coste:** `AiUsageRecorder::record(…)` y `AiPricing::gemini()` y `speech()`, en BCMath.
- **`AiQueue::NAME`** (`ai`) y **`TIMEOUT`** (600). Un Job de IA hace `$this->onQueue(AiQueue::NAME)` y `public int $timeout = AiQueue::TIMEOUT`.
- **Configuración:**
  - `services.gemini.*` y `services.google_tts.*`,
  - `.env.example`: `GEMINI_*` y `GOOGLE_TTS_*`,
  - `phpunit.xml` fuerza los dobles.
- **Enlaces:** los hace `WeekliesServiceProvider`.

### Rutas (`routes/app/weeklies.php`), controladores y páginas
- **Autorización y respuesta:**
  - cada acción autoriza antes que nada,
  - lo que falta responde **501** con la entrega que lo completa (trait `PendingDelivery`): el agente de esa entrega sustituye el `pending()`,
  - un colaborador externo no entra en ninguna (no están en `config/collaborators.php`).
- **Semanas, informe y audio** (`module:weeklies`):
  - el histórico: `weeklies.index` (página), `store`, `show` (página), `destroy`, `deadline.update` y `close`,
  - el informe: `weeklies.report.store`, `update`, `status` y `pdf`,
  - el audio: `weeklies.audio.store`, `download` y `show` (firmada).
- **Exenciones y avisos** (`module:weeklies`):
  - `weeklies.exemptions.store`, `waive` y `destroy`,
  - `weeklies.reminders.edit`, `update`, `send` y `remind`.
- **Estado de proyectos** (`module:weeklies` y `module:project_status`): `weeklies.project-status`.
- **Mi espacio** (`module:weeklies`):
  - `my-space.index` (página), `my-weekly.draft` y `my-weekly.submit`,
  - `dictations.store` y `show`,
  - `my-space.tasks.suggest`, `archive` y `unarchive`.
- **Equipo y ficha de cliente** (`module:weeklies`): `team.index`, `team.show`, `team.ai-summary`, `clients.ai-summary` y `clients.team-activity`.
- **Uso de IA:** `admin.ai-usage.index`.
- **Asistente** (`module:assistant`): `assistant.index` (página) y `assistant.ask`.
- **Ayuda** (`module:help`):
  - `help.index` (página, `?pestana=`), `help.manual` y `help.settings.update`,
  - `help.releases.*`, `help.updates.*`, `help.likes.toggle`, `help.tutorials.*` (con `reorder` y `video`), `help.faq-sections.*` y `help.faqs.*`.
- **Sugerencias** (`module:help` y `module:suggestions`):
  - `suggestions.store`, `show`, `update`, `destroy`, `vote`, `status.update` y `position.update`,
  - `suggestions.comments.store`, `update`, `destroy` y `react`,
  - `suggestions.boards.*` y `suggestions.categories.*`.
- **Resources** (`app/Http/Resources/Weeklies`): `WeeklyCycleResource`, `WeeklyCycleDetailResource`, `WeeklySubmissionResource`, `WeeklyEntryResource`, `WeeklyExemptionResource`, `WeeklyAudioSectionResource`, `ClientSatisfactionResource`, `AiUsageResource` y `DictationResource`.
  - Los de la ayuda y las sugerencias los crea 10.7 con los tipos ya definidos.
- **Tipos y textos:**
  - TS: `resources/js/types/weeklies.ts`, con los enums, el informe, las filas, la ayuda, las sugerencias y las props de página,
  - `auth.ts`: `AppConfig.modules` y `global_banner`, y `Abilities` con los nuevos campos opcionales,
  - textos: `lang/ui/weeklies.json` (registrado en `i18n.ts`) y `lang/es/weeklies.php` (enums, errores y plantillas por defecto).
- **Páginas mínimas:** `weeklies/index`, `weeklies/show`, `my-space/index`, `assistant/index` y `help/index`, con `WeeklyPlaceholder`. Aún no salen en la barra lateral (F-001, entrega 10.2).

### Tests del contrato
- `tests/Unit/Weeklies`: el calendario, los plazos, las rachas, el informe y los 330 casos del estabilizador.
- `tests/Feature/Weeklies`: el esquema, la elegibilidad, los permisos, la IA con `Http::fake` y las rutas.
- `QueueConfigTest`, con el supervisor `ai` y los 640 MB.

### Pendiente fuera del Mac (con el SSH)
- **Al desplegar:**
  - `migrate` (8 migraciones),
  - subir `MemoryLimit=640M` en `audax-horizon.service` (`deploy/systemd`) con `daemon-reload` y reinicio, anotándolo en `SERVIDOR-CAMBIOS.md`,
  - después, `verificar.sh` y `comparar-webs.sh`.
- **Claves del `.env` del servidor** (las pone el propietario): `GEMINI_API_KEY`, `GEMINI_MODEL` (uno vigente, por la retirada de 2.5 Flash el 16/10) y `GOOGLE_TTS_API_KEY`.

### Notas para las siguientes entregas
- **10.2:**
  - abrir la semana con `WeeklyCalendar::current()` y `toAttributes()`; el índice único impide dos activas, así que la condición de carrera da `QueryException`,
  - la tarjeta de Inicio y la barra lateral (F-001) usan `auth.can.useWeeklies` y `config.modules.weeklies`,
  - el interruptor de los módulos y el aviso global, en la pantalla de ajustes,
  - el campo «Puesto» en el perfil y en el alta.
- **10.3:**
  - al cerrar, `WeeklyEligibility::freeze()` y luego `WeeklyCalendar::next()`,
  - la satisfacción, con `SatisfactionStabilizer::stabilize()` y `applyTo()`, en `client_satisfaction_snapshots` y en `clients.satisfaction_score`, más `withSatisfaction()` en el informe.
- **10.5:** el recordatorio de los viernes se une con `time:remind-week` (D-150); la deduplicación es el índice único de `weekly_reminder_logs`.
- **10.8:**
  - mapear `day_of_week` de 0 = domingo a ISO,
  - el estado «On Track», «Risk» y «Blocked» con `WeeklyClientStatus::fromWeeklySync()`,
  - rellenar `expected_user_ids` y las exenciones `absence` con `excused_user_ids`, con el motivo de importación,
  - `import_refs` con la fuente `weeklysync`.

## 10.2a (hecho): semana y envío, parte de servidor
Hecha el 05/10/2026 en `fase-10`. La 10.2 se parte en dos:
- **10.2a:** el servidor, con todos los datos que necesitan las pantallas.
- **10.2b:** las pantallas React.

Decisiones nuevas: **D-155 a D-161**. En `WEEKLY-INVENTARIO.md` §A.4, las F con el servidor hecho llevan «Servidor hecho (10.2a)». Los tipos TS de todo lo que sigue están en `resources/js/types/weeklies.ts`; las rutas, en `routes/app/weeklies.php`.

### Dominio (`app/Domain/Weeklies`)
- **`EloquentWeeklySubmissionWriter`:** implementa `WeeklySubmissionWriter` (D-157). Relee y bloquea la semana dentro de la transacción.
- **`WeeklyCycleOpener`** (D-155): `ensureOpen()`, `target()`, `open()`, `afterDelete()` y `afterClose($cerrada)`, el enganche del cierre de 10.3.
- **Datos de las pantallas:**
  - `MyWeeklyStatus`: `for()`, `pendingCount()` (D-160), `forget()` y `forgetActive()`,
  - `MyWeeklyHistory` y `MyWeeklyClients`: `for()`, `autofill()` y `duration()`,
  - `WeeklyTeamStatus`, `WeeklyOverview`, `WeeklyStreaks` (`summary()` y `weeks()`) y `HomeWeeklyCard`.
- **`WeeklyEligibility::rosterForUser()`:** la foto de una sola persona.
- **Dictado:** `Dictation\DictationText` (reglas puras) y `Dictation\DictationCleaner` (IA, ajuste `weekly_dictation_cleanup`).
- **Jobs:**
  - `TranscribeDictation`, en la cola `transcriptions`,
  - `CleanDictation`, en la cola `ai`.
- **Evento `App\Events\Weeklies\DictationUpdated`:** `dictation.updated` por el canal `App.Models.User.{id}`, con `{dictation_id, status, warning}`.
- **Comando `weeklies:open-week`:** lo lanza el planificador cada día a las 00:05 de Madrid.

### Páginas y sus props
**`weeklies/index`** (`WeeklyCycleController::index`, `/weeklies?pestana=resumen|historico`). `WeekliesIndexPageProps`:
- `tab`,
- `active` y `latest_closed`: `WeeklyHighlightedCycle`, es decir, una fila del histórico más `team` (`WeeklyTeamStatus`: `members[]` con `user`, `status`, `submitted_at`, `exemption_reason` y `exemption_id`, y `counts` con `{submitted, expected, exempt, pending}`),
- `cycles`: `WeeklyHistoryRow[]`, que es `WeeklyCycleResource` más `progress` y `participation`; hasta 104,
- `me`: `MyWeeklyStatus` de la semana activa, o null,
- `streak`: `{submitted, on_time, streak}`,
- `my_clients`: `{owned[], member[]}`, cada uno con `{id, name, icon, projects[{id, code, name, can_leave}]}`,
- `joinable_projects`: prop **opcional**; se pide con `router.reload({ only: ['joinable_projects'] })`,
- `can`: `{manage, create, extendDeadline, delete, exempt}`.

**`my-space/index`** (`MySpaceController::index`, `/mi-espacio?pestana=reportes|tareas&semana={id}`). `MySpacePageProps`:
- `tab`,
- `cycle` y `submission`: la semana activa y mi envío en ella (contrato 10.1),
- `weeks`: `MyWeeklyRow[]`, con `cycle`, `status`, `is_upcoming`, `submitted_at`, `has_draft`, `entries_count` y `exemption_reason`,
- `streak`,
- `editor`: solo con `?semana=`; si no, null. `MyWeeklyEditor` trae:
  - `cycle`, `me` y `submission`, con sus `entries`,
  - `clients`: `proposed` (los id con caja) y `catalog` (`[{id, name, icon, is_active, projects[{id, code, name, is_mine}]}]`),
  - `autofill`: `{[clientId | "general"]: texto}`,
  - `read_only`,
  - `can`: `{write, waive, undo_waiver}`.
- Una `?semana=` que no existe da 404.

**`home`** (`HomeController`): la prop **diferida** `weekly` (`HomeWeeklyCard`) trae `{cycle, me, streak, team: {counts, pending: UserSummary[] (hasta 8)} | null, can: {manage, open}}`. Es null para quien no escribe la weekly o con el módulo apagado; a un colaborador externo no le llega. La tarjeta `weekly` ya está en `HomeLayout` y en `tests/fixtures/home-cards.json`.

**`settings/profile`:**
- `jobTitle`,
- `weeklyStats`, diferida: `{submitted, on_time, streak}`, o null.

**Props compartidas:** `weeklies.pending`, que vale 0 o 1 (D-160), para el contador rojo de «Mi espacio» (F-003). Con `auth.can.useWeeklies` y `config.modules.weeklies` se pinta la barra lateral (F-001).

### Acciones
- **`PUT /mi-espacio/weeklies/{cycle}`** (`my-weekly.draft`): el autoguardado, sin recargar.
  - Lleva `{entries: WeeklyEntryInput[]}` y responde en JSON `{submission}`.
  - Una regla rota da 422 en `entries` con su mensaje (semana cerrada, no te toca o estás exento).
- **`POST /mi-espacio/weeklies/{cycle}/enviar`** (`my-weekly.submit`): lo mismo, como Inertia. Redirige a `?semana=` con aviso; sin texto, 422.
- **`POST /mi-espacio/dictados`** (multipart: `context=weekly_entry`, `weekly_cycle_id`, `client_id?`, `audio` y `duration_ms`):
  - responde 201 con `{dictation}`,
  - después se sigue con `GET /mi-espacio/dictados/{id}` o con el evento `dictation.updated`, hasta `status` `done` o `failed`,
  - `text` vacío más `warning` (`too_short` o `no_speech`) es «no se ha oído nada».
- **`POST /mi-espacio/proyectos`** (`{project_ids[]}`) y **`DELETE /mi-espacio/proyectos/{project}`**.
- **Gestión** (`manage-weeklies`):
  - `POST /weeklies`: iniciar la semana,
  - `PUT /weeklies/{cycle}/plazo` (`{deadline_date: "Y-m-d"}`): del lunes a cuatro semanas después del viernes,
  - `DELETE /weeklies/{cycle}`: redirige a `?pestana=historico`,
  - `POST /weeklies/{cycle}/exenciones` (`{user_id, note?}`).
- **Exenciones de cada persona:**
  - `POST /weeklies/{cycle}/exenciones/renuncia`: la propia persona,
  - `DELETE /weeklies/{cycle}/exenciones/{exemption}`: quitar la manual o deshacer la renuncia.
- **Ajustes:** `PUT /admin/ajustes` acepta además `weekly_dictation_cleanup` (booleano).
- **`GET /version`:** `{version}`, la de Inertia (F-013).

### Para 10.2b (pantallas)
- **Barra lateral (F-001):** las entradas «Weeklies» y «Mi espacio», esta con `weeklies.pending`.
- **«Mi weekly»:**
  - la caja «General / Interno» va siempre: `client_id` null,
  - plegar todo (F-046) y la guía (F-047) son solo de interfaz,
  - el dictado usa la grabadora del chat.
- **Ajustes:**
  - el interruptor de los módulos (`modules`) y el aviso global (`global_banner`),
  - la limpieza del dictado (`weekly_dictation_cleanup`).
- **Las fechas de semana compactas en el móvil (F-021) y la recarga y el aviso de versión (F-013 y F-014):** solo de interfaz.

### Para 10.3
- **Al cerrar**, en la transacción:
  1. `WeeklyEligibility::freeze()`,
  2. marcar la semana cerrada,
  3. después, `WeeklyCycleOpener::afterClose($cycle)`.
- Guardar la semana olvida sola la semana activa en caché (D-160).

### Tests
En `tests/Feature/Weeklies`:
- `WeeklySubmissionWriterTest`, `WeeklyCycleLifecycleTest`, `WeeklyExemptionsTest`, `WeeklyDashboardsTest` y `WeeklyDictationTest`,
- `WeeklyMiscTest` y `WeeklyPagesPerformanceTest` (presupuestos de consultas).

## 10.2b (hecho): semana y envío, pantallas
Hecha el 05/10/2026 en `fase-10`, sobre el servidor de la 10.2a. Decisiones nuevas: **D-180 a D-186** (D-162 a D-179 están reservadas para otras ramas). En `WEEKLY-INVENTARIO.md` §A.4, 47 F pasan a «Hecho (10.2)»; F-001, F-064, F-070 y F-133 tienen la pantalla a medias, a la espera de la 10.3 o la 10.4.

### Páginas
- **`weeklies/index`** (D-183), con las pestañas «Resumen» e «Histórico» como enlaces:
  - «Resumen» (`WeeklyOverview`): mi weekly con su botón (`MyWeeklyCallout`), mi racha, la gestión de la semana para quien gestiona (estado global, pendientes con «Eximir», exentos con «Quitar exención», «Cambiar el plazo» e «Iniciar la semana») y mis clientes con «Unirme a proyectos» y «Dejar proyecto»,
  - «Histórico» (`WeeklyHistory`): la semana activa y la última cerrada destacadas con la tira del equipo y el estado del informe, y la tabla (tarjetas en el móvil), con «Eliminar».
- **`my-space/index`:**
  - «Reportes»: «Mis envíos» (`MyWeeksList`) con la racha; con `?semana=`, «Mi weekly» (`MyWeeklyEditor`, D-181),
  - «Tareas»: enlace a Mis tareas hasta la 10.6.
- **`home`:** la tarjeta «Weekly» (`HomeWeeklyCard`, diferida).
- **`settings/profile`:** las estadísticas de la weekly (`WeeklyStats`, diferidas).
- **`admin/settings`:** la sección «Weekly y módulos» (D-185).

### Componentes (`resources/js/components/weeklies`)
- **Cajas y dictado:**
  - `client-entry-box`: una caja por cliente,
  - `dictation-button` y `use-dictation`: el dictado (D-182),
  - `use-weekly-autosave`: el autoguardado,
  - `weekly-api`: las peticiones JSON del borrador y del dictado.
- **Resumen e histórico:** `team-status-strip` (la tira del equipo, F-067), `weekly-ui` (etiquetas de estado, la semana compacta del móvil, el icono del cliente, la racha y la participación), `weekly-dialogs` (el plazo, eximir y unirme a proyectos) y `weekly-tabs`.
- **Comunes de la app:**
  - `global-banner` (el aviso global),
  - `components/app-freshness` (la versión nueva y la conexión, D-184), los dos en el layout de la app.
- **Fuera de la carpeta:**
  - la barra lateral (D-180),
  - `DatePicker` con `min`,
  - la altura del chat descuenta el aviso global.

### Datos de ejemplo
El `DemoDataSeeder` trae las tres semanas anteriores cerradas, sin ninguna activa (D-186), con su test en `SeedersTest`. El E2E abre la semana en curso con «Iniciar la semana».

### Tests
- **Vitest:**
  - `weeklies-editor` (las cajas, el autoguardado y su error, autocompletar, añadir un cliente, plegar, enviar, exento y solo lectura),
  - `weeklies-dictation` (MediaRecorder simulado: subida, «Transcribiendo…», texto, sin voz, demasiado corto y error),
  - `weeklies-team-nav` (la tira, la barra lateral con el contador y la tarjeta de Inicio),
  - `weeklies-global-banner` y `weeklies-app-freshness`.
- **E2E:** `weeklies.spec.ts` (escribir el borrador, enviar y verlo en la tira del equipo; eximir y quitar la exención; una semana cerrada en solo lectura; AA y 375 px). Además, `SIDEBAR_PATHS` y `collaborator.spec.ts` incluyen las entradas nuevas. **Escritos, sin ejecutar en el Mac:** van a la CI.

### Para 10.3
- **«Cerrar semana» (F-035 y F-089):** en la gestión de `WeeklyOverview` y en la página del informe. Al cerrar, `WeeklyCycleOpener::afterClose()` abre la siguiente (completa F-070).
- **`weeklies/show`:** sigue siendo el esqueleto del contrato. Desde el resumen y el histórico ya se enlaza a él («Ver el informe»).
- **La tira del equipo** (`TeamStatusStrip`) sirve para el estado del equipo del informe (F-088).
- **«Recordar» a una persona pendiente** (F-037) es de la 10.5: va en la lista de pendientes de `WeeklyOverview`.

## 10.3 (hecho): informe, audio y cierre
Hecha el 05/10/2026 en `fase-10`. Decisiones nuevas: **D-187 a D-193**. En `WEEKLY-INVENTARIO.md` §A.4, F-035, F-070, F-072 a F-094, F-173 y F-180 pasan a «Hecho (10.3)»; F-095 queda preparada (el evento) para la 10.5.

### Dominio
- **Informe** (`app/Domain/Weeklies/Report` y `LlmWeeklyReportGenerator`, D-188 y D-189):
  - `ReportPipeline`: el port puro de `pipeline.js` (prompts, lotes, normalización, reglas de estado, reservas sin IA y detección de inglés),
  - `ReportClientInput` y `ReportEntryInput`: un cliente con sus apuntes; `ReportSchemas`: los `responseSchema` del informe, el guion y la satisfacción,
  - `WeeklyProjectStatus::forCycle(cycle, clientIds)`: el estado de los proyectos por cliente (bolsa en curso, fee mensual con lo esperado por días laborables, presupuesto y horas de la semana). **Lo puede reutilizar la 10.4** para la vista «Estado de proyectos»,
  - `WeeklyReportText::markdown()`: el texto para copiar,
  - `LlmWeeklyReportGenerator` implementa `WeeklyReportGenerator`, enlazado en `WeekliesServiceProvider`.
- **Audio** (`app/Domain/Weeklies/Audio`, D-190): `WeeklyAudioScripts` (guion por secciones y sus reservas), `WeeklyAudioGenerator` (locución, ficheros y audio completo) y `Mp3Duration`. `Ai\SpeechFailed` envuelve los errores de la locución.
- **Cierre y satisfacción** (D-191): `WeeklyCycleCloser::close(cycle, user)` y `hasAudio()`; `Satisfaction\SatisfactionUpdater::update(cycle)`.
- **Progreso** (D-190): `WeeklyJobProgress` (estado en la semana, detalle en caché y evento).
- **Jobs** (cola `ai`, un intento, `AiQueue::TIMEOUT`): `GenerateWeeklyReport`, `GenerateWeeklyAudio` y `UpdateWeeklySatisfaction`.
- **Eventos:** `Events\Weeklies\WeeklyGenerationUpdated` (`weekly.progress`, canal privado `weeklies.{id}`, en `routes/channels.php`) y `Events\Weeklies\WeeklyCycleClosed` (sin difusión: para la 10.5).
- **Cambios del contrato 10.1** (compatibles):
  - `WeeklyReportGenerator::generate()` admite un tercer parámetro opcional, `?Closure $progress` (hechos, total, paso),
  - `WeeklyProjectSnapshot` lleva `weekMinutes` (`week_minutes`) y `billingType` es el tipo de la vista (`hour_bank`, `monthly_fee`, `fixed_price` o `time_and_materials`),
  - `WeeklyCycleResource` lleva `has_audio`,
  - `weeklies.audio.show` usa `signed:relative` y la URL firmada es relativa, como los audios del chat,
  - `FakeLlm::demo()` para local y E2E (D-193).
- **PDF** (D-192): `ReportKind::Weekly`, `Reports\Delivery\Documents\WeeklyDocument` y `resources/views/reports/pdf/weekly.blade.php` (con estilos en `report.css`); `ReportAccess` y `report-request.ts` lo conocen.

### Rutas, páginas y props
- **`weeklies/show`** (`WeeklyCycleController::show`, `WeeklyShowPageProps`): `cycle` (con `audio_sections`), `team` (`WeeklyTeamStatus`), `reports` (los originales por cliente; clave `general` sin cliente), `stale`, `submitted_count`, `my_client_ids`, `progress` (`{report, audio}`), `close` (`{blockers, pending}`), `report_request` (kind `weekly`) y `can` (`generate`, `edit`, `extendDeadline`, `close`, `delete`).
- **Acciones:**
  - `POST weeklies.report.store` (422 con la semana cerrada o si ya se está generando; 202 en JSON),
  - `PUT weeklies.report.update` (`{global_summary?, team_risks?, client_updates[]}`),
  - `GET weeklies.report.status` (`WeeklyReportStatus`),
  - `GET weeklies.report.pdf?formato=pdf|imprimir|xlsx|csv|html&mios=1`,
  - `POST weeklies.audio.store`, `GET weeklies.audio.download[?descargar=1]` y `GET weeklies.audio.show` (firmada),
  - `POST weeklies.close`.
- **`admin/ai-usage`** (`AiUsageController`, `AiUsagePageProps`, `?dias=7|30|90`), con enlace en Administración.
- **Componentes** (`resources/js/components/weeklies`): `weekly-report-view` (la página), `client-report-card` (tarjeta y barra de proyecto), `weekly-audio` (reproductores y «solo suena uno»), `report-edit-dialog`, `weekly-close-dialog` (también en la gestión de `WeeklyOverview`) y `use-weekly-progress`.

### Tests
- **Pest:** `tests/Unit/Weeklies/ReportPipelineTest` y, en `tests/Feature/Weeklies`, `WeeklyReportGenerationTest`, `WeeklyAudioTest`, `WeeklyCloseTest` (con los 23 casos del estabilizador de `satisfaction-cases.json` de punta a punta), `WeeklyReportPdfTest` (también «Uso de IA») y los presupuestos de `WeeklyPagesPerformanceTest`. `SeedersTest` comprueba la semana en curso abierta.
- **Vitest:** `weeklies-report` (la página, los reproductores y las piezas puras) y `weeklies-ai-usage` (la página y la edición).
- **E2E:** `weekly-report.spec.ts` (generar con la IA de prueba, editar, cerrar y que se abra la siguiente; ver, filtrar, pantalla completa, imprimir y descargar; móvil y AA; «Uso de IA»). **Escrito, sin ejecutar en el Mac:** va a la CI, que ahora fija `GEMINI_DRIVER=fake` y `GOOGLE_TTS_DRIVER=fake`.

### Para desplegar (con el SSH)
- **`.env` del servidor** (lo pone el propietario): `GEMINI_API_KEY`, `GEMINI_MODEL` (uno vigente: 2.5 Flash se retira el 16/10) y `GOOGLE_TTS_API_KEY` (y, si cambia, `GOOGLE_TTS_VOICE`). `GEMINI_DRIVER=gemini` y `GOOGLE_TTS_DRIVER=google`: **nunca** `fake` en el servidor.
- **Horizon:** el supervisor `supervisor-ai` (cola `ai`, un proceso de 128 MB, 600 s) ya está en `config/horizon.php`; subir `MemoryLimit=640M` en `audax-horizon.service` (D-154) con `daemon-reload` y reinicio, anotado en `SERVIDOR-CAMBIOS.md`, y después `verificar.sh` y `comparar-webs.sh`. Sin el worker de la cola `ai`, el informe se queda «en cola» (la página lo deja volver a pedir a los 12 minutos).
- **Reverb:** nada nuevo en el servidor; el canal `weeklies.{id}` se autoriza en `/broadcasting/auth`.
- **Ficheros:** los MP3 van a `storage/app/private/weeklies/{id}/audio`, que ya entra en la copia nocturna (los adjuntos privados, D-029).
- **Gotenberg:** el PDF de la weekly usa el mismo motor que los informes (D-140).
- **Sin migraciones nuevas.**

### Para 10.4 y 10.5
- **10.4:** `WeeklyProjectStatus` sirve para la vista «Estado de proyectos» (D-148); la satisfacción por semana ya está en `client_satisfaction_snapshots` (F-132) y el enlace de cada cliente del informe lleva a su ficha.
- **10.5:** escuchar `WeeklyCycleClosed` (cola `ai`, tras la satisfacción) para el aviso «weekly cerrada» a todo el equipo activo (F-095), con su plantilla `weekly_closed` y su `trigger_key` en `weekly_reminder_logs`.

## 10.4 (hecho): clientes, equipo y estado de proyectos
Hecha el 05/10/2026 en `fase-10`. Decisiones nuevas: **D-194 a D-198**. En `WEEKLY-INVENTARIO.md` §A.4, 22 F pasan a «Hecho (10.4)» y se completan F-001, F-028, F-067 y F-133: ya no queda ninguna con la pantalla a medias.

### Datos
- **Migración `2026_10_05_140000_create_ai_summaries_table`:** `ai_summaries`, el último resumen con IA de cada tipo (`AiSummaryKind`: `client_summary`, `client_team_activity`, `person_performance` y `person_client_activity`) para cada cliente o persona (morph `subject`, único por tipo y sujeto). Lleva `state` (`WeeklyJobState`), `content` (Markdown), `items` (id → frase), `error`, `model`, `requested_by` y `generated_at`. Modelo `AiSummary`.
- **Sin más columnas:** `clients.icon` y `clients.satisfaction_score` ya estaban (10.1); ahora van en `ClientResource` y el icono se valida y guarda en `ClientRequest` (un solo emoji).

### Dominio
- **`Weeklies\ProjectStatus`** (D-196):
  - `ProjectKindCode`: el tipo de WeeklySync de un código (`for()`), su grupo (`tag()`), las insignias (`badges()`) y el orden (`tagIndex()`),
  - `ProjectStatusBoard`: `build(?today, ?clientIds)` (la cartera por cliente, cinco consultas) y `prefixesByClient(?clientIds)` (una consulta, para la lista de clientes). Reutiliza `WeeklyProjectStatus` (ahora con `currentBanks()` y `minutes()` públicos).
- **`Weeklies\Insights`** (D-194, D-195 y D-198):
  - `InsightPrompts`: los cuatro prompts portados, los recortes, el estado de proyectos del resumen, la tendencia, `summariesById()` y `summariesSchema()`,
  - `ClientInsights`: `mainOwner()`, las pestañas `summary()`, `history()`, `team()` y `satisfaction()`, `deltas()`, `satisfactionNow()` y los datos de la IA (`summaryWeeks()`, `summaryContext()` y `teamActivityPayload()`),
  - `ClientWeeklyTabs`: la prop `weekly` de la ficha, `myProjects()` y `joinableProjects()`,
  - `PersonInsights`: `directory()`, `profile()`, `habits()`, `clients()`, `performancePayload()` y `clientActivityPayload()`,
  - `AiSummaries`: `find()`, `request()` (encola), `generate()` (la llama el Job), `isBusy()` y `present()`.
- **Job `GenerateAiSummary`** (cola `ai`, un intento, `AiQueue::TIMEOUT`).
- **`FakeLlm::demo()`** responde también a los prompts con `INPUT_JSON` (una frase por id).

### Rutas, páginas y props
- **`weeklies.project-status`** (`/weeklies/estado-proyectos`, página `weeklies/project-status`): `ProjectStatusPageProps` (`clients`, `reference_date`). Tercera pestaña de `/weeklies` (`weekliesTabs()`), con el módulo `project_status`.
- **`clients.index`:** `?orden=nombre|ultimo_reporte|satisfaccion&dir=&tipo=&persona=&mios=1`. Cada fila lleva además `icon`, `satisfaction_score`, `last_report_at`, `satisfaction_trend` y `kind_badges`; la página, `weekly` (columnas de la Weekly) y `people` (diferida).
- **`clients.show`:** `?pestana=resumen|historial|equipo|satisfaccion`, con `tab`, `owner`, `kindBadges`, `weekly` (diferida, solo la pestaña abierta; null sin la Weekly), `joinable_projects` (opcional) y `can.useWeeklies`. Tipos en `resources/js/types/weekly-insights.ts` (`ClientWeeklyData`).
- **`clients.ai-summary`** y **`clients.team-activity`** (POST): encolan y vuelven (Inertia) o responden 202 con `{summary}` (JSON).
- **`team.index`** (`/equipo`, página `team/index`): `TeamIndexPageProps`. **`team.show`** (`/equipo/{user}`, página `team/show`): `TeamShowPageProps`, con `ai` null para quien no puede ver los resúmenes. **`team.ai-summary`** (POST, `tipo=desempeno|clientes`), con `view-person-ai-summary`.
- **Componentes** (`resources/js/components/weeklies/insights`): `project-kind` (código, tipo, insignias, barra y desviación), `project-status-view`, `ai-summary-panel` (y `useAiSummaryPolling`), `client-weekly-panels` (resumen, historial, equipo, satisfacción y `SatisfactionTrend`), `satisfaction-chart`, `team-ui` (ausencia y copiar el email) y `weeklies-tabs`. Piezas puras en `resources/js/lib/project-status.ts` y `resources/js/lib/team-filter.ts`. Textos en `lang/ui/weekly-insights.json`.
- **Navegación:** «Equipo» en la barra lateral; la tira del equipo enlaza a la ficha de cada persona; `useListFilters` gana `updateMany()`.

### Tests
- **Pest:** `ProjectStatusBoardTest` (con el fixture compartido `tests/fixtures/weeklies/project-status-board.json`, los tipos, las insignias, permisos y presupuesto), `ClientWeeklyInsightsTest` (cartera, orden, filtros, icono, pestañas, responsable, resúmenes con IA, `ai_usage` con `GeminiClient` y presupuestos), `TeamWeeklyInsightsTest` (lista, ausencias, ficha, hábitos, permisos de los resúmenes por persona y presupuestos) y `tests/Unit/Weeklies/InsightPromptsTest`. `WeeklyRoutesTest` deja de esperar 501 en lo de la 10.4.
- **Vitest:** `weeklies-insights` (las piezas puras con el mismo fixture, la vista, el panel de IA, el equipo y la satisfacción del cliente, la lista y la ficha del equipo) y la cartera en `clients-pages`; `weeklies-team-nav`, con los avatares como enlaces.
- **E2E:** `weekly-insights.spec.ts` (estado de proyectos, cartera, ficha de cliente con sus pestañas e IA de prueba, equipo y los resúmenes por persona; AA y 375 px). `SIDEBAR_PATHS` y `collaborator.spec.ts` incluyen «Equipo». **Escritos, sin ejecutar en el Mac:** van a la CI.

### Para desplegar (con el SSH)
- `migrate` (una migración nueva: `ai_summaries`). Nada más en el servidor: la cola `ai` y las claves son las de la 10.3.

### Para las siguientes entregas
- **10.6:** el asistente puede reutilizar `ClientInsights`, `PersonInsights` y `ProjectStatusBoard` para su contexto, respetando lo que ve quien pregunta (D-147 para lo de una persona).
- **10.8:** la satisfacción histórica va a `client_satisfaction_snapshots` (la serie y las tendencias de la ficha salen de ahí) y el icono, a `clients.icon`.

## 10.5 (hecho): avisos
Hecha el 05/10/2026 en `fase-10`. Decisiones nuevas: **D-199 a D-202**. En `WEEKLY-INVENTARIO.md` §A.4, F-037, F-095 y F-101 a F-110 pasan a «Hecho (10.5)» (F-103 ya existía con Web Push) y se completa F-010 (el acceso a los avisos).

### Dominio (`app/Domain/Weeklies/Reminders`)
- **`WeeklyReminderRecipients`:** `pending(cycle, ?ids)` (quien debe enviar y no ha enviado, con `WeeklyEligibility`: sin exentos, activos, nunca colaboradores) y `team()` (el equipo activo, para «weekly cerrada»).
- **`WeeklyReminderSchedule`** (puro): `due(rules, now, lookback=10)` con instantes reales de Madrid (medianoche y cambios de hora), `triggerKey()` y `minuteOfDay()`.
- **`WeeklyTemplates`:** `defaults()`, `all()` (con `is_default`), `get()`, `save()` (solo lo cambiado, con auditoría), `render()` y `normalize()`. Gemelo TS: `resources/js/lib/weekly-templates.ts`, con el fixture compartido `tests/fixtures/weeklies/template-render.json`.
- **`WeeklyNotifier`:** `send(kind, cycle, template, triggerKey, users, channels, make, ?sender, byPreferences)` (resuelve el canal de cada persona, reclama las filas del registro y manda una notificación por persona con `onlyChannels`), `claim()` (inserción en bloque, deduplicación por el índice único), `resolve()` y `logicalChannel()`. Devuelve `WeeklyNoticeResult(notified, skipped, duplicates)`.
- **`WeeklyReminders`:** `runDueRules(cycle)`, `sendManual()`, `remindOne()`, `notifyClosed()` y `notifyDeadline()`, con sus claves (D-201).
- **`TracksWeeklyReminderLogs`** (trait de las notificaciones): `afterSending()` deja la fila «enviada»; el listener `Listeners\Weeklies\MarkWeeklyReminderFailed` (`NotificationFailed`), «fallida» con el error.
- **Notificaciones** (`app/Notifications/Weeklies`): `WeeklyNotice` (base: foto de la semana, plantilla con variables, email con `mail.weeklies.notice` y botón), `WeeklyReminder` (`weeklies.reminder`), `WeeklyClosedNotice` (`weeklies.closed`) y `WeeklyDeadlineChanged` (`weeklies.deadline_changed`). `Time\WeekSubmissionReminder` lleva ahora las dos partes (`hours` y `weekly`).
- **Comandos:** `weeklies:remind` (cada 5 minutos, `routes/console.php`); `time:remind-week` manda el recordatorio unificado de los viernes (D-200) y se programa si las horas o la weekly del viernes están activas.
- **Listener `SendWeeklyClosedNotice`** de `WeeklyCycleClosed` (10.3): «weekly cerrada» a todo el equipo, una vez.
- **Catálogo:** grupo `weeklies` (audiencia `AUDIENCE_WEEKLIES`); `AppNotification::$onlyChannels`.
- **Ajustes:** `weekly_friday_reminder` (por defecto, sí), `retention_weekly_reminder_logs_months` (12) y `retention_dictations_months` (3).

### Rutas, páginas y props
- **`weeklies.reminders.edit`** (`GET /weeklies/avisos?plantilla=&estado=&pagina=`, página `weeklies/reminders`, `WeeklyRemindersPageProps`): `cycle`, `rules`, `templates` (con `is_default`), `defaults`, `variables`, `friday` (`{weekly, hours}`), `pending` (`UserSummary[]`), `logs` (paginado de 50, `WeeklyReminderLogRow` con `cycle_number` y `sent_by_name`), `filters`, `push_available` y `can.send`. Cuarta pestaña «Avisos» de `/weeklies` (`weekliesTabs(projectStatus, manage)`), solo con `manage-weeklies`; enlace desde «Weekly y módulos» de `/admin/ajustes`.
- **`weeklies.reminders.update`** (`PUT`): `{rules: [{id?, channel, day_of_week 1-7, time "HH:MM", enabled}] (la lista entera, máx. 20), templates?: {automatic|manual|weekly_closed: {subject ≤200, body ≤5000}}, friday_reminder?}`.
- **`weeklies.reminders.send`** (`POST /weeklies/avisos/enviar`): `{recipients: pending|users, user_ids[]?, template: automatic|manual, channels[]}`; 422 sin semana activa.
- **`weeklies.reminders.remind`** (`POST /weeklies/{cycle}/recordar`): `{user_id}`; aviso según el resultado (enviado, no lo necesita, ya enviado hace un momento o sin canal).
- **`weeklies.deadline.update`**: si cambia la fecha, avisa a las pendientes (salvo a quien lo cambia) y lo dice en el aviso.
- **Props nuevas:** `weeklies/index` `can.remind` y `can.reminders`; `team/index` y `team/show` `can.remind`.
- **Componentes** (`resources/js/components/weeklies/reminders`): `reminder-rules-editor` (y `rulesSummary`), `template-editor` (vista previa y «Restaurar»), `send-reminders-dialog`, `reminder-log` y `remind-button` (en el resumen, la lista de Equipo y la ficha de persona). Textos en `lang/ui/weekly-reminders.json`.

### RGPD (D-202)
- **Exportación** (`config('privacy.export_sections')`): `WeeklySubmissionsSection` (`weeklies-envios`), `WeeklyEntriesSection` (`weeklies-apuntes`), `DictationsSection` (`dictados`), `WeeklyExemptionsSection` (`weeklies-exenciones`), `AiSummariesSection` (`resumenes-ia`) y `WeeklyRemindersSection` (`weeklies-avisos`).
- **Plazos:** `RetentionPolicy::WEEKLY_REMINDER_LOGS` (`WeeklyReminderLogsPruner`) y `DICTATIONS` (`DictationsPruner`), editables en `/admin/privacidad`.
- **Auditoría:** entidades «Weekly (semanas y exenciones)» y «Avisos de la Weekly», y acciones de envíos manuales y de cambios de plantillas y del viernes.

### Tests
- **Pest:** `tests/Unit/Weeklies/WeeklyReminderScheduleTest` (margen, medianoche, los dos cambios de hora y el fixture de las plantillas) y, en `tests/Feature/Weeklies`, `WeeklyRemindersTest` (reglas, pendientes y exentos, deduplicación, canales y preferencias, resumen diario, navegador, zona horaria, envío real, fallos, consultas constantes, programación y «weekly cerrada»), `WeeklyReminderPagesTest` (permisos, props, reglas, validación, plantillas, viernes, envío manual, «Recordar», plazo cambiado y auditoría) y `WeeklyFridayReminderTest` (D-200); `tests/Feature/Privacy/WeeklyPersonalDataTest` (exportación y plazos). Al día: `NotificationPreferencesTest`, `NotificationSettingsPageTest`, `WeekSubmissionReminderTest` (una consulta más, la semana activa), `WeeklyRoutesTest` (ya no hay 501 en los avisos), `PrivacyPagesTest`, `PersonalDataExportTest`, `SeedersTest` y el presupuesto de `/weeklies/avisos` en `WeeklyPagesPerformanceTest`.
- **Vitest:** `weeklies-reminders` (el gemelo de las plantillas con el fixture, el resumen de reglas, la página, la vista previa, «Restaurar», guardar, filtros, sin semana activa, el envío manual y «Recordar»).
- **E2E:** `weekly-reminders.spec.ts` (programar una regla, el texto con vista previa y «Restaurar», enviar a las pendientes y verlo en el registro, «Recordar» desde el resumen, la plantilla sin acceso, AA y 375 px). **Escrito, sin ejecutar en el Mac:** va a la CI.

### Datos de ejemplo
El `DemoDataSeeder` añade dos reglas: en la app el jueves a las 10:00 y por email el viernes a las 16:00 (la de WeeklySync).

### Para desplegar (con el SSH)
- **Sin migraciones nuevas** (los valores nuevos de los enums caben en sus columnas de texto). Nada nuevo en systemd: `audax-scheduler.service` ya ejecuta `schedule:work`, que lanzará `weeklies:remind` cada 5 minutos.
- **Correo:** sale por la cola `mail` con el SMTP que ya funciona (el relé de Google). Sin cambios en el `.env`. El Web Push necesita las claves VAPID que ya usa el chat.
- **Mientras se usa WeeklySync** (hasta la 10.9): Audax ya abre semanas y, con esta entrega, recordaría la weekly a la plantilla, que aún la escribe en WeeklySync. Hasta el cambio, **dejar sin reglas** «Avisos de la Weekly» y **desactivar la weekly del recordatorio de los viernes** (o el módulo de la Weekly) en producción, para no avisar dos veces ni de una weekly que se escribe en otra app. En la 10.9, la importación (10.8) trae las reglas y plantillas de WeeklySync, se activa todo aquí y se apaga la GitHub Action.
- Después, como siempre, `verificar.sh` y `comparar-webs.sh` si se toca el servidor.

### Para las siguientes entregas
- **10.8:** las reglas de `email_reminders` (canal `email`) y `web_notification_reminders` (canal `push`), pasando el día de 0 = domingo a ISO; las plantillas de `email_templates` a `weekly_email_templates` (solo las que difieran); `email_log` a `weekly_reminder_logs` (`template`, `status`, `trigger_key`, `week_id` → semana importada, canal `email`).

## 10.6 (hecho): tareas de Mi espacio y asistente
Hecha el 05/10/2026 en `fase-10`. Decisiones nuevas: **D-203 a D-206**. En `WEEKLY-INVENTARIO.md` §A.4, F-006, F-055 a F-063, F-146 y F-147 pasan a «Hecho (10.6)» y se completa F-041 (la pestaña «Tareas»).

### Datos
- **Migración `2026_10_05_160000_create_task_suggestion_batches_table`:** `task_suggestion_batches`, la última tanda de tareas sugeridas de cada persona (única por `user_id`, en cascada al borrarla): `weekly_cycle_id` (la weekly de origen), `state` (`WeeklyJobState`), `items` (las propuestas: `{key, title, client_id, client_name, project_id, hour_bank_id, author_id, author_name}`), `skipped` (repetidas omitidas), `error`, `model` y `generated_at`. Modelo `TaskSuggestionBatch`.
- **Sin más tablas:** el archivado personal es `task_archives` y el dictado de las notas, `dictations` con el contexto `task_note` (contrato 10.1).

### Dominio
- **`Weeklies\Tasks`:**
  - `MySpaceTasks`: `list(user)` (mis tareas asignadas: las pendientes y las 100 últimas hechas, con cliente, proyecto, estado, «De: …», notas, archivado y `can`), `catalog(user)` (proyectos abiertos en los que puedo crear, con sus bolsas abiertas), `toggleStatuses()` y `editableProjects(user)`,
  - `TaskNotes` (puro): `isPlain()`, `toPlain()` y `toHtml()` (D-203),
  - `TaskSuggestionPrompt` (puro): el prompt de `extract-tasks`, `report()`, `schema()`, `rows()`, `id()` e `isDuplicate()` (la deduplicación de App.tsx),
  - `TaskSuggester`: `sourceCycle()`, `find()`, `request()` (encola; 422 sin weekly cerrada), `isBusy()`, `generate()` (la llama el Job), `proposals()`, `accept()` (con `TaskWriter`, todo o nada), `remove()` y `present()`.
- **`Weeklies\Assistant`:** `AssistantContext` (`scope(user)` y `for(user)`, D-205), `AssistantPrompt` (puro: `truncate()`, `contextText()` y `prompt()`) y `AssistantQuestions` (`ask()`, `find()`, `answer()` y `present()`, en la caché una hora, D-206).
- **Jobs** (cola `ai`, un intento, `AiQueue::TIMEOUT`): `SuggestTasksFromWeekly` y `AnswerAssistantQuestion`.
- **Evento `Events\Weeklies\AssistantAnswered`:** `assistant.answered` por el canal privado `App.Models.User.{id}`, con `{question_id, state}`.
- **`FakeLlm::demo()`** propone una tarea de prueba para quien la pide (`SuggestedTasks`).
- **Privacidad:** `MySpaceTasksSection` (`mi-espacio-tareas`) en la exportación de datos.

### Rutas, páginas y props
- **`my-space/index` con `?pestana=tareas`** (`MySpacePageProps`): `my_tasks` (`MySpaceTask[]`), `task_projects` (`MySpaceTaskProject[]`), `task_statuses` (`{open, done}`), `suggestions` (`TaskSuggestionBatch` o null) y `suggestion_source` (la última weekly cerrada). Con otra pestaña llegan a null sin consultas.
- **Acciones de Mi espacio:**
  - `POST my-space.tasks.suggest` (202 en JSON; si no, vuelve con aviso),
  - `POST my-space.tasks.suggestions.accept` (`{tasks: [{key, title, project_id, hour_bank_id?, priority?, due_date?}], dismiss?: [key]}`; errores en `tasks.{i}.campo`),
  - `DELETE my-space.tasks.suggestions.dismiss` (`{keys?}`; sin claves, toda la tanda),
  - `POST`/`DELETE my-space.tasks.archive`/`unarchive`,
  - `PUT my-space.tasks.notes` (`{notes}`, JSON; 422 si la descripción tiene formato),
  - crear, editar, marcar hecha y borrar: las rutas `tasks.store`, `tasks.update` y `tasks.destroy` de siempre.
- **`dictations.store`** acepta `context=task_note` con `task_id` (una tarea que puedo editar y con notas en texto plano).
- **`assistant/index`** (`AssistantPageProps`): `suggested_questions`, `scope` (`{weeklies, clients, project_status, hours: own|team|all, financials}`) y `max_question`. **`POST assistant.ask`** (`{question, history?: [{role, content}]}` → 202 `{question}`) y **`GET assistant.questions.show`** (`/ia/preguntas/{uuid}`, solo quien pregunta; 404 si no o si caducó).
- **Componentes:** `components/weeklies/tasks` (`my-space-tasks`, `my-space-task-row`, `my-space-task-dialogs`, `my-space-task-fields`, `task-notes-field` y `task-suggestions-panel`), `components/assistant/use-assistant` y la página `assistant/index`. Piezas puras en `resources/js/lib/my-space-tasks.ts`. El dictado (`DictationButton`, `useDictation` y `uploadDictation`) admite `taskId`. Textos en `lang/ui/my-space-tasks.json` y `lang/ui/assistant.json`.
- **Navegación:** «Asistente IA» en la barra lateral, tras Chat (F-006).

### Tests
- **Pest:** `tests/Feature/Weeklies/MySpaceTasksTest.php` (la pestaña y el catálogo, archivado personal, notas, dictado de notas, la petición en la cola `ai`, el prompt sin borradores, la deduplicación, solo para mí, el proyecto y la bolsa sugeridos, el fallo de la IA, la revisión antes de crear, todo o nada, descartar y la IA de prueba) y `AssistantTest.php` (página, acceso de colaboradores, clientes y módulo apagado, validación, la cola `ai`, Reverb y sondeo solo para quien pregunta, fallo, `ai_usage` con `GeminiClient`, y que el contexto **nunca** lleva borradores ajenos, tareas archivadas, horas de otros sin permiso, importes sin `view-financials`, costes ni resúmenes de una persona). Al día: `WeeklyRoutesTest`, `WeeklyDictationTest`, `WeeklyPagesPerformanceTest` (presupuestos de la pestaña y de `/ia`), `WeeklyPersonalDataTest` y `PersonalDataExportTest`.
- **Vitest:** `my-space-tasks` (piezas puras, la pestaña, marcar hecha, archivar, generar, la revisión de las propuestas, los errores del servidor, descartar y las notas con autoguardado y dictado) y `assistant-page` (preguntas sugeridas, cola y sondeo, Intro y Mayúsculas+Intro, la conversación de la sesión, errores y la entrada de la barra lateral).
- **E2E:** `my-space-tasks.spec.ts` (crear con proyecto, nota, hecha, archivar y recuperar; generar con la IA de prueba, revisar y crear; el asistente con la conversación al recargar; AA y 375 px). `SIDEBAR_PATHS` incluye `/ia` y `collaborator.spec.ts`, su 403. **Escritos, sin ejecutar en el Mac:** van a la CI.

### Para desplegar (con el SSH)
- `migrate` (una migración nueva: `task_suggestion_batches`). Nada más en el servidor: la cola `ai`, las claves y Reverb son los de la 10.3.

### Para las siguientes entregas
- **10.8:** las 36 tareas de WeeklySync (todas hechas, sin proyecto) se migran como tareas hechas si su cliente tiene un proyecto que case (D-149); su `archived` pasa a `task_archives` de su responsable.

## 10.7 (hecho): centro de ayuda y sugerencias
Hecha el 05/10/2026 en `fase-10`. Decisiones nuevas: **D-207 a D-212**. En `WEEKLY-INVENTARIO.md` §A.4, F-148 a F-153, F-155 a F-168 y F-170 pasan a «Hecho (10.7)»; F-154 (el editor) se usa en las nuevas pantallas y se completa F-010 («Ayuda» en la barra lateral). Ya no queda ninguna F pendiente salvo la migración (10.8) y el cierre (10.9).

### Datos
- **Sin migraciones nuevas:** las tablas son las del contrato 10.1 (`help_*` y `suggestion_*`). Los ajustes `help_manual` (`{disk, path, name, size}`) y `help_support_url` ya estaban en `Setting::DEFAULTS`.
- **Cambios compatibles del contrato:**
  - `HelpRelease::versionLabel()` devuelve «V.1.10.2», como WeeklySync (antes «V1.10.2»),
  - los modelos de la ayuda, los tableros, las categorías y las sugerencias usan `LogsDomainActivity` (`SuggestionPost` sin los contadores, el orden ni la actividad),
  - `AttachmentStorage::store()` admite `SuggestionPost` y `SuggestionComment` (en `attachments/suggestions/{post}[/comments]`) y `AttachmentPolicy` sirve los adjuntos de la ayuda y las sugerencias (D-210),
  - se quita el trait `PendingDelivery` y la clave `weeklies.not_implemented`: ya no queda ninguna ruta 501,
  - los tipos de la ayuda y las sugerencias de `resources/js/types/weeklies.ts` se rehacen con su forma real (`HelpUpdateEntry`, `HelpReleaseOption`, `SuggestionPostDetail`, `SuggestionsTabProps`…).

### Dominio
- **`Weeklies\Help`:**
  - `HelpReleaseCalendar` (puro): `versionFor()`, `label()`, `publishedOn()`, `hasContent()`, `isInProgress()` y `withStatuses()` (port de `helpCenter.ts`),
  - `HelpCenter`: `ensureCurrentRelease()`, `settings()`, `updates(viewer)`, `releaseOptions()`, `tutorials()` (con `tutorialData()`) y `faqSections()`,
  - `TutorialVideoUploads` (D-207): `start()`, `append()`, `attach()`, `owns()`, `discard()` y `prune()`; `MAX_BYTES` (200 MB), `CHUNK_BYTES` (8 MB) y los tipos de vídeo admitidos.
- **`Weeklies\Suggestions`:**
  - `SuggestionQueries`: `boards()` (con recuentos), `feed()`, `roadmap()`, `similar()` y `detail()`,
  - `SuggestionBoardView::page()`: la prop de la pestaña con los filtros de la URL, y `mentionables()`,
  - `SuggestionPresenter`: la forma JSON (tablero, categoría, sugerencia, detalle con el árbol de comentarios, adjuntos),
  - `SuggestionWriter`: `create()`, `update()`, `delete()`, `toggleVote()`, `comment()`, `updateComment()`, `deleteComment()`, `react()`, `changeStatus()` y `move()`, con los avisos (D-209).
- **Notificaciones** (`app/Notifications/Suggestions`): `SuggestionStatusChanged`, `SuggestionReplied` y `SuggestionMentioned`, en el grupo `suggestions` del catálogo (audiencia `AUDIENCE_SUGGESTIONS`).
- **Evento `Events\Weeklies\HelpCenterChanged`** (`help.changed`, canal privado `help`, D-212).
- **Comando `help:prune-uploads`** (cada noche a las 03:35).
- **Privacidad:** `SuggestionsSection`, `SuggestionCommentsSection`, `SuggestionVotesSection` y `HelpLikesSection` en `config('privacy.export_sections')`.
- **Auditoría:** entidades `help` y `suggestion`, acciones `help_settings_changed` y `suggestion_status_changed`.

### Rutas, páginas y props
- **`help/index`** (`HelpController::index` y `page()`, `HelpPageProps`): `tab`, `can` (`manage`, `suggestions`), `settings`, `updates` (General), `releases` (Tutoriales, y General para quien gestiona), `tutorials`, `faq_sections`, `suggestions` (`SuggestionsTabProps`) y `upload` (`video_max_bytes`, `video_chunk_bytes`, `attachment_max_mb`). La pestaña de sugerencias, solo con su módulo.
- **`suggestions.show`** (`/ayuda/sugerencias/{id}`): la misma página con la sugerencia abierta (`suggestions.post`). 404 a quien no gestiona si su tablero está oculto.
- **Filtros de la pestaña de sugerencias** (en la URL): `vista=roadmap|feedback`, `tablero`, `categoria`, `q`, `orden`, `estados`, `limite` y `nueva=1|bug`.
- **Rutas nuevas** (además de las del contrato): `help.tutorials.uploads.store` y `.chunk` (subida por trozos), `help.faq-sections.reorder`, `help.faqs.reorder`, `suggestions.similar` (JSON), `suggestions.boards.reorder` y `suggestions.categories.reorder`. `help.manual` y `help.tutorials.video` van con `signed:relative`.
- **Cuerpos:** `help.releases.store/update` (`{major_version, month_number, week_of_month, summary?, is_hidden?, changes?: [{id?, description}]}`; sin `summary` ni `changes`, se conservan), `help.updates.*` (`{published_on, title, subtitle, body}`), `help.likes.toggle` (`{kind: release|manual, id}`), `help.tutorials.store/update` (`{title, description?, help_release_id?, upload?}`), `help.settings.update` (multipart: `{support_url?, manual?, remove_manual?}`), reordenar (`{ids}`; las preguntas, con `help_faq_section_id`), `suggestions.store/update` (multipart: `{title, body, suggestion_board_id, suggestion_category_id?, files[], remove_attachment_ids[]}`), `suggestions.comments.store/update` (multipart: `{body, parent_id?, files[], remove_attachment_ids[]}`), `suggestions.comments.react` (`{reaction}`), `suggestions.status.update` (`{status, note?}`) y `suggestions.position.update` (`{status, before_id?, after_id?}`).
- **Componentes:** `components/help` (`help-general`, `help-dialogs`, `help-tutorials`, `help-faq`, `sortable-list` y `use-help-live`) y `components/suggestions` (`suggestions-tab`, `suggestion-feed`, `suggestion-roadmap`, `suggestion-detail`, `suggestion-comments`, `suggestion-composer`, `suggestion-taxonomy`, `suggestion-ui` y `use-suggestion-query`). Piezas puras en `lib/help-center.ts`, `lib/suggestions.ts` y `lib/video-upload.ts`. Textos en `lang/ui/help.json`, `lang/ui/suggestions.json` y `lang/es/help.php`.
- **Navegación:** «Ayuda» en la barra lateral, tras el asistente (F-010).

### Tests
- **Pest:** `tests/Unit/Weeklies/HelpReleaseCalendarTest`, `tests/Feature/Weeklies/HelpCenterTest` (permisos, pestañas, versión automática y estados, versiones con sus cambios, ocultar, actualizaciones, «me gusta», manual y soporte, subida por trozos, vídeo con Range y URL firmada, sustituir y borrar, no vídeo, reordenar, limpieza de subidas, preguntas frecuentes, canal `help`), `tests/Feature/Weeklies/SuggestionsTest` (crear con adjuntos y menciones, validación, editar y borrar con adjuntos, votos únicos, comentarios anidados, avisos, reacciones, estados con historial, roadmap, feed y búsqueda global, similares, tableros y categorías, módulo apagado) y `tests/Feature/Privacy/HelpPersonalDataTest`; presupuestos en `WeeklyPagesPerformanceTest`. Al día: `WeeklyRoutesTest` (sin 501), `WeeklySchemaTest`, `PersonalDataExportTest`, `NotificationPreferencesTest` y `NotificationSettingsPageTest` (grupo «Sugerencias»).
- **Vitest:** `help-center` (piezas puras, la subida por trozos con reintento, General, gestión, «Reportar un bug», Tutoriales con el límite de 200 MB y Preguntas frecuentes) y `suggestions` (piezas puras, Feedback con el voto al momento, el formulario de bug, el Roadmap con «Mover a…», el detalle con la actividad y las reacciones y la moderación).
- **E2E:** `help-center.spec.ts` (novedad con «me gusta» y búsqueda, preguntas frecuentes, sugerencia con comentario y reacción, «Reportar un bug», moderación y roadmap; AA y 375 px). `SIDEBAR_PATHS` incluye `/ayuda` y `collaborator.spec.ts`, su 403. **Escritos, sin ejecutar en el Mac:** van a la CI.

### Para desplegar (con el SSH)
- **Sin migraciones.** El programador (`schedule:work`) lanzará `help:prune-uploads` cada noche.
- **Límites de subida: no hace falta tocar nada.** Cada trozo del vídeo pesa 8 MB, por debajo de `upload_max_filesize` (55 MB), `post_max_size` (64 MB) y `client_max_body_size` (128 MB), y cada petición dura segundos (`max_execution_time` 60 s). Si algún día ModSecurity bloqueara las subidas por trozos (muchas peticiones seguidas a `/ayuda/tutoriales/subidas/…`), se ajusta solo para este dominio, con aprobación (RUNBOOK A4).
- **Disco:** los vídeos van a `shared/storage/app/private/help/tutorials` (los 4 de WeeklySync suman 135 MB; con la 10.8) y los trozos temporales a `help/uploads` (borrados a las 24 h). Hay 47 GB libres; la copia nocturna los incluye. El aviso de almacenamiento (D-076) sigue mirando el disco.
- **Reverb:** nada nuevo en el servidor; el canal `help` se autoriza en `/broadcasting/auth`.
- Después, como siempre, `verificar.sh` y `comparar-webs.sh` si se toca el servidor.

### Para la 10.8
- La ayuda de WeeklySync: `help_releases` (con `is_hidden`) y `help_release_changes`, `help_manual_updates` (`content_markdown` en Markdown o HTML → `body` saneado con `RichText`), `help_update_likes` (`release_id`/`manual_update_id` → `likeable`), `help_tutorials` (el vídeo del bucket `help-content` → `Attachment` en `help/tutorials`), `help_faq_sections` y `help_faqs`, y `help_settings` (`manual_path` y `support_url` → `help_manual` y `help_support_url`).
- Las sugerencias: tableros, categorías (la de bugs ya existe: casar por slug), la propuesta con sus votos, comentarios (`parent_comment_id` → `parent_id`), reacciones (`reaction_key`), eventos de estado y adjuntos (bucket `suggestion-attachments` → `Attachment`). El cuerpo y los comentarios en Markdown con menciones `@[Nombre](user:uuid)` → HTML de RichText con `<span data-type="mention" …>` y el id de Audax. Recontar `vote_count` y `comment_count` y dar `position` por `last_activity_at` dentro de cada estado.

## 10.8 (hecho): migración de los datos
Hecha el 07/10/2026 en `fase-10`. Decisiones nuevas: **D-213 a D-220**. Sin migraciones. Antes de empezar se pasó la batería completa de Pest (la 10.7 no lo había hecho): 4.299 en verde.

### Qué hay
- **Volcador** (`app:dump-weeklysync <carpeta> [--credenciales=] [--sin-ficheros]`, D-213), para el Mac del propietario:
  - `App\Domain\Import\WeeklySync\Dump`: `WeeklySyncCredentials` (fichero 600, valores ocultos), `PostgresWeeklySyncSource` (transacción `READ ONLY` y `REPEATABLE READ`, TLS), `SupabaseStorage` (solo `GET`), `WeeklySyncDumper` (carpeta 700, ficheros 600, manifiesto con sha256) y `WeeklySyncConnector` (lo sustituyen los tests).
  - Solo descarga los ficheros a los que apunta alguna fila (`WeeklySyncDump::references()`).
- **Importador** (`app:import-weeklysync <carpeta> [--dry-run] [--personas=] [--clientes=]`, D-214 a D-220), para el servidor:
  - `WeeklySyncDump` (lee y verifica el volcado), `WeeklySyncMappings` (ficheros de correspondencias), `WeeklySyncNames`, `WeeklySyncText` (Markdown y menciones → RichText), `WeeklySyncFiles` (copia con ruta fija y sha256), `WeeklySyncImportReport` y `WeeklySyncImporter`,
  - etapas en `Stages/`: `PeopleStage`, `ClientsStage`, `WeeksStage` (semanas, informe, audio, exenciones, envíos, borradores y satisfacción), `TasksStage`, `RemindersStage`, `HelpStage`, `SuggestionsStage` y `AiUsageStage`,
  - reutiliza `ImportRefs`, `ImportOutput` y `SilentOutput` del importador de ClickUp; la consola, `App\Console\Support\CommandImportOutput`,
  - auditoría: evento `weeklysync_import` en la acción «importado».

### Tests
- `tests/Feature/Import/WeeklySyncImportTest.php`: casamientos (correo, identidad, fichero, nombre normalizado, códigos de proyecto y factura), inactivos, informe con los ids reescritos, `expected_user_ids`, exenciones, envíos y borradores, unión de duplicados, satisfacción, audios y ficheros, tareas, avisos, ayuda, sugerencias, uso de IA, idempotencia (dos pasadas = mismo resultado), `--dry-run`, lo escrito en Audax, la semana activa, la corrección de correspondencias, el volcado tocado y el comando.
- `tests/Feature/Import/WeeklySyncDumpTest.php`: el volcador con dobles reproduce el volcado de ejemplo byte a byte, tablas que no existen, carpeta no vacía, rutas del Storage, credenciales (permisos, que nunca salen en pantalla), DSN de PDO y el Storage con `Http::fake`.
- **Volcado de ejemplo** en `tests/fixtures/weeklysync/` (datos inventados): lo generó el propio volcador con un origen en memoria. Si cambia el formato, el primer test de `WeeklySyncDumpTest` falla y hay que regenerarlo igual.

### Procedimiento de la migración
Se hace dos veces: un **ensayo** cuando se quiera y la **definitiva** en la 10.9, con WeeklySync congelado. La segunda pasada sobre la misma base trae solo lo nuevo (D-214).

**1. Credenciales (en el Panel de Supabase, proyecto de WeeklySync).** Hacen falta cuatro valores:

| Clave | Dónde | ¿Secreta? |
|---|---|---|
| `WEEKLYSYNC_DB_URL` | Botón **«Connect»** (arriba) → «Connection string» → **«Session pooler»** (la «Direct connection» solo va por IPv6). Copia la URI `postgresql://postgres.<ref>:[YOUR-PASSWORD]@aws-0-<región>.pooler.supabase.com:5432/postgres` **quitando `:[YOUR-PASSWORD]`**. | No |
| `WEEKLYSYNC_DB_PASSWORD` | La contraseña de la base que se puso al crear el proyecto. Si no se conoce: **Project Settings → Database → «Reset database password»** (la app y sus funciones usan las claves de la API, no esta contraseña; compruébalo antes de cambiarla). | **Sí** |
| `WEEKLYSYNC_URL` | **Project Settings → Data API** (o «API») → «Project URL»: `https://<ref>.supabase.co`. | No |
| `WEEKLYSYNC_SERVICE_KEY` | **Project Settings → API Keys**: mejor una **clave secreta nueva** solo para esto («Create new secret key», `sb_secret_…`), que se borra al terminar; si no, la `service_role` de «Legacy API keys» («Reveal»). | **Sí** |

En el Mac, en una ventana de Terminal (zsh), sin que las secretas salgan en pantalla ni en el historial:

```zsh
mkdir -p ~/.config/audax && chmod 700 ~/.config/audax
umask 077
f=~/.config/audax/weeklysync.env
print -r -- "WEEKLYSYNC_DB_URL=postgresql://postgres.<ref>@aws-0-<región>.pooler.supabase.com:5432/postgres" > $f
print -r -- "WEEKLYSYNC_URL=https://<ref>.supabase.co" >> $f
stty -echo; IFS= read -r 'p?Contraseña de la base: '; stty echo; print
print -r -- "WEEKLYSYNC_DB_PASSWORD=$p" >> $f; unset p
stty -echo; IFS= read -r 'k?Clave secreta de Supabase: '; stty echo; print
print -r -- "WEEKLYSYNC_SERVICE_KEY=$k" >> $f; unset k
chmod 600 $f
```

Para comprobarlo sin ver los valores: `cut -d= -f1 ~/.config/audax/weeklysync.env` (solo los nombres) y `ls -l ~/.config/audax/weeklysync.env` (`-rw-------`).

**2. Volcado (en el Mac, en la copia de trabajo con la 10.8).** No escribe nada en Supabase y no usa la base local:

```zsh
export PATH=/usr/local/opt/php@8.4/bin:$PATH
d=~/weeklysync-volcado-$(date +%Y%m%d-%H%M)
php artisan app:dump-weeklysync $d
```

Muestra las filas de cada tabla, los ficheros descargados (MB) y los que falten en el Storage. Deben salir unas 13 personas, 50 clientes, 28 semanas, 267 envíos, 1.695 apuntes, 27 borradores (196 apuntes), 602 registros de correo, 4 vídeos y 1 manual (WEEKLY-INVENTARIO §C).

**3. Subida al servidor** (al usuario de la app, en su almacenamiento privado, 700/600):

```zsh
ssh audax-projects 'mkdir -p -m 700 /var/www/vhosts/projects.audaxstudio.com/app/shared/storage/app/private/weeklysync-import'
rsync -a --chmod=D700,F600 $d/ audax-projects:/var/www/vhosts/projects.audaxstudio.com/app/shared/storage/app/private/weeklysync-import/
```

**4. Copia de la base** antes de importar, como en `DEPLOY.md` §8 (paso 1), con el nombre `antes-de-weeklysync.dump`. Los ficheros importados son nuevos (rutas `*-weeklysync.*`): no sustituyen nada.

**5. Simulación** (no guarda nada ni copia ficheros):

```bash
ssh audax-projects
cd /var/www/vhosts/projects.audaxstudio.com/app/current
V=/var/www/vhosts/projects.audaxstudio.com/app/shared/storage/app/private/weeklysync-import
scripts/heavy.sh /opt/plesk/php/8.4/bin/php artisan app:import-weeklysync $V --dry-run
```

Revisar en el informe:
- la tabla «Tabla / Volcado / Leídas / Importadas / Omitidas / Cuadra»: **todas en «sí»**,
- los avisos: personas creadas inactivas, clientes creados inactivos, clientes casados por códigos o factura (revisarlos), tareas no migradas y clientes del informe sin pareja,
- lo omitido y su motivo.

Si una persona o un cliente no casa como debe, se escribe su correspondencia en `$V/personas.json` o `$V/clientes.json` (formato en `WeeklySyncMappings`; con `chmod 600`) y se repite la simulación.

**6. Importación de verdad:** el mismo comando sin `--dry-run`. Si se corta, se vuelve a lanzar: continúa sin duplicar.

**7. Comprobaciones:**
- el informe cuadra igual que en la simulación y no quedan ficheros «que no están en el volcado» sin explicar,
- `scripts/heavy.sh /opt/plesk/php/8.4/bin/php artisan tinker --execute="dump(App\Models\WeeklyCycle::count(), App\Models\WeeklySubmission::whereNotNull('submitted_at')->count(), App\Models\WeeklySubmission::whereNull('submitted_at')->count(), App\Models\ClientSatisfactionSnapshot::count(), App\Models\WeeklyReminderLog::count(), App\Models\HelpFaq::count(), App\Models\AiUsage::count());"`: las semanas, los envíos, los borradores sin enviar, la satisfacción, el registro, las preguntas y el uso de IA, frente al informe,
- en la app: el histórico de `/weeklies` con sus 28 semanas, el informe y el audio de una semana cerrada, la satisfacción en la ficha de un cliente, `/ayuda` (vídeo de un tutorial, manual y preguntas), las sugerencias, «Avisos» de `/weeklies` (reglas, plantillas y registro) y «Uso de IA» con 90 días,
- en `/admin/auditoria`, una sola entrada «Importación de WeeklySync».
No es un cambio del sistema: no hace falta `verificar.sh` ni `comparar-webs.sh`.

**8. Borrado del volcado y de las credenciales** (después de comprobarlo):

```bash
# En el servidor
rm -rf /var/www/vhosts/projects.audaxstudio.com/app/shared/storage/app/private/weeklysync-import
```

```zsh
# En el Mac
rm -rf ~/weeklysync-volcado-*
rm ~/.config/audax/weeklysync.env
```

En Supabase, borrar la clave secreta creada para el volcado. La copia nocturna de esa noche puede llevar el volcado: son los mismos datos que ya están en la base y caduca con ella. En la 10.9, tras la última pasada, se rotan y borran todas las claves (`service_role`, contraseña de la base y GCP) al dar de baja Supabase.

### Para la 10.9
- **Probar la Weekly antes del cambio (modo de prueba, D-239):** en `/admin/ajustes` → «Weekly y módulos», con los módulos apagados, encender «Modo de prueba para los admins» y guardar. Los admins ven y usan la Weekly entera (en la barra lateral, el bloque «Weekly»; en cada página, el aviso «Modo de prueba»); el resto de la plantilla no la ve (404). No sale ningún aviso ni recordatorio a nadie y los comandos programados siguen parados. Para desactivarlo, apagar el mismo interruptor y guardar. En el cambio definitivo se encienden los módulos (el modo de prueba deja de influir) y se puede volver a apagar. Por SSH (`audax-projects`, en `current`), si hiciera falta: `/opt/plesk/php/8.4/bin/php artisan tinker --execute "App\Models\Setting::set('modules_preview', true)"` (o `false`).
- La última pasada, con WeeklySync congelado (sin escritura) y sus recordatorios apagados: los pasos 1 a 8 otra vez. Después, activar las reglas importadas en «Avisos» y la weekly del recordatorio de los viernes (10.5) y apagar la GitHub Action.
- Revisar en la app lo creado inactivo (personas y clientes) por si conviene fusionarlo a mano.

## 10.9a (hecho): fallos E2E y revisión de seguridad
La suite completa de Playwright dio 8 fallos, todos en specs nuevos de la Fase 10 que no se habían ejecutado nunca, y la revisión de seguridad de la fase dejó un hallazgo alto, dos medios y cuatro bajos. Decisiones D-221 a D-226.

### Los 8 fallos E2E
| Spec | Causa | Arreglo |
|---|---|---|
| `help-center.spec.ts:32` y `:114` | El test abría páginas con `browser.newPage()`, que axe no admite («Please use browser.newContext()»). Tras arreglarlo salió un fallo real: el número de comentarios del roadmap y del feed era un `<span aria-label>` sin rol (`aria-prohibited-attr`). | Test: un contexto por persona. App: el número va con texto oculto (`sr-only`), con test Vitest. |
| `help-center.spec.ts:81` | El test pulsaba Escape con dos diálogos abiertos (el de la sección aún cerrándose) y buscaba la sección con una expresión que casaba con los cinco botones de «Gestionar secciones». | Espera a un solo diálogo, lo cierra y pulsa la sección dentro de la navegación «Secciones»; la pregunta, con nombre exacto. |
| `weeklies.spec.ts:64` | Al recargar, la caja enviada sale plegada: el texto está en el botón y en el `textarea` oculto, así que `getByText` daba 2. Después, el avatar de la tira del equipo es un enlace desde la 10.4, no una imagen, y axe encontró un fallo real: las iniciales de quien falta o está exento tenían contraste 4,15 (`opacity-60`). | Test: comprueba el texto del botón y el valor del `textarea`; los avatares por su rol de enlace. App: la opacidad solo se aplica a la foto (`[&_img]:opacity-60`), con test Vitest. |
| `weeklies.spec.ts:147` | El avatar de los exentos es un enlace (no `img`), y en una base ya usada había más de un «Exención manual». | Por rol de enlace y mirando solo la fila de la persona. |
| `weekly-insights.spec.ts:31` y `:146` | `getByLabel('Tipo')` y `getByLabel('Estado del reporte')` casaban también con «Proyectos abiertos por tipo» y «Ordenar por Estado del reporte». | `exact: true`. |
| `weekly-reminders.spec.ts:30` | `getByText('Por defecto')` casaba también con el botón «Restaurar por defecto». | `exact: true`. |

### Seguridad
- **Alta, «Unirme a proyectos» (D-221):** la Weekly ya no toca `project_members`. «Unirme a clientes» crea una fila en `weekly_client_subscriptions` (migración `2026_10_06_100000`, `WeeklyClientSubscriptions`), que solo hace salir el cliente propuesto en «Mi weekly» (`MyWeeklyClients`), en «Mis clientes» y en los clientes de la ficha de persona (`PersonInsights`), y a la persona en el equipo del cliente (`ClientInsights::teamMembers`). Rutas `weeklies.clients.join` y `.leave`; `/mi-espacio/proyectos` da 404. El importador lleva `client_team_members` a esa tabla (`ClientTeamStage`). Tests de que no da chat (ni su histórico), horas, tareas ni bolsas.
- **Media, coste de Gemini (D-222):** `AiDailyLimits` (60 preguntas, 30 resúmenes y 10 tandas de tareas por persona y día, configurables), una pregunta en curso por persona, todos los Jobs de IA únicos (`ShouldBeUnique`) y la cola prioritaria `ai-high` (informe, audio y satisfacción) en el mismo `supervisor-ai`, sin balanceo.
- **Media, límites compartidos:** las 75 rutas con `throttle:N,M` sin prefijo (de todas las fases) llevan ahora su nombre de ruta como prefijo, así que cada una tiene su contador. Un test recorre todas las rutas y exige prefijo y que no se comparta salvo los grupos a propósito (`home-layout`, chat, Google y tiempo real). Tests de sondeo y acción: estado del informe → «Generar», respuesta del asistente → «Preguntar» y autoguardado → «Enviar».
- **Bajas:** cuotas de subida (D-223: una subida de vídeo abierta por persona, 1 GB a medio subir, 250 MB de adjuntos de sugerencias por persona y 5 GB libres como mínimo; límite de peticiones al editar sugerencias y comentarios), resúmenes con IA sin enlaces activos y con el dominio visible (D-224), `ai_usage` en la exportación RGPD (`uso-ia`) y anonimizado a los 12 meses (D-225), y tableros ocultos sin votos, comentarios, reacciones ni descarga de adjuntos para la plantilla (D-226).

### Tests nuevos
- **Pest:** `WeeklyMiscTest` (unirse y dejar, sin acceso a nada más, propuestos y equipo), `WeeklyAiLimitsTest`, `WeeklyRateLimitTest`, `HelpUploadQuotaTest`, los tableros ocultos en `SuggestionsTest`, el uso de la IA en `WeeklyPersonalDataTest` y la importación de `client_team_members` en `WeeklySyncImportTest` (con su tabla en el volcado de ejemplo). Al día: `QueueConfigTest`, `PrivacyPagesTest`, `PersonalDataExportTest`, `BanksPdfTest` y los presupuestos de consultas del equipo y la ficha (+2 y +1).
- **Vitest:** el número de comentarios y el contraste de la tira (`suggestions` y `weeklies-team-nav`), «Unirme a clientes» (`weeklies-team-nav` y `weeklies-insights`), los enlaces de la IA (`privacy-markdown` y `weeklies-insights`) y el tablero oculto (`suggestions`).

### Resultados
- Pest completo (`--parallel --processes=8`): 4.350 tests en verde (2 saltados), tras alinear `BanksPdfTest` con el prefijo de su límite.
- PHPStan completo (`--memory-limit=2G`), Pint, `tsc --noEmit` y `vp check`: sin errores.
- Vitest completo: 1.574 tests en 130 ficheros.
- `vp build` y la suite E2E completa con el servidor recién sembrado: **449 bien, 5 saltados y 0 fallos**. En la primera pasada salieron dos fallos más de los specs de la Fase 10, que se arreglaron en el test: `my-space-tasks.spec.ts:46` medía axe con dos avisos animándose (falso fallo de contraste; ahora espera a que se vayan) y `weeklies.spec.ts:64` daba por hecho que la caja recargada salía plegada, cosa que solo pasa si la weekly ya estaba enviada (ahora comprueba el texto en los dos casos).

### Para desplegar (con el SSH)
- **Una migración:** `weekly_client_subscriptions`. Las membresías creadas con el «Unirme» anterior (si alguien lo usó en el servidor de desarrollo) siguen siendo membresías: se revisan a mano en cada proyecto.
- **Horizon** coge la cola `ai-high` al reiniciarse en el despliegue (`horizon:terminate`); no cambian ni los procesos ni la memoria de `audax-horizon.service`.
- **Opcional en el `.env`:** `AI_DAILY_LIMIT_*` y `HELP_*` (sus valores por defecto ya son los de D-222 y D-223).
- **Sin cambios de sistema:** nada en `SERVIDOR-CAMBIOS.md`.

## 10.9b (hecho): paridad con WeeklySync
Siete revisores recorrieron WeeklySync (código) contra Audax por bloques (F-001 a F-181) y encontraron 3 P1, una veintena de P2, muchos P3 y 39 funcionalidades que no estaban en el inventario. Requisito del propietario: no se pierde ninguna funcionalidad. Decisiones D-227 a D-236; el inventario (`WEEKLY-INVENTARIO.md`) queda al día, con F-182 a F-220.

### P1
- **Marcarse fuera (D-228):** «Estoy fuera» (de vacaciones o ausente/baja, con vuelta opcional) desde el menú del avatar, «Mi weekly» (resumen e Inicio) y la ficha, con efecto inmediato: exime de las semanas cuyo plazo cae antes de la vuelta (motivo `away`, congelado al cerrar) y quita los recordatorios mientras dura, aunque se vuelva antes del plazo (también con una ausencia aprobada que cubre ese día). Insignia en el avatar. La propia persona puede pedir a la vez la ausencia de Audax y siempre tiene «Solicitar ausencia». Quien gestiona exime «solo esta semana» o marca fuera hasta una fecha (varias semanas) desde «Eximir», con enlace a «Ausencias del equipo». La importación trae el estado vigente de WeeklySync. Migración `2026_10_06_110000`.
- **Limpieza del dictado encendida por defecto (D-227):** solo en la weekly; si la IA falla, texto literal con aviso.
- **Importación sin perder texto con formato:** `WeeklySyncText::normalize` (h1/h2 → h3, h5/h6 → h4, b → strong, i → em, strike/del → s, div y bloques → p, img → enlace o texto alternativo, tablas → párrafos, el resto se desenvuelve; scripts fuera), con `WeeklySyncTextTest`. Las **semanas importadas sin informe estructurado** enseñan su texto final por secciones con índice en la página, el PDF/HTML y el CSV (`WeeklyReportText::sections`, `LegacyReportText`).

### P2
- **Tiempo real (D-229):** `weekly.changed` por el canal `weeklies` (envíos, exenciones, ausencias, «Estoy fuera», plazo, cierre, informe, audio y satisfacción); `/weeklies` y el informe se recargan solos (`useWeeklyLive`).
- **Regenerar un informe editado a mano** pide confirmación.
- **Weekly sin apuntes** (D-230): se puede enviar, tras confirmarlo. **Avisos del navegador**: aviso amable tras enviar y en el resumen (D-230).
- **Tareas de Mi espacio (D-231):** la revisión de la IA a la vista con aviso al terminar; buscador con más de 8 clientes o proyectos; explicación de qué clientes salen en «Nueva tarea» y enlace a la cartera (donde se ve el responsable); avisos al crear y completar; la nota avisa de que la ve el proyecto; borrar con horas, desactivado y explicado; autocompletar con las notas.
- **Clientes (D-232):** responsable elegible (`clients.owner_user_id`, migración `2026_10_06_120000`) y columna «Responsable y equipo» en la cartera (tres consultas por página); satisfacción con color por tramo.
- **Personas (D-233):** «Asignar clientes» (varios) desde la ficha con la suscripción de D-221 y «Quitar»; mapa «Constancia (últimas 12 semanas)» en el perfil y la ficha; «Ver histórico» por cliente (todos los reportes, de 25 en 25) en la ficha y en el equipo del cliente; historiales por páginas (un año y medio año) sin tope; filtros de `/equipo` que se conservan al volver.
- **Perfil y app (D-234):** foto de perfil recortada en el navegador y recodificada en el servidor (256 px, WebP, sin metadatos), URL firmada y relativa, en la exportación RGPD; rol en el pie de la barra; departamento y rol de solo lectura en el perfil (cambiarse el departamento no se permite: decide quién aprueba); `ErrorBoundary` con «Recargar».
- **Fee por código FE**, **botones Título, Subtítulo y Divisor** en el editor (F-154) y **vídeos MP4, MOV y WebM** en sugerencias, bugs y su importación (D-235).
- **«Avisos»** explica que quien tiene el resumen diario recibe el email al día siguiente.

### P3 hechos (baratos)
Nombres de quien falta al cerrar, color de la participación, un solo dictado a la vez, «hasta el…» de la exención en el editor y en «Mi weekly», el texto del editor exento, la bienvenida al entrar con Google, saltos de línea del subtítulo de las novedades, insignia de estado en el avatar y el comentario de los canales por defecto del recordatorio (D-236).

### P3 que quedan (con motivo)
- **Audio de una semana importada solo con texto:** no se regenera (el guion sale del informe estructurado); esas semanas ya traen su audio de WeeklySync.
- **Histórico de «Mi espacio» y de `/weeklies` con tope de 104 semanas:** no se nota hasta dentro de dos años; se paginará como la ficha cuando haga falta.
- **Ruta de detalle inexistente:** 404 de Audax con «Inicio» y «Volver» en lugar de redirigir con aviso.
- **Filtros de `/equipo` sin buscador** (son nativos y se puede escribir para saltar) y **tarjetas en el móvil** para clientes y equipo (tablas con desplazamiento): diseño de Audax.
- **Acciones en la fila** de la cartera y del equipo, **avatares del equipo en la cabecera** de la ficha de cliente, **resumen IA por tarjetas** y la insignia «Pendiente activación» en `/equipo`: el mismo dato está a un clic.
- **Sugerencias:** fecha de cada voto, «N comentarios» en la cabecera, última actividad en las tarjetas, estados de «similares», orden de la actividad, categoría «General» precargada y mover de categoría el autor: cosméticos.
- **Avisos:** recarga en vivo de la configuración, plantillas plegadas, envío a una semana concreta y reintento de un disparo fallido en la pasada siguiente (la cola ya reintenta el envío).
- **Estado de proyectos:** número de factura bajo el proyecto, frase de la desviación a la vista e insignias de la cabecera filtradas por tipo; clientes archivados con proyectos abiertos (se arreglan en la ficha del cliente).
- **Reproductor del cliente que vuelve a 0 al acabar,** «Guardar como» al descargar, índice dentro del HTML descargado, colores de la barra de proyecto (75/90 y parpadeo) y la etiqueta «Texto cerrado».
- **Modo oscuro, perfil y cerrar sesión a 1 clic** en la barra y el asistente flotante (D-206): la navegación de Audax.
- **Gemini 2.5 Flash** se retira el 16/10/2026: no es de código; `GEMINI_MODEL` en el `.env` del servidor lo cambia el propietario (ver «Urgente»).

### Tests nuevos
- **Pest:** `WeeklyAwayTest`, `WeeklyLiveTest`, `AvatarTest`, `WeeklySyncTextTest` (Unit) y casos nuevos en `WeeklyDictationTest`, `WeeklyReportPdfTest`, `WeeklySubmissionWriterTest`, `WeeklyDashboardsTest`, `MySpaceTasksTest`, `ClientWeeklyInsightsTest` (responsable, equipo y páginas), `TeamWeeklyInsightsTest` (constancia, histórico por cliente, páginas y asignar), `ProjectStatusBoardTest` (fee por código), `SuggestionsTest` (vídeos), `PersonalDataExportTest` (foto), `GoogleLoginTest` (bienvenida) y `WeeklySyncImportTest` («Estoy fuera»). Al día: `ClientIsolationTest` (ruta de la foto) y los presupuestos de `clients.index` (8 → 10) y de la cartera (12 → 13).
- **Vitest:** `weeklies-away`, `weeklies-live`, `weeklies-push-prompt`, `error-boundary`, `avatar-field`, `rich-text-editor` y casos nuevos en `weeklies-dictation`, `weeklies-report`, `weeklies-editor`, `my-space-tasks`, `clients-pages` y `weeklies-insights`.
- **E2E:** `weekly-parity.spec.ts` («Estoy fuera», ficha con constancia y asignar clientes, filtros del equipo y foto de perfil, con axe).

### Resultados
- Pest completo (`--parallel --processes=8`): 4.401 tests en verde (2 saltados). En la primera pasada, `ClientIsolationTest` pidió declarar la ruta de la foto (`/avatares/{user}`, firmada; un cliente del portal solo ve la suya).
- PHPStan completo (`--memory-limit=2G`), Pint, `tsc --noEmit` y `vp check`: sin errores ni avisos.
- Vitest completo: 1.601 tests en 136 ficheros.
- `vp build` y la suite E2E completa con el servidor recién sembrado: **453 bien, 5 saltados y 0 fallos**. En las pasadas anteriores salieron tres fallos de test, ya arreglados: dos de la ayuda buscaban el campo por la etiqueta «Título», que ahora casa también con el botón «Título» del editor (se busca por su rol de cuadro de texto), el de la cartera chocaba con el `data-test="client-owner"` de la ficha (la celda nueva usa `client-row-owner`), y el de mover una sugerencia, además, pulsaba el enlace antes de que llegase la búsqueda con espera, que pisaba la visita (ahora espera a la búsqueda).

### Para desplegar (con el SSH)
- **Dos migraciones:** `users.weekly_away_*` y `clients.owner_user_id`.
- **Fotos de perfil** en `storage/app/private/avatars` (disco `local`, va en la copia nocturna con el resto de `storage`).
- **Sin cambios de sistema:** nada en `SERVIDOR-CAMBIOS.md`. GD ya está en el PHP 8.4 del servidor.
