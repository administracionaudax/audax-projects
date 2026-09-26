# Progreso

_Última actualización: 26/09/2026_

## Hecho
- El plan de la Fase 0 está aprobado. Copia del plan: `~/.claude/plans/precious-gathering-sundae.md`; el resumen está en `docs/DECISIONES.md`.
- Repositorio Git local inicializado (rama `main`, autor "Audax Studio").
- `SPEC.md`, `CLAUDE.md`, `docs/DECISIONES.md` y `docs/PROGRESO.md` creados.
- Clave SSH dedicada generada en el Mac: `~/.ssh/audax_projects_ed25519`.

## En curso: Fase 0
- **0.1 Auditoría del servidor en solo lectura.** Bloqueada: esperando el acceso SSH.

## Siguiente
1. 0.1 Auditoría, y con ella `docs/SERVIDOR.md` y la propuesta de despliegue. **Pausa para aprobación.**
2. El usuario crea el registro DNS en Cloudflare con los datos que le demos.
3. 0.2 Esqueleto de la app (starter kit), sincronizado al servidor.
4. 0.3 Tema Audax y `/styleguide`. **Pausa para aprobación.**
5. 0.4 Autenticación, roles, layout, preferencias, búsqueda, PWA y `/health`.
6. 0.5 CI con GitHub Actions.
7. 0.6 Documentación y `deploy.sh`.

## Bloqueos: necesitamos del usuario
- [ ] Añadir la clave pública `~/.ssh/audax_projects_ed25519.pub` a `authorized_keys` en `svr.ztudio.es` y decirnos el usuario (root o uno con sudo).
- [ ] Crear el repositorio privado en GitHub (por ejemplo `audax-projects`), o ejecutar `gh auth login` en el Mac para que lo creemos nosotros.
- [ ] Más adelante: datos SMTP, logo SVG y favicon, destino de los backups.

## Problemas abiertos
- Ninguno.
