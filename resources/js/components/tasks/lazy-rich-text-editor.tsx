import { lazy, Suspense } from 'react';
import type { RichTextEditorProps } from '@/components/rich-text/rich-text-editor';
import { Skeleton } from '@/components/ui/skeleton';
import { t } from '@/lib/i18n';

// Tiptap solo se descarga cuando alguien edita una descripción o escribe un comentario.
const Editor = lazy(() => import('@/components/rich-text/rich-text-editor'));

export function LazyRichTextEditor(props: RichTextEditorProps) {
    return (
        <Suspense
            fallback={
                <div
                    role="status"
                    aria-label={t('rich_text.loading')}
                    className="grid gap-2"
                >
                    <Skeleton className="h-8 w-full rounded-[3px]" />
                    <Skeleton className="h-24 w-full rounded-[3px]" />
                </div>
            }
        >
            <Editor {...props} />
        </Suspense>
    );
}
