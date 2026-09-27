# Plan Fase 7: pulido

## Contexto
La Fase 7 (SPEC §17) cierra lo transversal:
- notificaciones con preferencias por canal y resumen diario por email (SPEC §13),
- auditoría visible (SPEC §14),
- lo que queda de RGPD, copias y observabilidad (SPEC §15),
- el `DEPLOY.md` final,
- la revisión global de accesibilidad y de rendimiento.

Se trabaja en modo autónomo (D-027). La rama `fase-7` sale de `fase-5` (Fases 1 a 4 y el contrato del portal) y se integra al final con las Fases 5 y 6 ya cerradas. Al final se pide al propietario lo que solo él puede dar (D-030): los datos SMTP, la lista de empleados, la revisión del texto RGPD y el destino de la copia externa.

## Decisiones de la fase

### D-073 · Preferencias de notificación **[concreta el SPEC §13]**
- **Un catálogo de eventos:** `App\Domain\Notifications\NotificationCatalog` tiene un evento por cada `kind()` de `AppNotification`, con:
  - su grupo: tareas, horas, bolsas, ausencias, chat, informes y sistema,
  - los canales en los que puede llegar: en la app, email y Web Push,
  - los que llegan por defecto,
  - a quién se le ofrece: cualquier interno, quien aprueba, quien gestiona bolsas o el admin,
  - si es obligatorio.
  - Un test comprueba que toda `AppNotification` tiene su evento.
- **Una sola decisión de canal:** `AppNotification::via()` pregunta a `NotificationPreferences`; las subclases ya no deciden sus canales.
- **Qué se guarda:** en `users.notification_preferences` solo lo que la persona cambia respecto al catálogo. Así, un valor por defecto nuevo llega a todos los que no lo han tocado.
- **Valores por defecto:**
  - se mantienen los canales que ya tenía cada aviso,
  - se añade el email para horas devueltas y vencimientos,
  - se añade Web Push para menciones y mensajes directos.
- **Obligatorios:** los avisos de sistema del admin (transcripciones que fallan, disco y copias) se muestran, pero no se pueden desactivar. Los emails de seguridad (invitación, contraseña) y los del cliente del portal no forman parte de las preferencias.
- **Resumen diario opcional:**
  - quien lo activa deja de recibir los emails sueltos de los eventos no obligatorios,
  - esos avisos quedan en la campana,
  - a las 08:00 de Madrid recibe un email con los que sigan sin leer de las últimas 24 h y cuyo email tenga activado, agrupados;
  - si no hay ninguno, no se envía nada.
- **Recordatorio de enviar la semana:** los viernes a las 13:00 de Madrid, en la app (y por email si se quiere), a quien tenga capacidad esa semana y no la haya enviado (abierta o devuelta). Se puede desactivar con el ajuste `week_reminder_enabled`.
- **Web Push:** se ofrece solo cuando la Fase 6 está integrada y las claves VAPID están en el `.env` (`config('notifications.channels.push')`).

### D-074 · Auditoría visible **[concreta el SPEC §14 y §15]**
- **Dónde:** `/admin/auditoria` (solo admin), sobre `activity_log`.
- **Filtros en la URL:**
  - entidad: proyecto, bolsa, tarea, horas, semana, cliente, ausencia, festivo, plantilla, regla recurrente, ajustes, privacidad y chat,
  - persona que hizo el cambio,
  - acción: crear, cambiar o borrar,
  - fechas.
- **Detalle:** el antes y el después de cada campo con nombres legibles, y un enlace a la entidad si sigue existiendo. Los campos económicos se muestran porque solo lo ve el admin.
- **Rendimiento:** paginación por cursor e índices sobre `activity_log`, sin N+1.
- **Exportación:** a CSV, con el límite de exportaciones de los informes.

### D-075 · RGPD **[concreta el SPEC §15; texto pendiente de asesor]**
- **Texto informativo configurable:**
  - `/admin/privacidad`, en markdown que se pinta saneado,
  - con versión: cada cambio sube la versión y queda en la auditoría,
  - el texto por defecto es un borrador marcado «pendiente de asesor».
- **Lectura:**
  - la plantilla lo ve en `/privacidad`, con los plazos de retención vigentes,
  - mientras no haya leído la versión vigente, un aviso en la app lleva a leerlo y aceptarlo, y se registra la lectura (`users.privacy_acknowledged_*`),
  - los clientes del portal no lo ven.
- **Retención configurable** (`RetentionPolicy`, ajustes `retention_*`):
  - registros de acceso: 12 meses,
  - notificaciones leídas: 6 meses,
  - auditoría: 5 años (mínimo 1),
  - mensajes del chat: sin límite por defecto.
  - Lo aplica cada día `app:prune-data` a las 03:10, antes de la copia nocturna. **Nunca borra horas, bolsas, tareas ni proyectos.**
- **Exportación de los datos personales:**
  - la pide la propia persona desde `/ajustes/mis-datos` o el admin desde la ficha de la persona,
  - genera en cola un ZIP con JSON y CSV: perfil, horarios, horas, ausencias, comentarios y mensajes propios, notificaciones y registros de acceso,
  - se descarga con URL firmada durante 7 días y después se borra,
  - una sola exportación en curso por persona,
  - queda en la auditoría.

### D-076 · Copias y almacenamiento **[concreta el SPEC §15 y D-029]**
- **Copia externa:** `restic` (binario oficial con su SHA-256, sin instalar paquetes) al destino que indique el propietario (S3 compatible o SFTP), con 7 diarias, 4 semanales y 6 mensuales. El script y la unidad quedan listos, y se activan cuando llegue el destino.
- **Prueba de restauración mensual** (unidad del servidor):
  - restaura el último volcado en una base temporal del contenedor propio,
  - compara el recuento de tablas y de filas clave,
  - borra la base temporal,
  - deja el resultado en un fichero de estado de la app.
- **Aviso de almacenamiento** (`app:check-storage`, diario a las 09:00):
  - disco por encima de `disk_warning_percent` (85 %),
  - adjuntos por encima de `attachments_warning_gb` (si se fija),
  - copia de la noche no hecha o prueba de restauración fallida.
  - Avisa al admin como mucho una vez al día por motivo; son eventos obligatorios.
- **ClamAV:** el escaneo de adjuntos queda como opción futura: el contenedor ClamAV del servidor es de otra aplicación y no se usa sin aprobación.

### D-077 · Observabilidad y cierre
- **Logs:** canal diario en JSON (`LOG_STACK=daily_json`) en el servidor. Horizon y `/health` ya están; Laravel Pulse queda fuera (opcional en el SPEC).
- **`docs/DEPLOY.md` final:**
  - despliegue y vuelta atrás,
  - restauración de copias,
  - variables de entorno,
  - servicios, puertos y unidades,
  - comprobaciones tras cada cambio.
- **Revisión final:**
  - accesibilidad AA de todas las páginas en claro, oscuro y 375 px con axe,
  - rendimiento de todas las páginas con el DemoDataSeeder: presupuesto de consultas y menos de 1 s en el servidor.

## Contrato técnico (hecho, con tests)
- **Notificaciones:**
  - `App\Domain\Notifications\{NotificationCatalog, NotificationEvent, NotificationPreferences}`,
  - `AppNotification::via()` con preferencias, un `toMail()` genérico y `toPushPayload()`,
  - `config/notifications.php` y `lang/es/notifications.php` (grupos, eventos y canales),
  - `resources/js/types/notification-settings.ts`,
  - tests en `tests/Feature/Notifications/`.
- **Privacidad:**
  - migraciones de `users.privacy_acknowledged_version` y `_at` y de `personal_data_exports`,
  - `PersonalDataExport` con su enum de estado y su factoría,
  - `App\Domain\Privacy\{PrivacyNotice, RetentionPolicy}`,
  - `lang/es/privacy.php` con el borrador del texto,
  - ajustes nuevos en `Setting::DEFAULTS`,
  - prop compartida `privacy.needs_acknowledgement`,
  - tests en `tests/Feature/Privacy/`.
- **Rutas:** `routes/app/{notification-settings, privacy, audit}.php`, cargadas desde `routes/web.php`.
- **Tareas programadas** en `routes/console.php`: `notifications:daily-digest`, `time:remind-week`, `app:prune-data` y `app:check-storage`. Cada una se omite mientras su comando no exista.

## Reparto
| Área | Contenido |
|---|---|
| **N · Notificaciones** | `/ajustes/notificaciones` (matriz evento × canal, resumen diario, obligatorios bloqueados, móvil y teclado), `notifications:daily-digest` con su email, `time:remind-week` con su notificación, enlace desde los ajustes y la campana |
| **A · Admin y RGPD** | `/admin/auditoria` con filtros, detalle y CSV; `/admin/privacidad` (texto y retención); `/privacidad` y el aviso de lectura; `/ajustes/mis-datos` y la exportación del admin (job, ZIP, URL firmada, caducidad); `app:prune-data`; `app:check-storage` con sus avisos |
| **Yo** | Contrato; copia externa y prueba de restauración en el servidor; logs JSON; `DEPLOY.md`; integración con las Fases 5 y 6 (Web Push en las preferencias, chat en la auditoría, en la retención y en la exportación); revisión final de accesibilidad y rendimiento; despliegue y cierre |

## Tests
- **Pest:**
  - preferencias (defaults, cambios, obligatorios, resumen y Web Push),
  - resumen diario (contenido, sin email si no hay nada, respeta lo leído),
  - recordatorio (quién sí y quién no),
  - auditoría (solo admin, filtros, detalle y CSV),
  - privacidad (lectura, versión y clientes fuera),
  - retención (qué borra y qué nunca),
  - exportación (contenido del ZIP, permisos, caducidad y URL firmada),
  - avisos de almacenamiento (umbral y una vez al día).
- **Vitest:** la matriz de preferencias (teclado y obligatorios), el aviso de privacidad, el texto saneado, los filtros y el detalle de la auditoría.
- **Playwright:**
  - una persona desactiva el email de un evento y activa el resumen,
  - un empleado lee el aviso de privacidad y descarga sus datos,
  - el admin filtra la auditoría y exporta el CSV,
  - axe en las páginas nuevas.

## Aceptación
Preferencias respetadas en todos los canales, resumen diario y recordatorio de los viernes, auditoría visible, texto RGPD con su lectura, retención aplicada, exportación de datos personales, avisos de disco y de copias, copia externa lista para activarse, `DEPLOY.md` completo, y la revisión final de accesibilidad y de rendimiento en verde.
