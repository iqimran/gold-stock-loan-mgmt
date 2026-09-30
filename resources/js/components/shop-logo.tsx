import AppLogoIcon from '@/components/app-logo-icon';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * The shop's logo and name (Settings → Loan settings) for the sign-in and other guest pages; the
 * built-in icon until a logo is uploaded.
 */
export function ShopLogo() {
    const { branding } = usePage<SharedData>().props;

    return (
        <div className="flex flex-col items-center gap-2">
            {branding.logo_url ? (
                <img src={branding.logo_url} alt="" className="h-14 max-w-48 object-contain" />
            ) : (
                <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
            )}
            <span className="text-center text-base font-semibold">{branding.name}</span>
        </div>
    );
}
