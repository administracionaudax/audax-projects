#!/usr/bin/env python3
"""Descarga SOLO LECTURA del chat de ClickUp (API v3) para importarlo en Audax (app:import-clickup-chat).

Reanudable: cada página se guarda en disco al momento y, al relanzarlo, sigue donde lo dejó.
Va despacio a propósito (≤40 peticiones/min por defecto) y respeta los 429 esperando al reinicio del cupo.

Uso (en el Mac, nunca en el servidor; el volcado tiene conversaciones privadas):
  python3 scripts/clickup/descargar-chat.py [--out DIR] [--token-file F] [--workspace ID] [--rpm 40]
                  [--solo-directos] [--canal ID ...] [--sin-reacciones] [--sin-adjuntos]

  --solo-directos  descarga solo los mensajes directos y grupos privados del dueño del token
                   (opt-in de cada persona con SU token: es lo único que la API le deja leer).

Estructura del volcado (la que lee app:import-clickup-chat):
  meta.json                     workspace, dueño del token, fecha y opciones
  channels.json                 todos los canales (con parent = ubicación: 7 workspace, 12 suelto, 4 espacio, 5 carpeta, 6 lista)
  members/<canal>.json          miembros de cada canal
  messages/<canal>.json         {"messages": [...], "cursor": ..., "done": bool}
  replies/<mensaje>.json        {"replies": [...], "done": bool}
  reactions/<canal>.json        {"<mensaje>": [reacciones], ...}
  attachments.json              {"<url>": {"path": "attachments/..", "size": n, "type": "..."} | {"error": ...}}
  attachments/<id>/<nombre>     ficheros adjuntos descargados (las URL de clickup-attachments son públicas)
  users.json                    {"<id de ClickUp>": {"name": ..., "email": ...}}: miembros de los canales y,
                                con --export, las personas que aparecen en el export v2 (antiguos empleados)
"""
# Volcado de solo lectura del chat de ClickUp para app:import-clickup-chat (D-274). Fuera del
# repositorio quedan el token (~/.config/audax/clickup.token o --token-file) y el volcado.
import argparse, hashlib, json, os, re, sys, time, urllib.error, urllib.parse, urllib.request

p = argparse.ArgumentParser()
p.add_argument('--out', default=os.path.join(os.getcwd(), 'clickup-chat'))
p.add_argument('--token-file', default=os.path.expanduser('~/.config/audax/clickup.token'))
p.add_argument('--workspace', default='9015317669')
p.add_argument('--rpm', type=float, default=40)
p.add_argument('--solo-directos', action='store_true')
p.add_argument('--canal', action='append', default=[])
p.add_argument('--sin-reacciones', action='store_true')
p.add_argument('--sin-adjuntos', action='store_true')
p.add_argument('--export', default=None, help='carpeta del export v2 de ClickUp (tasks.json, time_entries.json…) para completar users.json')
p.add_argument('--solo-usuarios', action='store_true', help='solo rehace users.json (sin peticiones a la API)')
a = p.parse_args()

OUT = a.out
os.umask(0o077)  # el volcado solo lo lee quien lo descarga (carpetas 700, ficheros 600)
os.makedirs(OUT, exist_ok=True)
TOKEN = open(a.token_file).read().strip()
BASE = f'https://api.clickup.com/api/v3/workspaces/{a.workspace}'
GAP = 60.0 / a.rpm
last = [0.0]
stats = {'peticiones': 0, '429': 0}


def log(*x):
    print(time.strftime('%H:%M:%S'), *x, flush=True)


def path(*parts):
    return os.path.join(OUT, *parts)


def load(rel, default):
    f = path(rel)
    return json.load(open(f)) if os.path.exists(f) else default


def save(rel, data):
    f = path(rel)
    os.makedirs(os.path.dirname(f), exist_ok=True)
    json.dump(data, open(f + '.tmp', 'w'), ensure_ascii=False)
    os.replace(f + '.tmp', f)


def api(url, **q):
    if not url.startswith('http'):
        url = (BASE if not url.startswith('/api/') else 'https://api.clickup.com') + url
    if q:
        url += ('&' if '?' in url else '?') + urllib.parse.urlencode({k: v for k, v in q.items() if v not in (None, '')})
    for intento in range(10):
        wait = GAP - (time.time() - last[0])
        if wait > 0:
            time.sleep(wait)
        last[0] = time.time()
        stats['peticiones'] += 1
        try:
            req = urllib.request.Request(url, headers={'Authorization': TOKEN, 'Accept': 'application/json'})
            with urllib.request.urlopen(req, timeout=90) as r:
                rem = r.headers.get('X-RateLimit-Remaining')
                if rem is not None and rem.isdigit() and int(rem) < 10:
                    reset = r.headers.get('X-RateLimit-Reset')
                    pausa = max(5, min(65, int(reset) - time.time() + 1)) if reset and reset.isdigit() else 30
                    log(f'cupo casi agotado ({rem}); pausa {pausa:.0f}s')
                    time.sleep(pausa)
                return json.load(r)
        except urllib.error.HTTPError as e:
            if e.code == 429:
                stats['429'] += 1
                reset = e.headers.get('X-RateLimit-Reset')
                pausa = max(15, min(90, int(reset) - time.time() + 2)) if reset and reset.isdigit() else 60
                log(f'429: espero {pausa:.0f}s')
                time.sleep(pausa)
                continue
            if e.code in (401, 403, 404):
                return {'_error': e.code}
            time.sleep(5 * (intento + 1))
        except Exception as e:  # red, timeout…
            log('error de red', e)
            time.sleep(10 * (intento + 1))
    return {'_error': 'reintentos'}


def paged(url, **q):
    """Recorre todas las páginas (next_cursor) devolviendo la lista completa (para listados cortos)."""
    out, cursor = [], None
    while True:
        r = api(url, limit=100, cursor=cursor, **q)
        if '_error' in r:
            return out, r['_error']
        out += r.get('data', [])
        cursor = r.get('next_cursor')
        if not cursor:
            return out, None


def build_users():
    """Índice id → nombre y correo para casar autores con personas.json (sin peticiones a la API)."""
    users = {}
    export = a.export or os.path.join(os.path.dirname(os.path.abspath(OUT)), 'clickup')
    if os.path.isdir(export):
        pat = re.compile(r'"id":\s*(-?\d+),\s*"username":\s*"((?:[^"\\]|\\.)*)"[^{}]{0,300}?"email":\s*"([^"]*)"')
        for name in ('team.json', 'tasks.json', 'tareas_extra.json', 'time_entries.json', 'comentarios.json'):
            f = os.path.join(export, name)
            if not os.path.exists(f):
                continue
            with open(f, encoding='utf-8') as fh:
                for m in pat.finditer(fh.read()):
                    users.setdefault(m.group(1), {'name': json.loads('"' + m.group(2) + '"'), 'email': m.group(3)})
    if os.path.isdir(path('members')):
        for f in os.listdir(path('members')):
            for m in load('members/' + f, {'members': []})['members']:
                users[str(m['id'])] = {'name': m.get('name') or m.get('username'), 'email': m.get('email')}
    save('users.json', users)
    log('usuarios:', len(users))


if a.solo_usuarios:
    build_users()
    sys.exit(0)

# 0. Meta: quién es el dueño del token (sus DM y grupos son los únicos legibles)
meta = load('meta.json', None)
if meta is None:
    me = api('/api/v2/user').get('user', {})
    meta = {'workspace': a.workspace, 'token_owner': {'id': str(me.get('id', '')), 'name': me.get('username'), 'email': me.get('email')},
            'started_at': time.strftime('%Y-%m-%dT%H:%M:%S%z'), 'solo_directos': a.solo_directos}
    save('meta.json', meta)
log('dueño del token:', meta['token_owner'].get('id'))

# 1. Canales
channels = load('channels.json', None)
if channels is None:
    channels, err = paged('/chat/channels', include_closed='true')
    if err:
        sys.exit(f'no se pudieron leer los canales: {err}')
    save('channels.json', channels)
log('canales:', len(channels), {t: sum(1 for c in channels if c['type'] == t) for t in {c['type'] for c in channels}})

todo = [c for c in channels if (not a.solo_directos or c['type'] in ('DM', 'GROUP_DM'))]
if a.canal:
    todo = [c for c in todo if c['id'] in a.canal]
# Primero los canales con actividad más reciente
todo.sort(key=lambda c: -(c.get('latest_comment_at') or 0))

# 2. Miembros
for c in todo:
    rel = f"members/{c['id']}.json"
    if os.path.exists(path(rel)):
        continue
    members, err = paged(f"/chat/channels/{c['id']}/members")
    save(rel, {'members': members, 'error': err})
log('miembros: hecho')

# 3. Mensajes (punto de control por página)
total = 0
for i, c in enumerate(todo, 1):
    rel = f"messages/{c['id']}.json"
    st = load(rel, {'messages': [], 'cursor': None, 'done': False})
    while not st['done']:
        r = api(f"/chat/channels/{c['id']}/messages", limit=100, cursor=st['cursor'], content_format='text/md')
        if '_error' in r:
            st['error'] = r['_error']
            st['done'] = True
        else:
            st['messages'] += r.get('data', [])
            st['cursor'] = r.get('next_cursor') or None
            st['done'] = not st['cursor'] or not r.get('data')
        save(rel, st)
    total += len(st['messages'])
    if i % 10 == 0 or i == len(todo):
        log(f'mensajes: {i}/{len(todo)} canales, {total} mensajes, {stats["peticiones"]} peticiones')


def all_messages():
    for c in todo:
        for m in load(f"messages/{c['id']}.json", {'messages': []})['messages']:
            yield c['id'], m


# 4. Respuestas (hilos): solo de los mensajes con replies_count > 0
with_replies = [(cid, m) for cid, m in all_messages() if (m.get('replies_count') or 0) > 0]
log('mensajes con respuestas:', len(with_replies))
for n, (cid, m) in enumerate(with_replies, 1):
    rel = f"replies/{m['id']}.json"
    if os.path.exists(path(rel)):
        continue
    replies, err = paged(f"/chat/messages/{m['id']}/replies", content_format='text/md')
    save(rel, {'channel': cid, 'replies': replies, 'error': err, 'done': True})
    if n % 100 == 0:
        log(f'respuestas: {n}/{len(with_replies)}')
log('respuestas: hecho')

# 5. Adjuntos: URLs de clickup-attachments en el contenido (públicas; otra máquina, sin cupo de API)
ATT = re.compile(r'https://[a-z0-9.-]*clickup-attachments\.com/[^\s)\]"]+')
if not a.sin_adjuntos:
    index = load('attachments.json', {})
    urls = []
    for cid, m in all_messages():
        urls += ATT.findall(m.get('content') or '')
    for f in sorted(os.listdir(path('replies'))) if os.path.isdir(path('replies')) else []:
        for rp in load('replies/' + f, {'replies': []})['replies']:
            urls += ATT.findall(rp.get('content') or '')
    urls = list(dict.fromkeys(urls))
    log('adjuntos:', len(urls), 'pendientes:', sum(1 for u in urls if u not in index))
    for n, u in enumerate(urls, 1):
        if u in index:
            continue
        name = urllib.parse.unquote(u.rsplit('/', 1)[-1].split('?')[0])[:150] or 'adjunto'
        name = re.sub(r'[/\\\x00]', '_', name)
        rel = f"attachments/{hashlib.sha1(u.encode()).hexdigest()[:16]}/{name}"
        try:
            time.sleep(0.5)
            with urllib.request.urlopen(urllib.request.Request(u), timeout=180) as r:
                data = r.read()
                os.makedirs(os.path.dirname(path(rel)), exist_ok=True)
                open(path(rel), 'wb').write(data)
                index[u] = {'path': rel, 'name': name, 'size': len(data), 'type': r.headers.get('Content-Type')}
        except Exception as e:
            index[u] = {'error': str(e)[:200], 'name': name}
        if n % 50 == 0:
            save('attachments.json', index)
            log(f'adjuntos: {n}/{len(urls)}')
    save('attachments.json', index)
    log('adjuntos: hecho', sum(1 for v in index.values() if 'path' in v), 'descargados,', sum(1 for v in index.values() if 'error' in v), 'con error')

# 6. Reacciones: una petición por mensaje (y respuesta); lo último porque es lo más caro
if not a.sin_reacciones:
    for i, c in enumerate(todo, 1):
        rel = f"reactions/{c['id']}.json"
        done = load(rel, {})
        ids = [m['id'] for m in load(f"messages/{c['id']}.json", {'messages': []})['messages']]
        for m in load(f"messages/{c['id']}.json", {'messages': []})['messages']:
            if (m.get('replies_count') or 0) > 0:
                ids += [r['id'] for r in load(f"replies/{m['id']}.json", {'replies': []})['replies']]
        pending = [x for x in ids if x not in done]
        for n, mid in enumerate(pending, 1):
            rs, err = paged(f'/chat/messages/{mid}/reactions')
            done[mid] = rs if not err else {'_error': err}
            if n % 50 == 0:
                save(rel, done)
        save(rel, done)
        log(f'reacciones: {i}/{len(todo)} canales')

build_users()
meta['finished_at'] = time.strftime('%Y-%m-%dT%H:%M:%S%z')
save('meta.json', meta)
log('FIN', stats)
