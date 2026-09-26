# Decisiones

Registro de decisiones del proyecto. Las que cambian el SPEC llevan la etiqueta **[cambia el SPEC]**.

## 26/09/2026: Arranque de la Fase 0

### D-001 · Servidor de la app
`svr.ztudio.es` (185.33.65.98, SSH por el puerto 22). La app va en `projects.audaxstudio.com`.

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

## Dudas abiertas (a cerrar antes de la Fase 1)
1. **Exceso de bolsa:** proponemos `overage_minutes` en cada entrada en lugar de partir la entrada en dos. `is_overage` pasaría a derivarse.
2. **Aprobación de horas de responsables y admins:** proponemos que las de los responsables las apruebe el admin y que las de los admins se aprueben solas. Además, añadir el estado "devuelta con comentario".
3. **Visibilidad del responsable:** proponemos que vea los proyectos con al menos un miembro o una bolsa de su departamento, más aquellos en los que es miembro o gestor. En horas, carga y productividad, solo su departamento.
4. **Creación de clientes y proyectos:** proponemos admin y responsables.
5. **Alertas por gestor:** proponemos que cada gestor elija las suyas, todas activadas por defecto, y que el admin pueda editarlas.
6. **Un solo responsable por departamento** (`manager_user_id`): proponemos que sí.
