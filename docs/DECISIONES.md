# Decisiones

Registro de decisiones del proyecto. Las que cambian el SPEC llevan la etiqueta **[cambia el SPEC]**.

## 26/09/2026: Arranque de la Fase 0

### D-001 · Servidor de la app
`svr.ztudio.es` (185.33.65.98, **SSH por el puerto 5222**; el 22 está en DROP). La app va en `projects.audaxstudio.com`.

### D-002 · Desarrollo en el servidor **[cambia el SPEC §16.4 y §2 "Desarrollo local"]**
El usuario ha decidido no montar Docker en el Mac y desarrollar directamente en `projects.audaxstudio.com`.
- **Hasta que la plantilla empiece a usar la app:** `APP_DEBUG=false`, cabecera `noindex` y ningún dato real.
- **Antes de introducir datos reales:** se separan producción (`projects`) y desarrollo (otro subdominio) y se empieza a desplegar con `deploy.sh` desde GitHub.
- **Flujo:** la copia de trabajo está en el Mac; se sincroniza con `rsync` y los comandos se ejecutan por SSH con `nice`/`ionice`. Los E2E se ejecutan solo en la CI (GitHub Actions), nunca contra el servidor.

### D-003 · DNS
`audaxstudio.com` tiene el DNS en Cloudflare. El usuario crea los registros; nosotros le damos los datos exactos tras la auditoría.

### D-004 · Repositorio y CI
Repositorio privado en GitHub (cuenta `administracion@audaxstudio.com`), con CI en GitHub Actions. Motivos: copia del código fuera del servidor, historial, CI sin cargar el servidor y origen de `deploy.sh`.

### D-005 · Gestores de proyecto **[concreta el SPEC §5]**
Cada proyecto puede tener **varios gestores** (principal y co-gestores), elegidos en ese proyecto. Cada gestor puede escoger **qué alertas recibe** de ese proyecto. "Gestor" no es un rol global: es una relación por proyecto. Se implementa en la Fase 1.

### D-006 · Visibilidad del responsable de departamento **[concreta el SPEC §5]**
El responsable **solo ve lo de su departamento**. La interpretación exacta está pendiente de confirmar antes de la Fase 1 (ver "Dudas abiertas").

### D-007 · Gráficas: Recharts
Motivos para elegir **Recharts** (MIT) frente a ECharts:
- se integra con los charts de shadcn/ui y hereda las variables CSS del tema (claro/oscuro sin configuración extra),
- es React declarativo y el bundle es más ligero.
El heatmap diario y la matriz de carga se hacen como componentes propios.

### D-008 · Base del proyecto
- **Starter kit oficial de Laravel con React:** Laravel 13, Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui, Fortify con 2FA y Wayfinder.
- **Pest 5** en lugar de PHPUnit.
- Sin registro público.
- `config.platform.php = 8.4` en Composer.

### D-009 · Licencias fuera de MIT/Apache/BSD
- **DM Sans:** OFL-1.1, la licencia estándar de fuentes libres, que permite uso comercial.
- **axe-core:** MPL-2.0, pero solo en tests; no llega al navegador.

### D-010 · Primer admin sin contraseña tecleada
`app:install` crea el admin e imprime un enlace de un solo uso para que fije su contraseña.

### D-011 · Azul de marca y contraste AA **[concreta el SPEC §3.1]**
`#0171FF` con texto blanco da 4,37:1 y no llega a AA (4,5:1). El SPEC permite ajustar la luminosidad en ese caso:
- **Botón principal:** `#0068EB` con texto blanco (5,02:1).
- **Enlaces y texto en azul:** `#005FD6` en claro (≥ 4,76:1 sobre todos los fondos, incluidos los tintados) y `#4A93FF` en oscuro (5,68:1).
- **`#0171FF` se mantiene** para la marca (logotipo, palabras destacadas en titulares grandes), los anillos de foco, las barras y la serie 1 de las gráficas.
- **Texto secundario:** `#56667A` en claro, en lugar del navy al 60 %, que no llega a 4,5:1 sobre los fondos tintados; blanco al 60 % en oscuro.
- **Bordes de campos de formulario:** navy al 50 % en claro (3,38:1) y blanco al 40 % en oscuro (3,71:1), por la regla de 3:1 para elementos no textuales. El resto de bordes mantiene el 20 % de marca.
- Todos los pares se verifican en `tests/js/theme-contrast.test.ts`.

### D-012 · Paleta de datos y estados
- **Categórica (tonos fríos, orden fijo, nunca se cicla):** validada con el validador de daltonismo (Machado 2009, OKLab) en los dos temas. ΔE adyacente con protanopía/deuteranopía: 17,1 en claro y 14,2 en oscuro (objetivo ≥ 8). Visión normal: 20,4 y 18,2 (mínimo 15). Todas las series ≥ 3:1 sobre la tarjeta.
  - **Claro:** azul `#0171FF` · turquesa `#179FA5` · violeta `#5E2DAD` · magenta `#E65FB3` · índigo `#3C41AE` · celeste `#0892C4`.
  - **Oscuro:** `#0260D9` · `#0D9298` · `#9D74F8` · `#B53087` · `#7685F8` · `#04729B`.
  - Con más de 6 series se agrupa en «Otros»; en gráficas de dispersión o mapas, máximo 3 series.
- **Estados:** verde, ámbar y rojo desaturados, siempre con icono y texto (texto ≥ 4,5:1 en los dos temas).
- **Semáforo de carga:** tintes neutro, azul, verde, ámbar y rojo con texto navy (≥ 4,5:1).
- **Departamentos por defecto:** Diseño `#0171FF`, Desarrollo `#179FA5`, Marketing `#5E2DAD` (editables).

### D-013 · Herramientas de frontend: Vite+ en lugar de ESLint y Prettier **[PROPUESTA pendiente de tu confirmación; cambia el SPEC §2]**
El starter kit oficial trae **Vite+** (`vite-plus`, MIT), que incluye:
- **Oxlint** (lint),
- **Oxfmt** (formato),
- **Vitest 4** (tests).
Cumple la misma función que ESLint y Prettier, pero mucho más rápido y ya configurado. Se mantiene salvo que prefieras ESLint y Prettier. Comandos: `npx vp check` (lint + formato), `npx vp test run` y `npx vp build`.

### D-014 · Sesiones en base de datos
`SESSION_DRIVER=database` (PostgreSQL), para poder listar y cerrar las sesiones activas de cada usuario (SPEC §15). La caché y las colas van en Valkey.

### D-015 · Tipografía autoalojada
El kit cargaba la fuente desde Bunny Fonts, un CDN externo. Se sustituye por **DM Sans autoalojada** (`@fontsource/dm-sans`, OFL-1.1), subconjunto latino, pesos 400 y 500 y cursiva 400, por RGPD y rendimiento.

### D-016 · Idioma y URLs
- **Nombre de la app:** «Audax Proyectos».
- **URLs visibles en español** (`/proyectos`, `/ajustes/perfil`) y nombres de ruta en inglés. Las rutas de autenticación de Fortify mantienen las suyas (`/login`, `/forgot-password`).
- **Traducciones:** el frontend usa `lang/es.json`, importado en la compilación con `t()`; el backend usa `lang/es/*.php`.

### D-017 · Autenticación
Del kit se mantienen solo **2FA** y la **confirmación de contraseña**. Se eliminan:
- el registro público (el alta es por invitación),
- la verificación de email (la invitación ya la verifica),
- las passkeys (simplicidad).
Tampoco se puede **borrar la propia cuenta**: los usuarios se desactivan (SPEC §14).

### D-018 · Despliegue durante D-002
- **Cómo se despliega:** `scripts/desplegar-dev.sh` compila en el Mac (PHP 8.4 y Composer de Homebrew) y sincroniza con rsync como **root** con `--chown=audaxprojects:psacln`. Los comandos de la app se ejecutan como `audaxprojects` (`runuser`). No se instaló la clave SSH de `audaxprojects` porque el control de permisos de Claude bloqueó crear ese acceso persistente; el propietario puede añadirla para trabajar sin root.
- **Tests en local:** en SQLite en memoria, para iterar rápido.
- **Tests completos:** en el servidor contra PostgreSQL 18 (`audax_projects_test`) y en CI, contra PostgreSQL 18.
- **Scheduler:** con `schedule:work` en systemd (no con cron), para no sumar sesiones de cron, PAM, logind y dbus.

## Dudas abiertas (a cerrar antes de la Fase 1)
1. **Exceso de bolsa:** proponemos `overage_minutes` en cada entrada en lugar de partir la entrada en dos. `is_overage` pasaría a derivarse.
2. **Aprobación de horas de responsables y admins:** proponemos que las de los responsables las apruebe el admin y que las de los admins se aprueben solas. Además, añadir el estado "devuelta con comentario".
3. **Visibilidad del responsable:** proponemos que vea los proyectos con al menos un miembro o una bolsa de su departamento, más aquellos en los que es miembro o gestor. En horas, carga y productividad, solo su departamento.
4. **Creación de clientes y proyectos:** proponemos admin y responsables.
5. **Alertas por gestor:** proponemos que cada gestor elija las suyas, todas activadas por defecto, y que el admin pueda editarlas.
6. **Un solo responsable por departamento** (`manager_user_id`): proponemos que sí.
