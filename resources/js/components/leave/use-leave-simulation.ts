import { useEffect, useState } from 'react';
import { xsrfToken } from '@/lib/xsrf';
import { simulate } from '@/routes/absences';
import type { LeaveSimulation } from '@/types/leave';

export type SimulationInput = {
    leave_type_id: string;
    start_date: string | null;
    end_date: string | null;
    partial_minutes: number | null;
    start_time: string;
    end_time: string;
};

/**
 * Lo que costaría la solicitud que se está rellenando (Fase 11, R3; D-364): pregunta a
 * POST /ausencias/simular, 300 ms después del último cambio, cuánto cuesta, el saldo que quedaría,
 * los avisos y los errores. Sin datos suficientes (tipo y fecha), null.
 */
export function useLeaveSimulation(
    input: SimulationInput | null,
): LeaveSimulation | null {
    const [result, setResult] = useState<LeaveSimulation | null>(null);
    const key =
        input === null || input.leave_type_id === '' || !input.start_date
            ? null
            : JSON.stringify(input);

    useEffect(() => {
        if (key === null) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const token = xsrfToken();
            const body = JSON.parse(key) as SimulationInput;

            fetch(simulate.url(), {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-XSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify({
                    leave_type_id: Number(body.leave_type_id),
                    start_date: body.start_date,
                    end_date: body.end_date ?? body.start_date,
                    partial_minutes: body.partial_minutes,
                    start_time: body.start_time || null,
                    end_time: body.end_time || null,
                }),
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((data: LeaveSimulation | null) => setResult(data))
                .catch(() => {
                    // Abortada o sin conexión: se queda la última respuesta.
                });
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [key]);

    return key === null ? null : result;
}
