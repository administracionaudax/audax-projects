// @vitest-environment jsdom
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import {
    AttachFilesButton,
    AttachmentDropzone,
} from '@/components/chat/media/attachment-dropzone';
import { AttachmentList } from '@/components/chat/media/attachment-list';
import { namePastedFile } from '@/components/chat/media/media-utils';
import type { ChatAttachment } from '@/components/chat/media/types';

function attachment(overrides: Partial<ChatAttachment>): ChatAttachment {
    return {
        id: 1,
        original_name: 'plano.png',
        mime: 'image/png',
        size: 2048,
        kind: 'image',
        is_image: true,
        url: '/adjuntos/1?signature=a',
        thumbnail_url: '/adjuntos/1/miniatura?signature=a',
        ...overrides,
    };
}

const files: ChatAttachment[] = [
    attachment({ id: 1, original_name: 'fachada.jpg', mime: 'image/jpeg' }),
    attachment({
        id: 2,
        original_name: 'salon.webp',
        mime: 'image/webp',
        thumbnail_url: null,
    }),
    attachment({
        id: 3,
        original_name: 'Presupuesto final.pdf',
        mime: 'application/pdf',
        size: 1_572_864,
        kind: 'file',
        is_image: false,
        url: '/adjuntos/3?signature=a',
        thumbnail_url: null,
    }),
    attachment({
        id: 4,
        original_name: 'logo.svg',
        mime: 'image/svg+xml',
        kind: 'svg',
        is_image: false,
        url: '/adjuntos/4?signature=a',
        thumbnail_url: null,
    }),
];

describe('AttachmentList', () => {
    it('las imágenes van en miniatura (o con el icono hasta que exista) y el resto como tarjeta de descarga', () => {
        render(<AttachmentList attachments={files} />);

        const images = screen.getByRole('list', {
            name: 'Imágenes adjuntas: 2',
        });
        const buttons = within(images).getAllByRole('button');
        expect(
            buttons.map((button) => button.getAttribute('aria-label')),
        ).toEqual([
            'Ver la imagen «fachada.jpg»',
            'Ver la imagen «salon.webp»',
        ]);
        expect(buttons[0].querySelector('img')?.getAttribute('src')).toBe(
            '/adjuntos/1/miniatura?signature=a',
        );
        expect(buttons[1].querySelector('img')).toBeNull();

        const cards = within(
            screen.getByRole('list', { name: 'Archivos adjuntos: 2' }),
        ).getAllByRole('link');
        expect(cards[0].getAttribute('aria-label')).toBe(
            'Descargar «Presupuesto final.pdf» (1,5 MB)',
        );
        expect(cards[0].getAttribute('download')).toBe('Presupuesto final.pdf');
        expect(cards[0].textContent).toContain('PDF');
        // El SVG nunca se muestra: se descarga.
        expect(cards[1].getAttribute('href')).toBe('/adjuntos/4?signature=a');
        expect(cards[1].getAttribute('download')).toBe('logo.svg');
        expect(cards[1].querySelector('img')).toBeNull();
    });

    it('sin adjuntos no pinta nada', () => {
        const { container } = render(<AttachmentList attachments={[]} />);

        expect(container.innerHTML).toBe('');
    });

    it('el visor se abre, pasa de imagen con las flechas y se cierra con Escape', async () => {
        const user = userEvent.setup();
        render(<AttachmentList attachments={files} />);

        await user.click(
            screen.getByRole('button', { name: 'Ver la imagen «fachada.jpg»' }),
        );

        const dialog = screen.getByRole('dialog', { name: 'fachada.jpg' });
        expect(
            within(dialog)
                .getByRole('img', { name: 'fachada.jpg' })
                .getAttribute('src'),
        ).toBe('/adjuntos/1?signature=a');
        expect(within(dialog).getByText('Imagen 1 de 2')).toBeTruthy();
        expect(
            within(dialog)
                .getByRole('link', { name: 'Descargar' })
                .getAttribute('download'),
        ).toBe('fachada.jpg');

        await user.keyboard('{ArrowRight}');
        expect(screen.getByRole('dialog', { name: 'salon.webp' })).toBeTruthy();
        expect(screen.getByText('Imagen 2 de 2')).toBeTruthy();

        await user.keyboard('{ArrowRight}');
        expect(
            screen.getByRole('dialog', { name: 'fachada.jpg' }),
        ).toBeTruthy();

        await user.click(
            screen.getByRole('button', { name: 'Imagen anterior' }),
        );
        expect(screen.getByRole('dialog', { name: 'salon.webp' })).toBeTruthy();

        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});

function dataTransfer(list: File[]): DataTransfer {
    return {
        types: ['Files'],
        files: list,
        items: list.map((file) => ({ kind: 'file', getAsFile: () => file })),
        dropEffect: 'none',
    } as unknown as DataTransfer;
}

describe('AttachmentDropzone', () => {
    it('al arrastrar archivos avisa de que se pueden soltar y al soltarlos los entrega', () => {
        const onFiles = vi.fn();
        render(
            <AttachmentDropzone onFiles={onFiles}>
                <p>Conversación</p>
            </AttachmentDropzone>,
        );
        const zone = screen.getByText('Conversación')
            .parentElement as HTMLElement;
        const dropped = [
            new File(['a'], 'uno.pdf', { type: 'application/pdf' }),
            new File(['b'], 'dos.png', { type: 'image/png' }),
        ];

        fireEvent.dragEnter(zone, { dataTransfer: dataTransfer(dropped) });
        expect(screen.getByRole('status').textContent).toBe(
            'Suelta los archivos para adjuntarlos',
        );

        fireEvent.drop(zone, { dataTransfer: dataTransfer(dropped) });

        expect(onFiles).toHaveBeenCalledWith(dropped);
        expect(screen.queryByRole('status')).toBeNull();
    });

    it('al pegar una captura la entrega con un nombre con fecha', () => {
        const onFiles = vi.fn();
        render(
            <AttachmentDropzone onFiles={onFiles}>
                <textarea aria-label="Mensaje" />
            </AttachmentDropzone>,
        );

        fireEvent.paste(screen.getByRole('textbox', { name: 'Mensaje' }), {
            clipboardData: dataTransfer([
                new File(['png'], 'image.png', { type: 'image/png' }),
            ]),
        });

        const [pasted] = onFiles.mock.calls[0][0] as File[];
        expect(pasted.name).toMatch(/^captura-\d{8}-\d{6}\.png$/);
        expect(pasted.type).toBe('image/png');
    });

    it('pegar texto no toca nada; desactivada no entrega archivos', () => {
        const onFiles = vi.fn();
        const { rerender } = render(
            <AttachmentDropzone onFiles={onFiles}>
                <textarea aria-label="Mensaje" />
            </AttachmentDropzone>,
        );

        fireEvent.paste(screen.getByRole('textbox'), {
            clipboardData: {
                files: [],
                items: [{ kind: 'string', getAsFile: () => null }],
            },
        });
        expect(onFiles).not.toHaveBeenCalled();

        rerender(
            <AttachmentDropzone onFiles={onFiles} disabled>
                <textarea aria-label="Mensaje" />
            </AttachmentDropzone>,
        );
        fireEvent.drop(
            screen.getByRole('textbox').parentElement as HTMLElement,
            {
                dataTransfer: dataTransfer([new File(['a'], 'uno.pdf')]),
            },
        );
        expect(onFiles).not.toHaveBeenCalled();
    });

    it('el clip abre el selector de archivos y entrega los elegidos', async () => {
        const user = userEvent.setup();
        const onFiles = vi.fn();
        render(<AttachFilesButton onFiles={onFiles} />);
        const input = document.querySelector<HTMLInputElement>(
            '[data-test="chat-attach-input"]',
        );

        expect(
            screen.getByRole('button', { name: 'Adjuntar archivos' }),
        ).toBeTruthy();
        expect(input?.multiple).toBe(true);

        const chosen = new File(['a'], 'acta.pdf', { type: 'application/pdf' });
        await user.upload(input as HTMLInputElement, chosen);

        expect(onFiles).toHaveBeenCalledWith([chosen]);
    });

    it('solo renombra las capturas genéricas', () => {
        const named = new File(['a'], 'mi-foto.png', { type: 'image/png' });

        expect(namePastedFile(named)).toBe(named);
        expect(
            namePastedFile(
                new File(['a'], 'image.jpeg', { type: 'image/jpeg' }),
                new Date(2026, 8, 27, 10, 5, 9),
            ).name,
        ).toBe('captura-20260927-100509.jpg');
    });
});
