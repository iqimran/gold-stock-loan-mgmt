import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    const { branding } = usePage<SharedData>().props;

    return (
        <>
            {branding.logo_url ? (
                <img src={branding.logo_url} alt="" className="size-8 shrink-0 rounded-md object-contain" />
            ) : (
                <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                    <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
                </div>
            )}
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">{branding.name}</span>
            </div>
        </>
    );
}
