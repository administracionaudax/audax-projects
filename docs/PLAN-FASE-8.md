# Plan de la Fase 8: puesta en marcha

Pedida por el propietario el 03/10/2026, tras cerrar las fases 0 a 7. Rama `fase-8`.

## Contexto
- La agencia trabaja hoy en ClickUp:
  - **workspace:** «Audax Studio», 14 miembros,
  - **histórico:** desde marzo de 2024, 17.545 tareas y 25.525 registros de horas (17.620 h).
- Para dejar ClickUp hacen falta tres cosas:
  1. un acceso restringido para los **colaboradores externos**: Amparo y las tres cuentas invitadas de ClickUp,
  2. **importar todo ClickUp**,
  3. el botón «Iniciar temporizador» de la cabecera, ya hecho en `fase-7`.

## Entregas
| Entrega | Contenido | Quién |
|---|---|---|
| **8.1 Contrato** | Rol `collaborator` con su migración, `User::isCollaborator()`, `visibleProjectIds()` y `canSeeProject()`, el tipo TS, este plan y D-134 a D-136 | Yo |
| **8.2 Colaboradores** | Acceso restringido (D-134): rutas cerradas por defecto, políticas, consultas, navegación, búsqueda, chat, Inicio y gestión de usuarios | Agente A |
| **8.3 Importador** | `app:import-clickup` (D-135 y D-136): idempotente, con simulación, informe y tests con un export de ejemplo | Agente B |
| **8.4 Cierre** | Integración, revisión adversarial, despliegue, importación en el servidor (copia previa) y comprobación con el propietario | Yo |

## Personas (respuesta del propietario del 03/10)
| Departamento | Personas | Responsable |
|---|---|---|
| Diseño | Jero, Belén, Aga, Toni y Amparo (colaboradora) | Aga |
| Desarrollo | Wendy y Jose Luis | Jose Luis |
| Contenidos | Julieta, Mairena y Mireya | Mireya |
| Sin departamento | Alfredo | |

- **Administradores:** Alfredo, Toni y Jose Luis.
- **Colaboradores externos:** Amparo (en Diseño), Daniel Espinosa y Raúl Ramírez (AgenciaSEO) y Pablo Ramírez (Melodía).
- **Datos reales:** los correos y los roles de cada persona van en un fichero de personas que **no** entra en Git: `personas.json`, junto al export.

## Verificación
- **Local:** Pest, PHPStan nivel 7, Vitest, `tsc`, `vp check` y compilación. E2E del colaborador y E2E de regresión.
- **Servidor:**
  - `desplegar-dev.sh --tests`,
  - importación con `scripts/heavy.sh` después de una copia de la base,
  - recuentos comparados con el export,
  - batería V y webs.
