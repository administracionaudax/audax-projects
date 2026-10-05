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
| **10.8 Migración** | `app:import-weeklysync` idempotente con `--dry-run`, ficheros de correspondencias y `import_refs` (fuente `weeklysync`). Migra ciclos, envíos, entradas, exenciones, satisfacción, audios, ayuda, sugerencias, reglas y plantillas | — | M |
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
