<?php

namespace App\Domain\Audit;

/**
 * Qué se puede filtrar en la auditoría visible (D-074): las entidades (log_name de activity_log)
 * y las acciones (event). Las claves son las de la URL (?entidad=task&accion=updated) y las de
 * lang/es/audit.php; todo lo que no esté aquí se ignora.
 *
 * - log_name: el nombre de la tabla en los modelos con LogsDomainActivity; los registros propios
 *   usan holidays, task_statuses, timesheet_periods, time_entry_locks, settings, privacy y chat
 *   (moderación de mensajes y cambios en los grupos de la Fase 6) e import (una entrada resumen
 *   por cada importación de ClickUp, D-136) y report-delivery (descargas, envíos y subidas de
 *   informes, D-139; Google Sheets, D-142).
 */
final class AuditCatalog
{
    /**
     * Entidad del filtro → log_name que abarca, en el orden del selector.
     *
     * @var array<string, list<string>>
     */
    public const array ENTITIES = [
        'project' => ['projects'],
        'hour_bank' => ['hour_banks'],
        'task' => ['tasks'],
        'time_entry' => ['time_entries'],
        'timesheet' => ['timesheet_periods'],
        'time_lock' => ['time_entry_locks'],
        'client' => ['clients'],
        'absence' => ['absences'],
        'holiday' => ['holidays'],
        'template' => ['project_templates'],
        'recurring_rule' => ['recurring_task_rules'],
        'task_status' => ['task_statuses'],
        'settings' => ['settings'],
        'privacy' => ['privacy'],
        'chat' => ['chat'],
        'import' => ['import'],
        'report_delivery' => ['report-delivery'],
    ];

    /**
     * Acción del filtro → eventos que abarca. Las cuatro primeras son las de los modelos (crear,
     * cambiar, borrar y restaurar; los festivos usan sus propios nombres); el resto, los eventos
     * propios de cada flujo.
     *
     * @var array<string, list<string>>
     */
    public const array ACTIONS = [
        'created' => ['created', 'holiday_created', 'holidays_national', 'holidays_imported'],
        'updated' => ['updated', 'holiday_updated'],
        'deleted' => ['deleted', 'holiday_deleted'],
        'restored' => ['restored'],
        'submitted' => ['submitted'],
        'approved' => ['approved', 'auto_approved'],
        'returned' => ['returned'],
        'withdrawn' => ['withdrawn'],
        'reopened' => ['reopened'],
        'locked' => ['locked'],
        'unlocked' => ['unlocked'],
        'member_added' => ['member_added', 'manager_added'],
        'member_removed' => ['member_removed', 'manager_removed'],
        'status_changed' => ['task_status.category_changed', 'task_status.replaced'],
        'acknowledged' => ['acknowledged'],
        'export_requested' => ['export_requested'],
        'export_downloaded' => ['export_downloaded'],
        'message_hidden' => ['hidden'],
        'message_unhidden' => ['unhidden'],
        'group_changed' => ['group_created', 'group_renamed', 'group_members_added', 'group_member_removed', 'group_left'],
        'imported' => ['clickup_import'],
        'sheets_exported' => ['sheets'],
    ];

    /** Acciones básicas (el resto se agrupan como «otros eventos» en el selector). */
    public const array BASIC_ACTIONS = ['created', 'updated', 'deleted', 'restored'];

    /** Entidad de un log_name, o null si no es de ninguna del catálogo. */
    public static function entityOf(?string $logName): ?string
    {
        foreach (self::ENTITIES as $entity => $logNames) {
            if (in_array($logName, $logNames, true)) {
                return $entity;
            }
        }

        return null;
    }

    public static function entityLabel(?string $entity): string
    {
        return self::text($entity === null ? 'audit.entities.other' : "audit.entities.{$entity}");
    }

    public static function actionLabel(string $action): string
    {
        return self::text("audit.actions.{$action}");
    }

    /**
     * Etiqueta de un evento concreto (la de la fila): «Alta», «Semana enviada»… Los eventos con
     * punto (task_status.replaced) se buscan con guion bajo. Si no se conoce, el propio evento.
     */
    public static function eventLabel(string $event): string
    {
        $key = 'audit.events.'.str_replace('.', '_', $event);
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $event;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function entityOptions(): array
    {
        return array_map(
            fn (string $entity): array => ['value' => $entity, 'label' => self::entityLabel($entity)],
            array_keys(self::ENTITIES),
        );
    }

    /**
     * @return list<array{value: string, label: string, basic: bool}>
     */
    public static function actionOptions(): array
    {
        return array_map(
            fn (string $action): array => [
                'value' => $action,
                'label' => self::actionLabel($action),
                'basic' => in_array($action, self::BASIC_ACTIONS, true),
            ],
            array_keys(self::ACTIONS),
        );
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
