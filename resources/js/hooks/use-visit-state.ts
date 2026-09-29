import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const FAILED = 'The server could not be reached or returned an unexpected response.';

/**
 * Loading / error state of Inertia visits that stay on the given path (filters, pagination, retry).
 * Visits to other pages are ignored, so navigating away does not show a loading or error state here.
 */
export function useVisitState(pathname: string) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inFlight = useRef(false);

    useEffect(() => {
        const fail = (event: Event) => {
            if (!inFlight.current) {
                return;
            }

            // Show the error in the page instead of Inertia's default error modal.
            event.preventDefault();
            setError(FAILED);
        };

        const listeners = [
            router.on('start', (event) => {
                inFlight.current = event.detail.visit.url.pathname === pathname;

                if (inFlight.current) {
                    setLoading(true);
                    setError(null);
                }
            }),
            router.on('finish', () => {
                inFlight.current = false;
                setLoading(false);
            }),
            // A non-Inertia response (e.g. a server error page in development) or a network failure.
            router.on('invalid', fail),
            router.on('exception', fail),
        ];

        return () => listeners.forEach((off) => off());
    }, [pathname]);

    return { loading, error, retry: () => router.reload() };
}
