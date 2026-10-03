import { useEffect, useState } from 'react';
import { people as peopleRoute } from '@/routes/reports/deliveries';
import type { DeliveryPerson } from '@/types/report-deliveries';

/**
 * Personas que pueden recibir informes (GET /informes/envios/personas: activas y de la plantilla).
 * Se piden al abrir el diálogo, una vez por diálogo.
 */
export function useDeliveryPeople(open: boolean): {
    people: DeliveryPerson[] | null;
    failed: boolean;
} {
    const [people, setPeople] = useState<DeliveryPerson[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open || people !== null) {
            return;
        }

        let cancelled = false;

        fetch(peopleRoute.url(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<{
                    people: DeliveryPerson[];
                }>;
            })
            .then((data) => {
                if (!cancelled) {
                    setPeople(data.people);
                    setFailed(false);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFailed(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open, people]);

    return { people, failed };
}
