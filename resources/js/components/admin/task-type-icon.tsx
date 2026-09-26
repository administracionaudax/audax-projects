import type { LucideIcon } from 'lucide-react';
import {
    BookOpen,
    Briefcase,
    Bug,
    Calendar,
    Camera,
    ChartColumn,
    ClipboardList,
    CodeXml,
    Database,
    FileText,
    Globe,
    GraduationCap,
    Handshake,
    Headphones,
    Image,
    Languages,
    LayoutTemplate,
    LifeBuoy,
    Lightbulb,
    Mail,
    Megaphone,
    MessageSquare,
    Monitor,
    Palette,
    PenTool,
    Presentation,
    Search,
    Server,
    Share2,
    ShieldCheck,
    ShoppingCart,
    Smartphone,
    Tag,
    Target,
    TrendingUp,
    Type,
    Users,
    Video,
    Wrench,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Catálogo cerrado de iconos de los tipos de tarea: el mismo que App\Domain\Admin\TaskTypeIcons
 * (tests/js/admin-task-type-icons.test.ts comprueba que coinciden).
 */
export const TASK_TYPE_ICONS: Record<string, LucideIcon> = {
    palette: Palette,
    'pen-tool': PenTool,
    image: Image,
    'layout-template': LayoutTemplate,
    'code-xml': CodeXml,
    bug: Bug,
    server: Server,
    database: Database,
    smartphone: Smartphone,
    monitor: Monitor,
    globe: Globe,
    search: Search,
    'file-text': FileText,
    type: Type,
    languages: Languages,
    megaphone: Megaphone,
    'share-2': Share2,
    target: Target,
    'trending-up': TrendingUp,
    'chart-column': ChartColumn,
    mail: Mail,
    'message-square': MessageSquare,
    users: Users,
    handshake: Handshake,
    briefcase: Briefcase,
    'clipboard-list': ClipboardList,
    calendar: Calendar,
    presentation: Presentation,
    'graduation-cap': GraduationCap,
    'life-buoy': LifeBuoy,
    headphones: Headphones,
    wrench: Wrench,
    'shield-check': ShieldCheck,
    lightbulb: Lightbulb,
    video: Video,
    camera: Camera,
    'shopping-cart': ShoppingCart,
    'book-open': BookOpen,
};

/** Icono de un tipo de tarea (una etiqueta genérica si no tiene o no se reconoce). */
export function TaskTypeIcon({
    name,
    color,
    className,
}: {
    name: string | null;
    color?: string;
    className?: string;
}) {
    const Icon = (name ? TASK_TYPE_ICONS[name] : undefined) ?? Tag;

    return (
        <Icon
            aria-hidden="true"
            className={cn('size-4 shrink-0', className)}
            style={color ? { color } : undefined}
            strokeWidth={1.75}
        />
    );
}
