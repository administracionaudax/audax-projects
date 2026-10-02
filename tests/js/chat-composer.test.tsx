// @vitest-environment jsdom
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Composer, draftKey, readDraft } from '@/components/chat/composer';
import {
    bodyToEditable,
    editableToBody,
    mentionCandidates,
    mentionQueryAt,
} from '@/components/chat/mentions';
import { TooltipProvider } from '@/components/ui/tooltip';

/**
 * Editor del chat: Intro envía y Mayús+Intro salta de línea, menciones con autocompletado y
 * teclado (se guardan como <@ID>), @todos, borrador por conversación y edición.
 */

const people = [
    { id: 7, name: 'Ana Pérez', is_active: true },
    { id: 9, name: 'Luis Gil', is_active: true },
    { id: 11, name: 'Álvaro Ruiz', is_active: false },
];

function renderComposer(props: Partial<Parameters<typeof Composer>[0]> = {}) {
    const onSubmit = vi.fn<(body: string) => boolean>(() => true);
    const utils = render(
        <TooltipProvider>
            <Composer
                conversationId={5}
                people={people}
                onSubmit={onSubmit}
                {...props}
            />
        </TooltipProvider>,
    );

    return { ...utils, onSubmit, input: screen.getByRole('textbox') };
}

describe('menciones (texto del editor ↔ cuerpo)', () => {
    it('convierte @Nombre elegido en <@ID> y al revés', () => {
        const mentions = [
            { id: 7, name: 'Ana Pérez' },
            { id: 12, name: 'Ana' },
        ];

        expect(
            editableToBody(
                'Hola @Ana Pérez y @Ana, (@Ana) pero no ana@correo.es ni @Anabel',
                mentions,
            ),
        ).toBe('Hola <@7> y <@12>, (<@12>) pero no ana@correo.es ni @Anabel');

        expect(
            bodyToEditable('Hola <@7> y <@99>', new Map([[7, 'Ana Pérez']])),
        ).toEqual({
            text: 'Hola @Ana Pérez y <@99>',
            mentions: [{ id: 7, name: 'Ana Pérez' }],
        });
    });

    it('detecta la mención que se escribe antes del cursor', () => {
        expect(mentionQueryAt('hola @lu', 8)).toEqual({
            start: 5,
            query: 'lu',
        });
        expect(mentionQueryAt('@', 1)).toEqual({ start: 0, query: '' });
        expect(mentionQueryAt('correo@lu', 9)).toBeNull();
        expect(mentionQueryAt('hola @lu y más', 14)).toBeNull();
    });

    it('sugiere por nombre o apellido, sin tildes, y @todos', () => {
        expect(mentionCandidates(people, 'alv', true)).toEqual([
            { kind: 'person', id: 11, name: 'Álvaro Ruiz', is_active: false },
        ]);
        expect(
            mentionCandidates(people, 'gil', false).map((c) =>
                c.kind === 'person' ? c.name : 'todos',
            ),
        ).toEqual(['Luis Gil']);
        expect(mentionCandidates(people, 'to', true)).toEqual([
            { kind: 'everyone' },
        ]);
        expect(mentionCandidates(people, '', true)).toHaveLength(4);
    });
});

describe('Composer', () => {
    it('Intro envía (y vacía el editor) y Mayús+Intro salta de línea', async () => {
        const user = userEvent.setup();
        const { input, onSubmit } = renderComposer();

        await user.type(input, 'Primera línea{Shift>}{Enter}{/Shift}segunda');
        expect((input as HTMLTextAreaElement).value).toBe(
            'Primera línea\nsegunda',
        );

        await user.keyboard('{Enter}');

        expect(onSubmit).toHaveBeenCalledWith('Primera línea\nsegunda');
        expect((input as HTMLTextAreaElement).value).toBe('');
    });

    it('no envía un mensaje vacío', async () => {
        const user = userEvent.setup();
        const { input, onSubmit } = renderComposer();

        await user.type(input, '   {Enter}');

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Enviar' })).toHaveProperty(
            'disabled',
            true,
        );
    });

    it('con archivos pendientes (C3), «Enviar» publica aunque no haya texto y no lo borra si falla', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn<(body: string) => boolean>(() => false);
        const { input } = renderComposer({ pendingFiles: 1, onSubmit });
        const send = screen.getByRole('button', { name: 'Enviar' });

        expect(send).toHaveProperty('disabled', false);
        await user.click(send);
        expect(onSubmit).toHaveBeenCalledWith('');

        await user.type(input, 'Con el acta');
        await user.click(send);
        expect(onSubmit).toHaveBeenLastCalledWith('Con el acta');
        // Falló (devuelve false): el texto sigue ahí para reintentar.
        expect((input as HTMLTextAreaElement).value).toBe('Con el acta');
    });

    it('autocompleta menciones con el teclado y las envía como <@ID>', async () => {
        const user = userEvent.setup();
        const { input, onSubmit } = renderComposer();

        await user.type(input, 'Hola @l');

        const listbox = screen.getByRole('listbox', {
            name: 'Personas que puedes mencionar',
        });
        expect(listbox).toBeTruthy();
        expect(input.getAttribute('aria-controls')).toBe(listbox.id);
        expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(
            ['LGLuis Gil'],
        );

        await user.keyboard('{Enter}');
        expect((input as HTMLTextAreaElement).value).toBe('Hola @Luis Gil ');
        expect(screen.queryByRole('listbox')).toBeNull();

        await user.type(input, 'y @');
        await user.keyboard('{ArrowDown}');
        const options = screen.getAllByRole('option');
        expect(options[1].getAttribute('aria-selected')).toBe('true');
        expect(input.getAttribute('aria-activedescendant')).toBe(options[1].id);
        await user.keyboard('{Tab}');

        await user.type(input, 'y @tod');
        await user.keyboard('{Enter}');
        await user.keyboard('{Enter}');

        expect(onSubmit).toHaveBeenCalledWith('Hola <@9> y <@7> y @todos');
    });

    it('Esc cierra las sugerencias sin enviar', async () => {
        const user = userEvent.setup();
        const { input, onSubmit } = renderComposer();

        await user.type(input, '@an');
        expect(screen.getByRole('listbox')).toBeTruthy();

        await user.keyboard('{Escape}');

        expect(screen.queryByRole('listbox')).toBeNull();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('guarda el borrador de cada conversación y lo recupera', async () => {
        const user = userEvent.setup();
        const { input, unmount } = renderComposer();

        await user.type(input, 'Borrador para @l');
        await user.keyboard('{Enter}');
        await waitFor(() =>
            expect(readDraft(5)).toEqual({
                text: 'Borrador para @Luis Gil ',
                mentions: [{ id: 9, name: 'Luis Gil' }],
            }),
        );
        unmount();

        const again = renderComposer();
        expect((again.input as HTMLTextAreaElement).value).toBe(
            'Borrador para @Luis Gil ',
        );

        await user.type(again.input, '{Enter}');
        expect(again.onSubmit).toHaveBeenCalledWith('Borrador para <@9>');
        await waitFor(() =>
            expect(localStorage.getItem(draftKey(5))).toBeNull(),
        );
    });

    it('sin almacenamiento disponible sigue funcionando', async () => {
        const user = userEvent.setup();
        const spy = vi
            .spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => {
                throw new Error('QuotaExceededError');
            });
        const { input, onSubmit } = renderComposer();

        await user.type(input, 'Hola{Enter}');

        expect(onSubmit).toHaveBeenCalledWith('Hola');
        spy.mockRestore();
    });

    it('↑ con el editor vacío edita el último mensaje propio', async () => {
        const user = userEvent.setup();
        const onEditLast = vi.fn();
        const { input } = renderComposer({ onEditLast });

        await user.click(input);
        await user.keyboard('{ArrowUp}');

        expect(onEditLast).toHaveBeenCalledTimes(1);
    });

    it('avisa del tope de 10.000 caracteres y no deja enviar', async () => {
        const { input, onSubmit } = renderComposer();

        fireEvent.change(input, { target: { value: 'a'.repeat(10_001) } });

        expect(screen.getByText(/Como mucho 10\.000 caracteres/)).toBeTruthy();
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByRole('button', { name: 'Enviar' })).toHaveProperty(
            'disabled',
            true,
        );
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('en edición guarda con Intro (manteniendo las menciones) y cancela con Esc', async () => {
        const user = userEvent.setup();
        const onCancel = vi.fn();
        const { input, onSubmit } = renderComposer({
            mode: 'edit',
            initial: bodyToEditable('Hola <@7>', new Map([[7, 'Ana Pérez']])),
            onCancel,
        });

        expect(screen.getByRole('textbox', { name: 'Edita el mensaje' })).toBe(
            input,
        );
        expect((input as HTMLTextAreaElement).value).toBe('Hola @Ana Pérez');

        await user.type(input, ', ¿vienes?{Enter}');
        expect(onSubmit).toHaveBeenCalledWith('Hola <@7>, ¿vienes?');

        await user.type(input, '{Escape}');
        expect(onCancel).toHaveBeenCalled();
    });

    it('en edición no deja el mensaje vacío', async () => {
        const user = userEvent.setup();
        const { input, onSubmit } = renderComposer({
            mode: 'edit',
            initial: { text: 'x', mentions: [] },
        });

        await user.clear(input);
        await user.keyboard('{Enter}');

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toContain(
            'no puede quedar vacío',
        );
    });

    it('muestra a quién se responde y deja cancelarlo', async () => {
        const user = userEvent.setup();
        const onCancelReply = vi.fn();
        const { input } = renderComposer({
            replyTo: { id: 3, author: 'Luis Gil', excerpt: '¿Quién revisa?' },
            onCancelReply,
        });

        expect(screen.getByText('Respondiendo a Luis Gil')).toBeTruthy();
        expect(input.getAttribute('aria-describedby')).toContain(
            screen.getByText('Respondiendo a Luis Gil').parentElement?.id ??
                'x',
        );

        await user.click(
            screen.getByRole('button', { name: 'Cancelar la respuesta' }),
        );
        expect(onCancelReply).toHaveBeenCalled();
    });
});
