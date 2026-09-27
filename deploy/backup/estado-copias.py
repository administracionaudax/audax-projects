#!/usr/bin/env python3
"""Actualiza el estado de las copias que lee la app (D-076, app:check-storage).

Uso: estado-copias.py <fichero.json> clave=valor [clave=valor ...]
  - «true» y «false» se guardan como booleanos; «now» como la hora actual en UTC (ISO 8601);
    el resto, como texto.
  - Conserva las claves que ya había y escribe de forma atómica (fichero temporal y rename).
  - El fichero queda legible por el usuario de la app (audaxprojects:psacln, 640).

Claves que usa la app: last_backup_at, last_backup_ok, last_restore_check_at,
last_restore_check_ok y last_offsite_at, last_offsite_ok (copia externa, si está activa).
"""

import datetime
import grp
import json
import os
import pwd
import sys
import tempfile


def value(raw: str):
    if raw == "true":
        return True
    if raw == "false":
        return False
    if raw == "now":
        return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    return raw


def main() -> int:
    if len(sys.argv) < 3:
        print(__doc__, file=sys.stderr)
        return 2

    path = sys.argv[1]
    state = {}
    if os.path.exists(path):
        try:
            with open(path, encoding="utf-8") as handle:
                loaded = json.load(handle)
                if isinstance(loaded, dict):
                    state = loaded
        except (OSError, ValueError):
            state = {}

    for pair in sys.argv[2:]:
        key, sep, raw = pair.partition("=")
        if not sep or not key:
            print(f"par no válido: {pair}", file=sys.stderr)
            return 2
        state[key] = value(raw)

    directory = os.path.dirname(path) or "."
    os.makedirs(directory, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".backup-status-", dir=directory)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(state, handle, ensure_ascii=False, indent=2, sort_keys=True)
        handle.write("\n")

    os.chmod(tmp, 0o640)
    try:
        os.chown(tmp, pwd.getpwnam("audaxprojects").pw_uid, grp.getgrnam("psacln").gr_gid)
    except (KeyError, PermissionError):
        pass  # fuera del servidor (pruebas locales)
    os.replace(tmp, path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
