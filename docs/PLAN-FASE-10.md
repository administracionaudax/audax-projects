# Plan de la Fase 10: la Weekly dentro de Audax Proyectos

Pedida por el propietario el 05/10/2026. La agencia hace cada semana su **weekly** en WeeklySync, una app aparte (React y Vite sobre Supabase, con Google Gemini), solo para Audax. Se **fusiona** en Audax Proyectos:
- se reutilizan sus pantallas y su lógica,
- sobre los usuarios, clientes, proyectos, bolsas, horas y ausencias de Audax,
- con un solo inicio de sesión,
- migrando sus datos,
- y apagando después Supabase, Vercel, la GitHub Action y Resend.

Rama `fase-10`, que sale de `fase-9`.

**Requisito del propietario: no se pierde ninguna funcionalidad.** El inventario completo, con las 181 funcionalidades (F-001 a F-181), su origen y su destino, está en [`WEEKLY-INVENTARIO.md`](WEEKLY-INVENTARIO.md) (§A.4). Esa lista es la **definición de hecho** de la fase: cada F se marca como hecha y probada, y la fase no se cierra sin una revisión de completitud contra la app original. Solo se descarta la consola multi-tenant (F-175 a F-181). De ella se conservan, para una sola empresa, los módulos activos, el aviso global y la página «Uso de IA».

Decisiones: **D-145 a D-150**.

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
