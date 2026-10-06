import { ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { AwayDialog } from '@/components/weeklies/away-dialog';
import { useUser } from '@/hooks/use-auth';
import { useIsMobile } from '@/hooks/use-mobile';
import { t } from '@/lib/i18n';

export function NavUser() {
    const user = useUser();
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const [awayOpen, setAwayOpen] = useState(false);

    if (!user) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="group text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent"
                            data-test="sidebar-menu-button"
                        >
                            <UserInfo user={user} showRole />
                            <span className="sr-only">
                                {t('user_menu.open')}
                            </span>
                            <ChevronsUpDown
                                aria-hidden="true"
                                className="ml-auto size-4"
                            />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-md"
                        align="end"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'left'
                                  : 'bottom'
                        }
                    >
                        <UserMenuContent
                            user={user}
                            onAway={() => setAwayOpen(true)}
                        />
                    </DropdownMenuContent>
                </DropdownMenu>
                {/* Fuera del menú: el diálogo sigue abierto al cerrarse el desplegable. */}
                <AwayDialog
                    person={user}
                    current={user.weekly_away}
                    self
                    open={awayOpen}
                    onOpenChange={setAwayOpen}
                />
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
