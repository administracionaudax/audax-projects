import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Palmtree, Settings } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { t } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
    /** «Estoy fuera…» de la Weekly (D-228): abre el diálogo (lo pinta NavUser). */
    onAway?: () => void;
};

export function UserMenuContent({ user, onAway }: Props) {
    const cleanup = useMobileNavigation();
    const useWeeklies = usePage().props.auth?.can?.useWeeklies === true;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings aria-hidden="true" className="mr-2" />
                        {t('user_menu.settings')}
                    </Link>
                </DropdownMenuItem>
                {onAway && useWeeklies ? (
                    <DropdownMenuItem
                        className="cursor-pointer"
                        onSelect={() => onAway()}
                        data-test="user-menu-away"
                    >
                        <Palmtree aria-hidden="true" className="mr-2" />
                        {user.weekly_away
                            ? t('weeklies.away.change_self')
                            : t('weeklies.away.open_self')}
                    </DropdownMenuItem>
                ) : null}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut aria-hidden="true" className="mr-2" />
                    {t('user_menu.logout')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}
