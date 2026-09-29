import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, X } from 'lucide-react';
import { useEffect, useState } from 'react';

type Toast = { type: 'success' | 'error'; message: string };

const AUTO_DISMISS_MS = 5000;

/**
 * Displays the flash message from the latest response as a dismissible toast.
 */
export function FlashMessages() {
    const { flash } = usePage<SharedData>().props;
    const [toast, setToast] = useState<Toast | null>(null);

    useEffect(() => {
        const next: Toast | null = flash?.error
            ? { type: 'error', message: flash.error }
            : flash?.success || flash?.status
              ? { type: 'success', message: (flash.success ?? flash.status) as string }
              : null;

        setToast(next);

        if (!next) {
            return;
        }

        const timer = window.setTimeout(() => setToast(null), AUTO_DISMISS_MS);

        return () => window.clearTimeout(timer);
    }, [flash]);

    if (!toast) {
        return null;
    }

    const Icon = toast.type === 'error' ? CircleAlert : CircleCheck;

    return (
        <div className="pointer-events-none fixed inset-x-0 top-4 z-50 flex justify-center px-4 sm:justify-end">
            <div
                role={toast.type === 'error' ? 'alert' : 'status'}
                aria-live={toast.type === 'error' ? 'assertive' : 'polite'}
                className={cn(
                    'bg-background pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border p-4 text-sm shadow-lg',
                    toast.type === 'error' ? 'border-destructive/50 text-destructive' : 'border-green-600/40 text-green-700 dark:text-green-400',
                )}
            >
                <Icon className="mt-0.5 size-4 shrink-0" />
                <p className="flex-1">{toast.message}</p>
                <button type="button" onClick={() => setToast(null)} className="opacity-70 hover:opacity-100" aria-label="Dismiss">
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}
