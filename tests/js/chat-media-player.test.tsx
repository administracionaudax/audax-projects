// @vitest-environment jsdom
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { MockInstance } from 'vitest';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AudioPlayer } from '@/components/chat/media/audio-player';

let play: MockInstance<(this: HTMLMediaElement) => Promise<void>>;
let pause: MockInstance<(this: HTMLMediaElement) => void>;
let load: MockInstance<(this: HTMLMediaElement) => void>;

/**
 * jsdom no reproduce audio: play() y pause() disparan los eventos como un navegador.
 */
beforeEach(() => {
    play = vi
        .spyOn(HTMLMediaElement.prototype, 'play')
        .mockImplementation(function (this: HTMLMediaElement) {
            this.dispatchEvent(new Event('play'));

            return Promise.resolve();
        });
    pause = vi
        .spyOn(HTMLMediaElement.prototype, 'pause')
        .mockImplementation(function (this: HTMLMediaElement) {
            this.dispatchEvent(new Event('pause'));
        });
    load = vi
        .spyOn(HTMLMediaElement.prototype, 'load')
        .mockImplementation(() => {});
});

afterEach(() => {
    vi.restoreAllMocks();
});

function audioElement(container: HTMLElement): HTMLAudioElement {
    const element = container.querySelector('audio');

    if (!element) {
        throw new Error('Sin <audio>');
    }

    return element;
}

describe('AudioPlayer', () => {
    it('muestra el botón, la barra de progreso accesible, el tiempo y la velocidad', () => {
        render(
            <AudioPlayer
                src="/chat/audios/1?signature=x"
                durationMs={65_000}
            />,
        );

        expect(screen.getByRole('group', { name: 'Audio' })).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Reproducir el audio' }),
        ).toBeTruthy();

        const slider = screen.getByRole('slider', {
            name: 'Posición del audio',
        });
        expect(slider.getAttribute('aria-valuemin')).toBe('0');
        expect(slider.getAttribute('aria-valuemax')).toBe('65');
        expect(slider.getAttribute('aria-valuenow')).toBe('0');
        expect(slider.getAttribute('aria-valuetext')).toBe('0:00 de 1:05');
        expect(slider.getAttribute('tabindex')).toBe('0');
        expect(screen.getByText('0:00 / 1:05')).toBeTruthy();
        expect(
            screen.getByRole('button', {
                name: 'Velocidad de reproducción: 1×. Pulsa para cambiarla',
            }),
        ).toBeTruthy();
    });

    it('reproduce y pausa', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <AudioPlayer src="/a.webm" durationMs={10_000} />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Reproducir el audio' }),
        );

        expect(play).toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Pausar el audio' }),
        ).toBeTruthy();

        await user.click(
            screen.getByRole('button', { name: 'Pausar el audio' }),
        );

        expect(pause).toHaveBeenCalled();
        expect(audioElement(container).paused).toBeDefined();
        expect(
            screen.getByRole('button', { name: 'Reproducir el audio' }),
        ).toBeTruthy();
    });

    it('la barra de progreso se maneja con el teclado', () => {
        const { container } = render(
            <AudioPlayer src="/a.webm" durationMs={120_000} />,
        );
        const slider = screen.getByRole('slider');
        const audio = audioElement(container);

        fireEvent.keyDown(slider, { key: 'ArrowRight' });
        expect(audio.currentTime).toBe(5);
        expect(slider.getAttribute('aria-valuenow')).toBe('5');
        expect(slider.getAttribute('aria-valuetext')).toBe('0:05 de 2:00');

        fireEvent.keyDown(slider, { key: 'PageUp' });
        expect(audio.currentTime).toBe(17);

        fireEvent.keyDown(slider, { key: 'ArrowLeft' });
        expect(audio.currentTime).toBe(12);

        fireEvent.keyDown(slider, { key: 'End' });
        expect(audio.currentTime).toBe(120);

        fireEvent.keyDown(slider, { key: 'ArrowRight' });
        expect(audio.currentTime).toBe(120);

        fireEvent.keyDown(slider, { key: 'Home' });
        expect(audio.currentTime).toBe(0);

        fireEvent.keyDown(slider, { key: 'ArrowDown' });
        expect(audio.currentTime).toBe(0);
    });

    it('cambia la velocidad 1× → 1,5× → 2× → 1×', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <AudioPlayer src="/a.webm" durationMs={10_000} />,
        );
        const audio = audioElement(container);

        await user.click(screen.getByRole('button', { name: /Velocidad/ }));
        expect(audio.playbackRate).toBe(1.5);
        expect(
            screen.getByRole('button', {
                name: 'Velocidad de reproducción: 1,5×. Pulsa para cambiarla',
            }).textContent,
        ).toBe('1,5×');

        await user.click(screen.getByRole('button', { name: /Velocidad/ }));
        expect(audio.playbackRate).toBe(2);

        await user.click(screen.getByRole('button', { name: /Velocidad/ }));
        expect(audio.playbackRate).toBe(1);
        expect(
            screen.getByRole('button', { name: /Velocidad/ }).textContent,
        ).toBe('1×');
    });

    it('sin duración conocida usa la del propio audio y avanza con el tiempo', () => {
        const { container } = render(<AudioPlayer src="/a.webm" />);
        const audio = audioElement(container);

        Object.defineProperty(audio, 'duration', {
            configurable: true,
            value: 42,
        });
        act(() => {
            audio.dispatchEvent(new Event('loadedmetadata'));
        });
        expect(screen.getByRole('slider').getAttribute('aria-valuemax')).toBe(
            '42',
        );

        act(() => {
            audio.currentTime = 12;
            audio.dispatchEvent(new Event('timeupdate'));
        });
        expect(screen.getByText('0:12 / 0:42')).toBeTruthy();
    });

    it('si la URL firmada ha caducado, pide una nueva y la usa', async () => {
        const onSourceExpired = vi
            .fn()
            .mockResolvedValue('/chat/audios/1?nueva=1');
        const { container } = render(
            <AudioPlayer
                src="/chat/audios/1?vieja=1"
                durationMs={10_000}
                onSourceExpired={onSourceExpired}
            />,
        );

        await act(async () => {
            audioElement(container).dispatchEvent(new Event('error'));
        });

        expect(onSourceExpired).toHaveBeenCalledTimes(1);
        expect(audioElement(container).getAttribute('src')).toBe(
            '/chat/audios/1?nueva=1',
        );
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('si no se puede cargar, lo dice y deja reintentar', async () => {
        const { container } = render(
            <AudioPlayer
                src="/a.webm"
                durationMs={10_000}
                onSourceExpired={() => Promise.resolve(null)}
            />,
        );

        await act(async () => {
            audioElement(container).dispatchEvent(new Event('error'));
        });

        expect(screen.getByRole('alert').textContent).toBe(
            'No se ha podido cargar el audio.',
        );
        expect(screen.getByRole('slider').getAttribute('aria-disabled')).toBe(
            'true',
        );

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Volver a cargar el audio',
                }),
            );
        });

        expect(load).toHaveBeenCalled();
        expect(play).toHaveBeenCalled();
    });

    it('al empezar un audio se paran los demás', async () => {
        const user = userEvent.setup();
        render(
            <>
                <AudioPlayer src="/uno.webm" durationMs={10_000} label="Uno" />
                <AudioPlayer src="/dos.webm" durationMs={10_000} label="Dos" />
            </>,
        );
        const [first, second] = screen.getAllByRole('button', {
            name: 'Reproducir el audio',
        });

        await user.click(first);
        pause.mockClear();
        await user.click(second);

        expect(pause).toHaveBeenCalledTimes(1);
        expect(
            screen.getAllByRole('button', { name: 'Reproducir el audio' }),
        ).toHaveLength(1);
    });
});

describe('AudioPlayer con una URL firmada nueva (H6)', () => {
    it('sin empezar a escuchar, adopta la URL nueva', () => {
        const { container, rerender } = render(
            <AudioPlayer src="/a.webm?signature=1" durationMs={10_000} />,
        );

        rerender(<AudioPlayer src="/a.webm?signature=2" durationMs={10_000} />);

        expect(audioElement(container).getAttribute('src')).toBe(
            '/a.webm?signature=2',
        );
    });

    it('mientras suena no cambia el src (no corta la reproducción) y usa la nueva si la actual falla', async () => {
        const user = userEvent.setup();
        const { container, rerender } = render(
            <AudioPlayer src="/a.webm?signature=1" durationMs={10_000} />,
        );
        const element = audioElement(container);

        await user.click(
            screen.getByRole('button', { name: 'Reproducir el audio' }),
        );
        expect(play).toHaveBeenCalledTimes(1);

        rerender(<AudioPlayer src="/a.webm?signature=2" durationMs={10_000} />);
        expect(element.getAttribute('src')).toBe('/a.webm?signature=1');
        expect(pause).not.toHaveBeenCalled();

        // Pausado a mitad: tampoco (se perdería la posición).
        await user.click(
            screen.getByRole('button', { name: 'Pausar el audio' }),
        );
        act(() => {
            element.currentTime = 4;
            fireEvent.timeUpdate(element);
        });
        rerender(<AudioPlayer src="/a.webm?signature=3" durationMs={10_000} />);
        expect(element.getAttribute('src')).toBe('/a.webm?signature=1');

        // Si la actual falla (caducada), se usa la más reciente sin pedir otra.
        const onSourceExpired = vi.fn(async () => '/a.webm?signature=9');
        rerender(
            <AudioPlayer
                src="/a.webm?signature=3"
                durationMs={10_000}
                onSourceExpired={onSourceExpired}
            />,
        );
        await act(async () => {
            fireEvent.error(element);
        });

        expect(element.getAttribute('src')).toBe('/a.webm?signature=3');
        expect(onSourceExpired).not.toHaveBeenCalled();
    });

    it('con un error, adopta la URL nueva que llegue', async () => {
        const { container, rerender } = render(
            <AudioPlayer src="/a.webm?signature=1" durationMs={10_000} />,
        );
        const element = audioElement(container);

        await act(async () => {
            fireEvent.error(element);
        });
        rerender(<AudioPlayer src="/a.webm?signature=2" durationMs={10_000} />);

        expect(element.getAttribute('src')).toBe('/a.webm?signature=2');
    });
});
