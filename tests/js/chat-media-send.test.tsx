// @vitest-environment jsdom
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    props: { config: { max_attachment_mb: 1, max_audio_seconds: 300 } },
}));
const toast = vi.hoisted(() => ({ error: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage: () => page }));
vi.mock('sonner', () => ({ toast }));

import { MediaComposerTray } from '@/components/chat/media/media-composer-tray';
import {
    MediaUploadError,
    mediaFormData,
    sendWithMedia,
} from '@/components/chat/media/send-with-media';
import { useChatMediaComposer } from '@/components/chat/media/use-media-composer';
import type { MediaComposer } from '@/components/chat/media/use-media-composer';

/** XMLHttpRequest falso: guarda la petición y deja responder, fallar o abortar desde el test. */
class FakeXhr extends EventTarget {
    static last: FakeXhr | null = null;

    method = '';

    url = '';

    headers: Record<string, string> = {};

    body: FormData | null = null;

    status = 0;

    responseText = '';

    upload = new EventTarget();

    constructor() {
        super();
        FakeXhr.last = this;
    }

    open(method: string, url: string): void {
        this.method = method;
        this.url = url;
    }

    setRequestHeader(name: string, value: string): void {
        this.headers[name] = value;
    }

    send(body: FormData): void {
        this.body = body;
    }

    abort(): void {
        this.dispatchEvent(new Event('abort'));
    }

    progress(loaded: number, total: number): void {
        this.upload.dispatchEvent(
            Object.assign(new Event('progress'), {
                lengthComputable: true,
                loaded,
                total,
            }),
        );
    }

    respond(status: number, body: unknown): void {
        this.status = status;
        this.responseText = JSON.stringify(body);
        this.dispatchEvent(new Event('load'));
    }

    fail(): void {
        this.dispatchEvent(new Event('error'));
    }
}

const sent = {
    id: 90,
    conversation_id: 5,
    user_id: 1,
    type: 'file',
    body: null,
    parent_id: null,
    created_at: '2026-09-27T10:00:00Z',
    attachments: [],
    audio: null,
    transcription: null,
};

beforeEach(() => {
    FakeXhr.last = null;
    toast.error.mockReset();
    vi.stubGlobal('XMLHttpRequest', FakeXhr);
    document.cookie = 'XSRF-TOKEN=token%3D1';
});

afterEach(() => {
    vi.unstubAllGlobals();
});

/** Un archivo del cuerpo de la petición (falla si no está). */
function fileField(data: FormData | null, name: string): File {
    const value = data === null ? null : data.getAll(name)[0];

    if (!(value instanceof File)) {
        throw new Error(`Sin el archivo ${name}`);
    }

    return value;
}

function xhr(): FakeXhr {
    if (!FakeXhr.last) {
        throw new Error('No se ha enviado nada');
    }

    return FakeXhr.last;
}

describe('sendWithMedia', () => {
    it('envía texto, archivos, audio y hilo con el token CSRF y avisa del progreso', async () => {
        const onProgress = vi.fn();
        const pdf = new File(['%PDF'], 'presupuesto.pdf', {
            type: 'application/pdf',
        });
        const audio = new Blob(['voz'], { type: 'audio/webm;codecs=opus' });

        const promise = sendWithMedia(
            5,
            {
                body: '  Te lo paso  ',
                files: [pdf],
                audio: { file: audio, durationMs: 1234.6 },
                parentId: 12,
            },
            { onProgress },
        );
        const request = xhr();

        expect(request.method).toBe('POST');
        expect(request.url).toBe('/chat/5/multimedia');
        expect(request.headers).toMatchObject({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': 'token=1',
        });
        expect(request.body?.get('body')).toBe('Te lo paso');
        expect(request.body?.get('parent_id')).toBe('12');
        expect(fileField(request.body, 'files[]').name).toBe('presupuesto.pdf');
        expect(fileField(request.body, 'audio').name).toMatch(
            /^audio-\d{8}-\d{6}\.webm$/,
        );
        expect(request.body?.get('duration_ms')).toBe('1235');

        request.progress(50, 200);
        expect(onProgress).toHaveBeenLastCalledWith(0.25);

        request.respond(201, { message: sent });

        await expect(promise).resolves.toEqual(sent);
        expect(onProgress).toHaveBeenLastCalledWith(1);
    });

    it('sin texto ni hilo no los envía', () => {
        const data = mediaFormData({ body: '   ', files: [] });

        expect(data.has('body')).toBe(false);
        expect(data.has('parent_id')).toBe(false);
        expect(data.has('audio')).toBe(false);
    });

    it.each([
        [
            422,
            { errors: { 'files.0': ['«x.exe» no vale.'], body: ['Otro'] } },
            'validation',
            '«x.exe» no vale.',
        ],
        [403, {}, 'forbidden', 'No puedes escribir en esta conversación.'],
        [
            419,
            {},
            'session',
            'Tu sesión ha caducado. Recarga la página y vuelve a intentarlo.',
        ],
        [
            413,
            {},
            'too_large',
            'Lo que intentas enviar pesa más de lo que admite el servidor.',
        ],
        [
            429,
            {},
            'throttled',
            'Has enviado muchos archivos seguidos. Espera un minuto y vuelve a intentarlo.',
        ],
        [500, {}, 'server', 'No se ha podido enviar. Vuelve a intentarlo.'],
    ])(
        'una respuesta %i se rechaza con un error comprensible',
        async (status, body, kind, message) => {
            const promise = sendWithMedia(5, { body: 'Hola' });
            xhr().respond(status, body);

            const error = await promise.catch((caught: unknown) => caught);
            expect(error).toBeInstanceOf(MediaUploadError);
            expect((error as MediaUploadError).kind).toBe(kind);
            expect((error as MediaUploadError).message).toBe(message);
        },
    );

    it('sin conexión y al cancelar', async () => {
        const offline = sendWithMedia(5, { body: 'Hola' });
        xhr().fail();
        await expect(offline).rejects.toMatchObject({ kind: 'network' });

        const controller = new AbortController();
        const cancelled = sendWithMedia(
            5,
            { body: 'Hola' },
            { signal: controller.signal },
        );
        controller.abort();
        await expect(cancelled).rejects.toMatchObject({ kind: 'aborted' });
    });
});

function Harness({ onReady }: { onReady: (composer: MediaComposer) => void }) {
    const composer = useChatMediaComposer(5);
    onReady(composer);

    return <MediaComposerTray composer={composer} />;
}

describe('useChatMediaComposer y MediaComposerTray', () => {
    it('añade los archivos que valen, explica los que no y deja quitarlos', () => {
        let composer!: MediaComposer;
        render(<Harness onReady={(value) => (composer = value)} />);

        act(() => {
            composer.addFiles([
                new File(['a'], 'acta.pdf', { type: 'application/pdf' }),
                new File(['b'], 'virus.exe', {
                    type: 'application/octet-stream',
                }),
                new File([new Uint8Array(2 * 1024 * 1024)], 'enorme.pdf', {
                    type: 'application/pdf',
                }),
            ]);
        });

        expect(toast.error).toHaveBeenCalledTimes(2);
        expect(toast.error.mock.calls[0][0]).toContain(
            '«virus.exe» no se puede adjuntar',
        );
        expect(toast.error.mock.calls[1][0]).toBe(
            '«enorme.pdf» pesa más de 1 MB.',
        );
        expect(
            screen.getByRole('list', { name: 'Archivos por enviar: 1' }),
        ).toBeTruthy();

        fireEvent.click(
            screen.getByRole('button', { name: 'Quitar «acta.pdf»' }),
        );
        expect(composer.files).toHaveLength(0);
        expect(screen.queryByRole('list')).toBeNull();
    });

    it('no pasa de 10 archivos por mensaje', () => {
        let composer!: MediaComposer;
        render(<Harness onReady={(value) => (composer = value)} />);

        act(() => {
            composer.addFiles(
                Array.from(
                    { length: 12 },
                    (_, index) =>
                        new File(['x'], `n${index}.txt`, {
                            type: 'text/plain',
                        }),
                ),
            );
        });

        expect(composer.files).toHaveLength(10);
        expect(toast.error).toHaveBeenCalledWith(
            'Puedes adjuntar hasta 10 archivos por mensaje.',
        );
    });

    it('envía con progreso y se puede cancelar', async () => {
        let composer!: MediaComposer;
        render(<Harness onReady={(value) => (composer = value)} />);
        act(() => {
            composer.addFiles([
                new File(['a'], 'plano.pdf', { type: 'application/pdf' }),
            ]);
        });

        let result: Promise<unknown> = Promise.resolve();
        act(() => {
            result = composer.send({ body: 'Plano nuevo' });
        });
        act(() => {
            xhr().progress(30, 100);
        });

        expect(screen.getByText('Subiendo archivos… 30 %')).toBeTruthy();
        expect(
            screen.getByRole('progressbar', { name: 'Progreso de la subida' }),
        ).toBeTruthy();

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
            await result;
        });

        expect(composer.sending).toBe(false);
        expect(composer.error).toBeNull();
        // Cancelar no pierde los archivos: se pueden volver a enviar.
        expect(composer.files).toHaveLength(1);
    });

    it('al enviar bien se vacía la bandeja', async () => {
        let composer!: MediaComposer;
        const onReady = (value: MediaComposer) => (composer = value);
        render(<Harness onReady={onReady} />);
        act(() => {
            composer.addFiles([
                new File(['a'], 'plano.pdf', { type: 'application/pdf' }),
            ]);
        });

        let result: Promise<unknown> = Promise.resolve();
        act(() => {
            result = composer.send();
        });
        await act(async () => {
            xhr().respond(201, { message: sent });
            await result;
        });

        await expect(result).resolves.toEqual(sent);
        expect(composer.files).toHaveLength(0);
        expect(
            document.querySelector('[data-test="chat-media-tray"]'),
        ).toBeNull();
    });

    it('un audio que no se pudo enviar se puede reintentar sin volver a grabarlo', async () => {
        let composer!: MediaComposer;
        render(<Harness onReady={(value) => (composer = value)} />);
        const audio = new File(['voz'], 'audio-20260927-100000.webm', {
            type: 'audio/webm',
        });

        let result: Promise<unknown> = Promise.resolve();
        act(() => {
            result = composer.sendAudio(audio, 65_000, 12);
        });
        await act(async () => {
            xhr().fail();
            await result;
        });

        expect(screen.getByRole('alert').textContent).toContain(
            'No se ha podido enviar el audio (1:05)',
        );

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }));
        });

        const retry = xhr();
        expect(fileField(retry.body, 'audio').name).toBe(
            'audio-20260927-100000.webm',
        );
        expect(retry.body?.get('duration_ms')).toBe('65000');
        expect(retry.body?.get('parent_id')).toBe('12');

        await act(async () => {
            retry.respond(201, { message: { ...sent, type: 'audio' } });
        });

        expect(composer.failedAudio).toBeNull();
        expect(screen.queryByRole('alert')).toBeNull();
    });
    it('un audio grabado mientras otra subida está en curso no se pierde: espera su turno', async () => {
        let composer!: MediaComposer;
        render(<Harness onReady={(value) => (composer = value)} />);
        act(() => {
            composer.addFiles([
                new File(['a'], 'plano.pdf', { type: 'application/pdf' }),
            ]);
        });

        let files: Promise<unknown> = Promise.resolve();
        act(() => {
            files = composer.send({ body: 'Plano' });
        });
        const first = xhr();

        const audio = new File(['voz'], 'audio-20260927-100000.webm', {
            type: 'audio/webm',
        });
        let audioResult: Promise<unknown> = Promise.resolve();
        act(() => {
            audioResult = composer.sendAudio(audio, 3_000, null);
        });

        // Todavía no se envía (una subida a la vez), pero se avisa de que espera.
        expect(xhr()).toBe(first);
        expect(
            document.querySelector('[data-test="chat-media-queued"]')
                ?.textContent,
        ).toContain('Audios esperando a que termine esta subida: 1');

        await act(async () => {
            first.respond(201, { message: sent });
            await files;
        });

        // Al terminar la primera, sale el audio.
        const second = xhr();
        expect(second).not.toBe(first);
        expect(fileField(second.body, 'audio').name).toBe(
            'audio-20260927-100000.webm',
        );
        expect(second.body?.get('duration_ms')).toBe('3000');

        await act(async () => {
            second.respond(201, { message: { ...sent, type: 'audio' } });
            await audioResult;
        });

        await expect(audioResult).resolves.toMatchObject({ type: 'audio' });
        expect(composer.queued).toBe(0);
    });
});
