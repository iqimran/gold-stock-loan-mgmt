import { type Currency, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * The shop's currency from Settings. Display only — amounts are never converted.
 */
export function useCurrency(): Currency {
    return usePage<SharedData>().props.currency;
}
