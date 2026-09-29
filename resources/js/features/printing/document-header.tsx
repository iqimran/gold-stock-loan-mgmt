export interface PrintShop {
    name: string;
    address: string | null;
    phone: string | null;
    receipt_footer?: string | null;
}

/**
 * Organization block centred at the top of printed documents (Settings → Organization). No logo.
 */
export function DocumentHeader({ shop, title }: { shop: PrintShop; title?: string }) {
    return (
        <header className="text-center">
            <h1 className="text-base font-bold">{shop.name}</h1>
            {shop.address && <p className="whitespace-pre-line">{shop.address}</p>}
            {shop.phone && <p>Tel: {shop.phone}</p>}
            {title && <p className="mt-1 font-bold tracking-wide">{title}</p>}
        </header>
    );
}

export const Divider = () => <div className="my-2 border-t border-dashed border-black" />;
