import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    FileText,
    FolderKanban,
    MessageSquare,
    Search,
    SquareCheck,
    TrendingUp,
    User,
} from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import {
    CommandDialog,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Spinner } from '@/components/ui/spinner';
import {
    SEARCH_MIN_LENGTH,
    useSearchResults,
} from '@/hooks/use-search-results';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SearchResult, SearchResultType } from '@/types';

/** Orden, etiqueta e icono de cada grupo de resultados. */
const GROUPS: {
    type: SearchResultType;
    label: TranslationKey;
    icon: LucideIcon;
}[] = [
    { type: 'page', label: 'search.group.page', icon: FileText },
    { type: 'person', label: 'search.group.person', icon: User },
    { type: 'client', label: 'search.group.client', icon: Building2 },
    { type: 'project', label: 'search.group.project', icon: FolderKanban },
    { type: 'task', label: 'search.group.task', icon: SquareCheck },
    { type: 'forecast', label: 'search.group.forecast', icon: TrendingUp },
    { type: 'message', label: 'search.group.message', icon: MessageSquare },
];

export function isSearchShortcut(event: KeyboardEvent): boolean {
    return (
        (event.metaKey || event.ctrlKey) &&
        !event.altKey &&
        !event.shiftKey &&
        event.key.toLowerCase() === 'k'
    );
}

export function isApplePlatform(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    return /mac|iphone|ipad|ipod/i.test(navigator.userAgent);
}

type GlobalSearchContextValue = {
    open: boolean;
    setOpen: (open: boolean) => void;
};

const GlobalSearchContext = createContext<GlobalSearchContextValue | null>(
    null,
);

export function useGlobalSearch(): GlobalSearchContextValue {
    const context = useContext(GlobalSearchContext);

    if (!context) {
        throw new Error(
            'useGlobalSearch debe usarse dentro de <GlobalSearchProvider>.',
        );
    }

    return context;
}

/**
 * Búsqueda global (SPEC §3). Registra el atajo Ctrl+K / Cmd+K en todo el documento
 * y monta la paleta. Los resultados llegan de GET /buscar ya filtrados por permisos.
 */
export function GlobalSearchProvider({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (!isSearchShortcut(event)) {
                return;
            }

            event.preventDefault();
            setOpen((current) => !current);
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    const value = useMemo(() => ({ open, setOpen }), [open]);

    return (
        <GlobalSearchContext.Provider value={value}>
            {children}
            <SearchPalette open={open} onOpenChange={setOpen} />
        </GlobalSearchContext.Provider>
    );
}

export function SearchPalette({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [query, setQuery] = useState('');
    const {
        status,
        results,
        query: trimmed,
    } = useSearchResults(open ? query : '');

    const handleOpenChange = useCallback(
        (next: boolean) => {
            if (!next) {
                setQuery('');
            }

            onOpenChange(next);
        },
        [onOpenChange],
    );

    const select = (result: SearchResult) => {
        handleOpenChange(false);
        router.visit(result.url);
    };

    const grouped = GROUPS.map((group) => ({
        ...group,
        items: results.filter((result) => result.type === group.type),
    })).filter((group) => group.items.length > 0);

    return (
        <CommandDialog
            open={open}
            onOpenChange={handleOpenChange}
            title={t('search.title')}
            description={t('search.description')}
            commandProps={{
                shouldFilter: false,
                loop: true,
                // cmdk nombra el campo con esta etiqueta (aria-labelledby gana a un aria-label).
                label: t('search.input_label'),
            }}
        >
            <CommandInput
                value={query}
                onValueChange={setQuery}
                placeholder={t('search.placeholder')}
            />
            <CommandList label={t('search.results_label')}>
                <div aria-live="polite" className="empty:hidden">
                    {status === 'idle' && (
                        <SearchMessage>
                            {t('search.min_length', { min: SEARCH_MIN_LENGTH })}
                        </SearchMessage>
                    )}
                    {status === 'loading' && (
                        <SearchMessage>
                            <Spinner />
                            {t('search.loading')}
                        </SearchMessage>
                    )}
                    {status === 'error' && (
                        <SearchMessage className="text-danger">
                            {t('search.error')}
                        </SearchMessage>
                    )}
                    {status === 'success' && results.length === 0 && (
                        <SearchMessage>
                            {t('search.empty', { query: trimmed })}
                        </SearchMessage>
                    )}
                </div>

                {grouped.map(({ type, label, icon: Icon, items }) => (
                    <CommandGroup key={type} heading={t(label)}>
                        {items.map((result) => (
                            <CommandItem
                                key={`${result.type}-${result.id}`}
                                value={`${result.type}-${result.id}`}
                                onSelect={() => select(result)}
                            >
                                <Icon aria-hidden="true" strokeWidth={1.5} />
                                <span className="flex min-w-0 flex-col">
                                    <span className="truncate">
                                        {result.title}
                                    </span>
                                    {result.subtitle && (
                                        <span className="truncate text-xs text-muted-foreground">
                                            {result.subtitle}
                                        </span>
                                    )}
                                </span>
                            </CommandItem>
                        ))}
                    </CommandGroup>
                ))}
            </CommandList>
        </CommandDialog>
    );
}

function SearchMessage({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'flex items-center justify-center gap-2 px-4 py-6 text-center text-sm text-muted-foreground',
                className,
            )}
        >
            {children}
        </p>
    );
}

/** Botón de la cabecera que abre la paleta (muestra el atajo de teclado). */
export function SearchTrigger({ className }: { className?: string }) {
    const { setOpen } = useGlobalSearch();
    const shortcut = isApplePlatform() ? '⌘K' : 'Ctrl K';

    return (
        <button
            type="button"
            onClick={() => setOpen(true)}
            aria-label={t('search.open', { shortcut })}
            aria-keyshortcuts="Control+K Meta+K"
            className={cn(
                'inline-flex h-9 items-center gap-2 rounded-md border border-input bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                FOCUS_RING,
                className,
            )}
        >
            <Search aria-hidden="true" className="size-4" strokeWidth={1.5} />
            <span className="hidden sm:inline">{t('search.trigger')}</span>
            <kbd className="pointer-events-none ml-2 hidden rounded-sm border bg-muted px-1.5 font-sans text-[11px] text-muted-foreground sm:inline-block">
                {shortcut}
            </kbd>
        </button>
    );
}
