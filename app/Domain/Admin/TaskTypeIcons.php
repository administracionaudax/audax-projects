<?php

namespace App\Domain\Admin;

/**
 * Lista cerrada de iconos (lucide) para los tipos de tarea (SPEC §4.3). El frontend tiene el mismo
 * catálogo en resources/js/components/admin/task-type-icon.tsx (tests/js/admin-task-type-icons.test.ts
 * comprueba que coinciden).
 */
final class TaskTypeIcons
{
    /**
     * @var list<string>
     */
    public const array ICONS = [
        'palette',
        'pen-tool',
        'image',
        'layout-template',
        'code-xml',
        'bug',
        'server',
        'database',
        'smartphone',
        'monitor',
        'globe',
        'search',
        'file-text',
        'type',
        'languages',
        'megaphone',
        'share-2',
        'target',
        'trending-up',
        'chart-column',
        'mail',
        'message-square',
        'users',
        'handshake',
        'briefcase',
        'clipboard-list',
        'calendar',
        'presentation',
        'graduation-cap',
        'life-buoy',
        'headphones',
        'wrench',
        'shield-check',
        'lightbulb',
        'video',
        'camera',
        'shopping-cart',
        'book-open',
    ];
}
