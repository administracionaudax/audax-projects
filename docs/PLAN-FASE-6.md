# Plan Fase 6: chat, tiempo real y transcripción de audios

## Contexto
El SPEC (§12 y §17) pide un chat completo:
- conversaciones de proyecto, directas y de grupo,
- mensajes en tiempo real, emojis, reacciones, audios, adjuntos, menciones, hilos, edición,
- presencia, leídos, búsqueda y fijados,
- crear una tarea desde un mensaje,
- Web Push.

Y una regla obligatoria: **todo audio tiene siempre su transcripción**, hecha en el propio servidor sin IA externa.

**Aceptación (SPEC §17):**
- dos usuarios en navegadores distintos chatean en tiempo real con todas las funciones (E2E),
- un audio de prueba en español se transcribe bien en el servidor sin afectar a la carga de los demás servicios,
- todo audio enviado acaba con su transcripción, incluso si el primer intento falla,
- una palabra dicha en un audio aparece en la búsqueda.

Se trabaja en modo autónomo (D-027). Esta fase no depende de las Fases 2 a 5, así que su contrato parte de `main` (Fase 1 cerrada) y se integra después.

## Decisiones de la fase

### D-068 · Tiempo real con Reverb **[concreta el SPEC §2 y el RUNBOOK A1]**
- **Reverb 1.12 (MIT):**
  - en el servidor, `audax-reverb.service` en `127.0.0.1:18080`, dentro de `system-audax.slice`, con 256 MiB y 50 % de CPU,
  - nginx le pasa `/app/` desde el 443 con la directiva adicional del dominio (Plesk),
  - en el navegador, `laravel-echo` 2.5, `@laravel/echo-react` 2.5 y `pusher-js` 8.6 (MIT).
  - Reverb exige `pusher/pusher-php-server`, que fija **Guzzle 7.15** (en lugar de 8); es compatible con Laravel 13.
- **Conexión del navegador en tiempo de ejecución:** el frontend se compila en el Mac, así que el host no puede ir en variables `VITE_`. El navegador recibe host, puerto, esquema y clave pública en la prop compartida `realtime` (`config/realtime.php`); si es `null`, la interfaz sigue funcionando con consultas periódicas.
- **Canales** (`routes/channels.php`):
  - `App.Models.User.{id}`: la campana,
  - `conversation.{id}`: mensajes, «escribiendo…», leídos y reacciones,
  - `online`: presencia.
- **Autorización:** `/broadcasting/auth` exige `auth`, `active` e `internal`; un cliente nunca se suscribe a nada. `ClientIsolationTest` lo comprueba con el resto de rutas.

### D-069 · Mensajes **[concreta el SPEC §12]**
- **Una sola vía de escritura:** `App\Domain\Chat\MessageWriter` hace todas las operaciones (publicar, editar, borrar, ocultar, fijar, reaccionar, marcar como leído y mensajes de sistema). Cada una comprueba su política y dispara `MessagePosted`, `MessageUpdated` o `ConversationRead`.
- **Cuerpo:** markdown ligero (negrita, cursiva, código y enlaces), saneado al pintar, con un máximo de 10.000 caracteres.
- **Menciones:** `<@ID>` insertado por el editor, y `@todos`. Solo cuentan los participantes activos; se rehacen al editar.
- **Borrado:** lógico. Queda «Mensaje eliminado» y sus adjuntos dejan de servirse.
- **Moderación:** el admin **oculta** mensajes, y queda en la auditoría (`activity('chat')`).
- **Adjuntos:** los de las conversaciones sin proyecto se guardan en `attachments/chat/{conversación}`. El límite es el mismo ajuste de 50 MB.
- **Audios:** duración máxima configurable, por defecto 5 minutos.
- **Previsualización de enlaces:** se hace en el servidor con protección SSRF:
  - solo http y https,
  - resolución DNS comprobada contra IP privadas, locales y reservadas,
  - sin seguir redirecciones a esas IP,
  - 3 s de tiempo máximo y 512 KB como máximo,
  - solo `og:title`, `og:description` e `og:image`, con la imagen servida por la propia app o sin imagen,
  - en caché.

### D-070 · Transcripción obligatoria **[concreta el SPEC §12 y el RUNBOOK A2]**
- **Motor:** whisper.cpp v1.9.4 (MIT) en el contenedor `audax-whisper` (`whisper-server --convert`, que usa ffmpeg para los formatos del navegador) en `127.0.0.1:18091`.
  - **La CPU virtual del servidor solo expone SSE2/SSE3** (sin AVX, AVX2, FMA ni F16C). La imagen oficial fallaría, así que se compila en GitHub Actions para x86-64 básico (`deploy/whisper/Dockerfile`) y se prueba con QEMU emulando esa CPU.
  - Hay dos variantes: sin BLAS y con OpenBLAS fijado a las rutinas SSE3 (Prescott).
- **Modelo:** `small` con OpenBLAS, elegido tras medir en el servidor real (tabla de abajo).
- **Arquitectura:**
  - interfaz `TranscriptionService` (`WhisperServerTranscriber` en el servidor; `FakeTranscriber` en local y tests),
  - job `TranscribeAudioMessage` en la conexión `redis-transcriptions`, atendida por `audax-transcriber.service` con **un único proceso**, Nice 19 y E/S idle; Horizon no la atiende,
  - 3 intentos con espera de 1, 5 y 15 minutos.
- **Garantía de que todo audio acaba con su texto:**
  - la transcripción se crea con el mensaje, en estado `pending`,
  - `transcriptions:requeue`, cada 15 minutos, vuelve a encolar las pendientes atascadas, las interrumpidas y las fallidas,
  - tras 9 intentos avisa al admin **una vez** y sigue probando cada hora,
  - `transcriptions:backfill` transcribe todo lo que no tenga texto,
  - el admin relanza a mano desde `/admin/transcripciones`.
- **Audio sin voz:** la transcripción termina con texto vacío y se muestra «Sin voz».

### D-071 · Quién ve cada conversación **[concreta D-021 para el chat]**
- **Proyecto:** sus miembros, que entran y salen con el proyecto (`left_at`: el histórico se conserva). En un proyecto archivado se lee pero ya no se escribe.
- **Directas:** solo las dos personas, internas y activas. **El admin no las ve.**
- **Grupos:** sus participantes.
- **El admin** ve y modera las conversaciones de proyecto y de grupo aunque no participe.
- **Personas desactivadas:** siguen en el histórico, marcadas como inactivas.

### D-072 · Web Push **[concreta el SPEC §12 y §13]**
- **Librerías:** `minishlink/web-push` 11 o `laravel-notification-channels/webpush` 13 (MIT), con claves VAPID generadas en el servidor y guardadas en `.env` (nunca en Git).
- **Qué avisa:** mensajes directos, menciones y `@todos`, salvo conversaciones silenciadas, y solo si la persona no tiene la conversación abierta.
- **Service worker:** el de la PWA, con el manejador de `push` y de `notificationclick`, que abre la conversación.
- **Preferencias por canal:** llegan en la F7. En la F6, activar o desactivar Web Push por navegador.

## Medición de los modelos (SPEC §12)
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

## Contrato técnico (hecho, con tests)
- **Esquema** (`2026_10_01_100000_create_chat_tables`): `conversations`, `conversation_participants`, `messages`, `message_reactions`, `message_mentions` y `audio_transcriptions`.
- **Modelos:** `Conversation`, `ConversationParticipant`, `Message`, `MessageReaction`, `MessageMention` y `AudioTranscription`.
- **Enums:** `ConversationType`, `MessageType` y `TranscriptionStatus`.
- **Dominio `App\Domain\Chat`:**
  - `ConversationDirectory` (`forProject`, `syncProject`, `join`, `leave`, `direct`, `group` y `forUser`),
  - `MessageWriter`,
  - `Mentions`,
  - `Transcription\{TranscriptionService, WhisperServerTranscriber, FakeTranscriber, AudioTranscriptions}`.
- **Job:** `App\Jobs\TranscribeAudioMessage`. **Comandos:** `transcriptions:requeue` (programado) y `transcriptions:backfill`.
- **Eventos:** `App\Events\Chat\{MessagePosted, MessageUpdated, ConversationRead, AudioTranscribed}`.
- **Políticas:** `ConversationPolicy` (`view`, `post` y `moderate`) y `MessagePolicy` (`update`, `delete`, `moderate`, `pin` y `react`).
- **Adjuntos:** `AttachmentStorage::store` acepta `Message`, conversaciones sin proyecto y audios (`AUDIO_EXTENSIONS`).
- **Configuración:**
  - `config/realtime.php` y la prop `realtime`,
  - `config/services.php` (`transcription`),
  - en `config/queue.php`, la conexión `redis-transcriptions`,
  - en `.env.example`, las variables de Reverb y de transcripción.
- **Rutas por agente:** `routes/app/{chat,realtime,chat-media}.php`.

## Reparto (en paralelo, cada uno en su worktree)
| Área | Contenido | Ficheros propios |
|---|---|---|
| **C1 · Chat** | `/chat` con la lista de conversaciones (no leídos por conversación y total en la navegación), crear directas y grupos, la pestaña Chat del proyecto (sustituye el `PhaseBadge` 6), mensajes con markdown ligero saneado, hilos, edición, borrado, fijados, silenciar, reacciones con selector de emojis (autoalojado, sin CDN), crear tarea desde un mensaje, previsualización de enlaces (D-069), moderación, paginación infinita hacia atrás, mensajes de sistema (bolsa al 90 % y otros avisos de la F1) | `app/Http/Controllers/Chat/*`, `app/Domain/Chat/Links/*`, `routes/app/chat.php`, `resources/js/pages/chat/*`, `resources/js/components/chat/*` (salvo `media/`), `lang/ui/chat.json`, `tests/Feature/Chat/C1*Test.php`, `tests/js/chat-*.test.tsx`, `tests/e2e/chat.spec.ts` |
| **C2 · Tiempo real y avisos** | Echo en la interfaz: mensajes en vivo, «escribiendo…», presencia (en línea, ausente, desconectado), leído por, contadores en vivo, campana en tiempo real (sustituye la consulta cada 60 s cuando hay tiempo real); avisos de menciones y `@todos`; Web Push (D-072) con el service worker | `app/Broadcasting/*`, `app/Listeners/Chat/*`, `app/Notifications/Chat/*` (salvo `TranscriptionsFailing`), `routes/app/realtime.php`, `resources/js/components/realtime/*`, `resources/js/hooks/use-realtime*.ts`, `public/sw.js` (solo el manejador de push), `lang/ui/realtime.json`, `tests/Feature/Realtime/*`, `tests/js/realtime-*.test.tsx` |
| **C3 · Audios, adjuntos y búsqueda** | Grabar audio (MediaRecorder: forma de onda, duración, máximo configurable), reproducir con barra y velocidad, «Transcribiendo…» o «Transcripción pendiente» y la transcripción plegable y copiable; adjuntos con arrastrar y soltar y pegar, visor de imágenes, servir audios con Range; `/admin/transcripciones` (estado, relanzar); búsqueda del chat (mensajes, archivos y transcripciones, que lleva al mensaje) y fuente `MessageSource` en la búsqueda global | `app/Http/Controllers/Chat/Media/*`, `app/Http/Controllers/Admin/TranscriptionController.php`, `app/Search/Sources/MessageSource.php`, `routes/app/chat-media.php`, `resources/js/components/chat/media/*`, `resources/js/pages/admin/transcriptions.tsx`, `lang/ui/chat-media.json`, `tests/Feature/Chat/C3*Test.php`, `tests/js/chat-media-*.test.tsx` |
| **Yo** | Contrato, servidor (Reverb y la directiva de nginx, el contenedor whisper y el transcriptor, con la batería V y la comparación de webs), integración, E2E con dos navegadores, revisión global y despliegue | |

## Tests (definición de hecho, §19)
- **Pest:**
  - permisos de conversaciones y mensajes (D-071), con el admin y las directas,
  - aislamiento del cliente (canales y rutas),
  - escritura (menciones, hilos, edición, borrado, moderación auditada),
  - transcripción (`pending` → `done`, fallos y reintentos, revisión cada 15 minutos, aviso al admin una vez, backfill, relanzar),
  - búsqueda de palabras de una transcripción,
  - SSRF de la previsualización.
- **Vitest:** editor con menciones, markdown saneado, grabador (duración máxima), reproductor, contadores e indicadores en vivo.
- **Playwright:** dos navegadores chatean en tiempo real (mensaje, escribiendo, leído, reacción, hilo, adjunto). En la CI, Reverb local y el motor falso de transcripción.
- **Servidor:** un audio de 1 minuto en español transcrito con el modelo elegido, con la batería V y la comparación de webs mientras se transcribe.
