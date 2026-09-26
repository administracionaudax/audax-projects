// @vitest-environment jsdom
import { act, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TimerButton } from '@/components/time/timer-button';
import { timerStopDialog } from '@/components/time/timer-actions';
import { spokenDuration, TimerChip } from '@/components/time/timer-chip';
import {
    measuredMinutes,
    TimerStopDialog,
} from '@/components/time/timer-stop-dialog';
import { elapsedSeconds, formatElapsed } from '@/components/time/use-elapsed';
import type { ActiveTimer } from '@/types';

type VisitOptions = {
    onStart?: () => void;
    onFinish?: () => void;
    onError?: (errors: Record<string, string>) => void;
    errorBag?: string;
};

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));
const post = vi.hoisted(() => vi.fn());
const toastError = vi.hoisted(() => vi.fn());

vi.mock('sonner', async (importOriginal) => ({
    ...(await importOriginal<typeof import('sonner')>()),
    toast: Object.assign(vi.fn(), { error: toastError }),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => page,
    router: {
        post: (...args: unknown[]) => post(...args),
        delete: vi.fn(),
        on: () => () => {},
    },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const NOW = new Date('2026-09-25T10:00:00Z');

function timerStartedSecondsAgo(seconds: number): ActiveTimer {
    return {
        task_id: 12,
        task_title: 'Maquetar la home',
        project_id: 3,
        project_code: 'ACME-WEB',
        project_name: 'Web de ACME',
        started_at: new Date(NOW.getTime() - seconds * 1000).toISOString(),
        description: null,
    };
}

beforeEach(() => {
    post.mockReset();
    toastError.mockReset();
    page.props = { timer: null };
});

afterEach(() => {
    act(() => {
        timerStopDialog.close();
    });
    vi.useRealTimers();
});

describe('tiempo transcurrido', () => {
    it('formatea h:mm:ss', () => {
        expect(formatElapsed(0)).toBe('0:00:00');
        expect(formatElapsed(59)).toBe('0:00:59');
        expect(formatElapsed(3725)).toBe('1:02:05');
        expect(formatElapsed(36000)).toBe('10:00:00');
        expect(formatElapsed(-5)).toBe('0:00:00');
    });

    it('calcula los segundos desde un instante ISO', () => {
        expect(elapsedSeconds('2026-09-25T09:00:00Z', NOW.getTime())).toBe(
            3600,
        );
        expect(elapsedSeconds(null, NOW.getTime())).toBe(0);
        expect(elapsedSeconds('no es una fecha', NOW.getTime())).toBe(0);
    });

    it('dice la duración en voz para lectores de pantalla', () => {
        expect(spokenDuration(5)).toBe('5 min');
        expect(spokenDuration(62)).toBe('1 h 2 min');
    });
});

describe('chip del temporizador de la cabecera', () => {
    it('muestra la tarea y el tiempo en vivo, y anuncia solo el cambio de minuto', () => {
        vi.useFakeTimers({ now: NOW });
        render(
            <TimerChip
                timer={timerStartedSecondsAgo(3718)}
                warningHours={10}
            />,
        );

        const elapsed = document.querySelector('[data-test="timer-elapsed"]');
        const live = document.querySelector('[aria-live="polite"]');
        expect(elapsed?.textContent).toBe('1:01:58');
        expect(live?.textContent).toBe(
            'Temporizador: 1 h 1 min en «Maquetar la home».',
        );
        expect(
            screen
                .getByRole('link', { name: /Maquetar la home/u })
                .getAttribute('href'),
        ).toBe('/proyectos/3/tareas?tarea=12');

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(elapsed?.textContent).toBe('1:01:59');
        expect(live?.textContent).toBe(
            'Temporizador: 1 h 1 min en «Maquetar la home».',
        );

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(elapsed?.textContent).toBe('1:02:00');
        expect(live?.textContent).toBe(
            'Temporizador: 1 h 2 min en «Maquetar la home».',
        );
    });

    it('avisa cuando supera las horas configuradas', () => {
        vi.useFakeTimers({ now: NOW });
        const { rerender } = render(
            <TimerChip
                timer={timerStartedSecondsAgo(2 * 3600 - 1)}
                warningHours={2}
            />,
        );
        const chip = document.querySelector('[data-test="timer-chip"]');
        expect(chip?.hasAttribute('data-warning')).toBe(false);

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        rerender(
            <TimerChip
                timer={timerStartedSecondsAgo(2 * 3600 - 1)}
                warningHours={2}
            />,
        );

        expect(chip?.getAttribute('data-warning')).toBe('true');
        expect(
            document.querySelector('[aria-live="polite"]')?.textContent,
        ).toContain('Lleva más de 2 horas en marcha');
    });

    it('parar envía la petición al servidor en la bolsa de errores del temporizador', () => {
        render(
            <TimerChip timer={timerStartedSecondsAgo(60)} warningHours={10} />,
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Parar el temporizador de «Maquetar la home»',
            }),
        );

        expect(post).toHaveBeenCalledTimes(1);
        const [url, data, visit] = post.mock.calls[0] as [
            string,
            unknown,
            VisitOptions,
        ];
        expect(url).toBe('/temporizador/parar');
        expect(data).toEqual({ minutes: null, task_id: null });
        expect(visit.errorBag).toBe('timer');
    });
});

describe('botón de temporizador de una tarea', () => {
    it('inicia el temporizador de la tarea y muestra la carga mientras responde', () => {
        render(<TimerButton task={{ id: 12, title: 'Maquetar la home' }} />);

        const button = screen.getByRole('button', {
            name: 'Iniciar el temporizador en «Maquetar la home»',
        });
        expect(button.getAttribute('aria-pressed')).toBe('false');

        fireEvent.click(button);

        expect(post).toHaveBeenCalledTimes(1);
        const [url, data, visit] = post.mock.calls[0] as [
            string,
            unknown,
            VisitOptions,
        ];
        expect(url).toBe('/temporizador');
        expect(data).toEqual({ task_id: 12 });

        act(() => visit.onStart?.());
        expect(button.getAttribute('aria-busy')).toBe('true');

        // Un segundo clic mientras responde no envía nada.
        fireEvent.click(button);
        expect(post).toHaveBeenCalledTimes(1);

        act(() => {
            visit.onError?.({
                task_id: 'Solo los miembros del proyecto pueden imputar horas.',
            });
            visit.onFinish?.();
        });
        expect(toastError).toHaveBeenCalledWith(
            'Solo los miembros del proyecto pueden imputar horas.',
        );
        expect(button.hasAttribute('aria-busy')).toBe(false);
    });

    it('si está en marcha en esta tarea, lo para', () => {
        page.props = { timer: timerStartedSecondsAgo(120) };
        render(
            <TimerButton
                task={{ id: 12, title: 'Maquetar la home' }}
                size="sm"
            />,
        );

        const button = screen.getByRole('button', {
            name: 'Parar el temporizador de «Maquetar la home»',
        });
        expect(button.getAttribute('aria-pressed')).toBe('true');
        expect(button.textContent).toContain('Parar');

        fireEvent.click(button);
        expect(post.mock.calls[0][0]).toBe('/temporizador/parar');
    });

    it('está desactivado en los hitos', () => {
        render(
            <TimerButton
                task={{ id: 20, title: 'Entrega', is_milestone: true }}
            />,
        );

        const button = screen.getByRole('button', {
            name: 'Los hitos no llevan horas',
        });
        expect((button as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(button);
        expect(post).not.toHaveBeenCalled();
    });
});

describe('diálogo al parar cuando la imputación no es válida', () => {
    const config = {
        hour_bank_thresholds: [75, 90, 100],
        timer_warning_hours: 10,
        timer_rounding_minutes: 15,
    };

    function openDialog(secondsAgo: number) {
        vi.useFakeTimers({ now: NOW, toFake: ['Date'] });
        page.props = { timer: timerStartedSecondsAgo(secondsAgo), config };
        render(<TimerStopDialog />);
        act(() => {
            timerStopDialog.open({
                timer: 'La bolsa de horas no tiene saldo.',
            });
        });
    }

    function sentData(): unknown {
        expect(post).toHaveBeenCalledTimes(1);
        const [url, data] = post.mock.calls[0] as [string, unknown];
        expect(url).toBe('/temporizador/parar');

        return data;
    }

    it('redondea lo medido como el servidor', () => {
        const now = NOW.getTime();
        const ago = (seconds: number) =>
            new Date(now - seconds * 1000).toISOString();

        expect(measuredMinutes(ago(3718), 15, now)).toBe(60);
        expect(measuredMinutes(ago(3718), 1, now)).toBe(62);
        expect(measuredMinutes(ago(5 * 60), 15, now)).toBe(0);
        expect(measuredMinutes(ago(30 * 3600), 1, now)).toBe(1800);
    });

    it('si solo cambia la tarea, no envía la duración: el servidor reparte por días y redondea', () => {
        openDialog(3718);

        expect(
            screen.getByText('La bolsa de horas no tiene saldo.'),
        ).toBeTruthy();
        const duration = screen.getByLabelText('Duración') as HTMLInputElement;
        expect(duration.value).toBe('1:00');
        expect(
            screen.getByText(/^Medido: 1:00\. Si no cambias la duración/u),
        ).toBeTruthy();

        fireEvent.click(
            screen.getByRole('button', { name: 'Imputar y parar' }),
        );

        expect(sentData()).toEqual({ minutes: null, task_id: 12 });
    });

    it('con la duración cambiada, la envía para imputarla en una sola entrada', () => {
        openDialog(3718);

        fireEvent.change(screen.getByLabelText('Duración'), {
            target: { value: '0:45' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Imputar y parar' }),
        );

        expect(sentData()).toEqual({ minutes: 45, task_id: 12 });
    });

    it('un temporizador de más de 24 h muestra el máximo y, sin tocarlo, se reparte por días', () => {
        openDialog(30 * 3600);

        expect(
            (screen.getByLabelText('Duración') as HTMLInputElement).value,
        ).toBe('24:00');
        expect(screen.getByText(/^Medido: 30:00\./u)).toBeTruthy();

        fireEvent.click(
            screen.getByRole('button', { name: 'Imputar y parar' }),
        );

        expect(sentData()).toEqual({ minutes: null, task_id: 12 });
    });
});
