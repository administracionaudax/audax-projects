# Chat de ClickUp y canales del chat

Rama `chat-clickup` (sale de `fase-10`). Decisiones D-270 a D-279 en `docs/DECISIONES.md`.

## 1. Qué hay en la app

| Pieza | Dónde |
|---|---|
| Tipos de conversación `client` y `team`, columnas `client_id`, `icon` y `archived_at` | `app/Enums/ConversationType.php`, migración `2026_10_06_130000_add_channels_to_conversations_table` |
| Quién ve cada conversación (una regla para política y consultas) | `app/Domain/Chat/ConversationAccess.php` |
| Quién participa en los canales | `app/Domain/Chat/ChannelMembership.php` |
| Crear, cambiar, archivar, entrar y salir; canal del cliente | `ConversationDirectory` (`forClient`, `createTeam`, `updateTeam`, `addToTeam`, `joinChannel`, `leaveChannel`, `listable`) y `Http/Controllers/Chat/ChannelController.php` |
| Rutas | `POST chat/canales` (admin), `PATCH chat/{id}/canal`, `POST chat/{id}/unirme`, `POST chat/{id}/dejar`, `GET chat/clientes/{cliente}` |
| Lista en tres niveles | `resources/js/components/chat/conversation-list.tsx`, `conversation-tree.ts`, `conversation-row.tsx`, `use-chat-list-prefs.ts` |
| Crear y ajustar un canal de equipo | `resources/js/components/chat/channel-dialog.tsx` |
| «Canal del cliente» en la ficha | `resources/js/pages/clients/show.tsx` |
| Descargador | `scripts/clickup/descargar-chat.py` |
| Importador | `php artisan app:import-clickup-chat` (`app/Domain/Import/ClickUp/Chat/*`) |
| Tests | `tests/Feature/Chat/ChannelsTest.php`, `tests/Feature/Import/ClickUpChatImportTest.php` (volcado inventado en `tests/fixtures/clickup-chat`), `tests/js/chat-tree.test.tsx`, `tests/e2e/chat-channels.spec.ts` |

## 2. Descargar el chat (en el Mac)

El volcado tiene conversaciones privadas: **nunca** en Git ni en el webspace público.

```bash
# El token personal de ClickUp, en ~/.config/audax/clickup.token (600). Nunca en pantalla ni en Git.
python3 scripts/clickup/descargar-chat.py --out ~/clickup-chat --export ~/clickup
```

- Reanudable: si se corta, se vuelve a lanzar igual y sigue donde lo dejó (cada página se guarda al momento). Para lanzarlo en segundo plano: `nohup python3 … >> ~/clickup-chat/chat.log 2>&1 &`.
- 40 peticiones por minuto por defecto (`--rpm`): el límite de ClickUp es 100 por minuto **por token** y puede haber otra descarga a la vez. Respeta los 429.
- Orden: canales → miembros → mensajes → respuestas (hilos) → adjuntos (no gastan cupo) → reacciones (una petición por mensaje: con ~33.000 mensajes, unas 14 h a 40/min; se pueden importar antes y repetir la importación cuando terminen).
- `--export` es la carpeta del export v2 de `app:import-clickup` (`tasks.json`, `time_entries.json`…): de ahí salen los correos de los antiguos empleados para `users.json`. `--solo-usuarios` rehace solo `users.json`, sin peticiones.
- `--canal ID` descarga solo ese canal (para probar).

**Directos de otras personas (opt-in, D-279).** Cada persona descarga los suyos con su propio token:

```bash
python3 scripts/clickup/descargar-chat.py --solo-directos --token-file /ruta/a/su.token --out ~/chat-de-ana
```

## 3. Importar en el servidor

Va **después** de `app:import-clickup` (clientes, proyectos y cuentas). Todo con `scripts/heavy.sh` y anotado en `docs/SERVIDOR-CAMBIOS.md` si toca algo del sistema (la importación en sí no lo toca).

1. **Copia de la base** antes de nada:

   ```bash
   docker exec audax-pg pg_dump -U audax_admin -Fc audax_projects > /var/backups/audax/antes-de-chat-clickup.dump
   ```

2. **Subida del volcado** a una carpeta privada del usuario de la app, fuera del webspace público, con permisos 700 (carpetas) y 600 (ficheros), junto con `personas.json`:

   ```bash
   # En el Mac
   tar czf clickup-chat.tgz -C ~ clickup-chat
   scp -P 5222 -i ~/.ssh/audax_projects_ed25519 clickup-chat.tgz ~/clickup/personas.json audax-projects:/var/www/vhosts/projects.audaxstudio.com/importacion/
   # En el servidor (usuario audaxprojects)
   cd /var/www/vhosts/projects.audaxstudio.com/importacion && umask 077 && tar xzf clickup-chat.tgz
   chmod -R go-rwx clickup-chat personas.json clickup-chat.tgz
   ```

3. **Simulación** (no guarda nada ni copia adjuntos; muestra el informe):

   ```bash
   cd /var/www/vhosts/projects.audaxstudio.com/app/current
   scripts/heavy.sh /opt/plesk/php/8.4/bin/php artisan app:import-clickup-chat ../../importacion/clickup-chat --personas=../../importacion/personas.json --dry-run
   ```

   Revisa los recuentos (canales por tipo, mensajes, respuestas, reacciones y adjuntos) y los avisos (personas sin pareja, reacciones que no existen, canales sin correspondencia). Si un canal no va donde debe, se corrige con `clasificacion.json` en el volcado (`{"<id del canal>": "team" | "group" | "skip" | "project:<id>" | "client:<id>"}`) y se repite la simulación.

4. **Importación de verdad**: el mismo comando sin `--dry-run`. Los adjuntos se copian a `storage/app/private/attachments/…` y sus miniaturas van por la cola.

5. **Comprobación:**
   - en `/chat`, los tres niveles: Daily y Audax Studio en «Canales»; los clientes con su canal y sus proyectos; los directos del propietario,
   - un hilo, una mención, una reacción y una foto en un canal con historia (Daily, Marketing),
   - `/admin/auditoria`, entidad Importación: una sola entrada «Importación del chat de ClickUp»,
   - nadie ha recibido avisos (campana vacía de chat) y no hay no leídos de lo importado,
   - `scripts/server/verificar.sh` y `scripts/server/comparar-webs.sh`.

6. **Borrado del volcado** en el servidor cuando todo cuadre (lleva conversaciones privadas):

   ```bash
   rm -rf /var/www/vhosts/projects.audaxstudio.com/importacion/clickup-chat /var/www/vhosts/projects.audaxstudio.com/importacion/clickup-chat.tgz
   ```

**Repetir:** es idempotente (`import_refs`). El día del cambio se descarga otra vez (el descargador sigue donde lo dejó; para traer lo nuevo de un canal se borra su `messages/<canal>.json`) y se vuelve a importar: añade lo nuevo, actualiza lo editado y deja leído todo lo importado.

**Directos de otra persona** (opt-in): con su volcado, `app:import-clickup-chat <volcado> --solo-directos` (mismo procedimiento: copia, simulación, importación y borrado).

## 4. Simulación local con el volcado real

Ver la sección «Simulación» al final de este documento (recuentos del ensayo).
