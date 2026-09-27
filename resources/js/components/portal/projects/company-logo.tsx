import { AudaxWordmark } from '@/components/app-logo';
import type { PortalCompany } from '@/components/portal/projects/types';
import { cn } from '@/lib/utils';

/**
 * Logo de la cabecera del portal (D-067): el de /admin/identidad, sobre una placa blanca (los logos
 * se diseñan para fondo claro y la cabecera va sobre el degradado), o el logotipo AUDAX en blanco si
 * no se ha subido ninguno. La placa es blanca en los dos temas, como el fondo del PDF y de los emails.
 */
export function CompanyLogo({
    company,
    className,
}: {
    company: PortalCompany | null | undefined;
    className?: string;
}) {
    if (!company?.logo) {
        return (
            <AudaxWordmark tone="inverse" className={cn('h-4', className)} />
        );
    }

    return (
        <span
            className={cn(
                'flex h-9 shrink-0 items-center rounded-[3px] bg-white px-2 py-1',
                className,
            )}
            data-test="portal-company-logo"
        >
            <img
                src={company.logo.url}
                alt={company.name}
                width={company.logo.width}
                height={company.logo.height}
                className="h-full max-h-7 w-auto max-w-32 object-contain sm:max-w-40"
            />
        </span>
    );
}
