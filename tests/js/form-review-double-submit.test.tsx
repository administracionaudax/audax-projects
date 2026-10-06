// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CommentForm } from '@/components/suggestions/suggestion-comments';

const post = vi.fn();

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: { post: (...args: unknown[]) => post(...args) },
}));

// El editor real (Tiptap) no hace falta: su Ctrl/⌘+Intro llama a onSubmit.
vi.mock('@/components/rich-text/rich-text-editor', () => ({
    default: ({ onSubmit }: { onSubmit?: () => void }) => (
        <button type="button" onClick={() => onSubmit?.()}>
            ctrl-intro
        </button>
    ),
}));

/** D-310 (H-D16): dos Ctrl/⌘+Intro seguidos publicaban el comentario dos veces. */
describe('comentario de una sugerencia', () => {
    it('no se envía dos veces mientras el primero está en camino', () => {
        render(
            <CommentForm
                postId={7}
                people={[]}
                attachmentMaxMb={10}
                label="Comentario"
            />,
        );
        const shortcut = screen.getByRole('button', { name: 'ctrl-intro' });

        fireEvent.click(shortcut);
        fireEvent.click(shortcut);

        expect(post).toHaveBeenCalledTimes(1);

        // Al terminar, se puede volver a publicar.
        const options = post.mock.calls[0][2] as { onFinish: () => void };
        options.onFinish();
        fireEvent.click(shortcut);
        expect(post).toHaveBeenCalledTimes(2);
    });
});
