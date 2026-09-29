import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { ReactNode, useEffect, useRef, useState } from 'react';

interface PrintPageProps {
    title: string;
    /**
     * Paper: a fixed CSS @page size ("A4", "38mm 25mm"), or `{ roll: '80mm' }` for continuous
     * receipt paper, where the page height is measured from the document so it prints as one slip.
     */
    paper: string | { roll: string };
    /** CSS @page margin (default 0: thermal printers and label stock handle their own margins). */
    pageMargin?: string;
    /** Open the print dialog once, when the page loads. */
    autoPrint?: boolean;
    /** Extra toolbar buttons (hidden when printing). */
    actions?: ReactNode;
    /** Toolbar hint below the buttons. */
    hint?: ReactNode;
    /** Screen width of the toolbar. */
    className?: string;
    children: ReactNode;
}

const PX_PER_MM = 96 / 25.4;
/** Extra paper after the slip so the last line clears the printer's tear bar. */
const ROLL_TAIL_MM = 6;

/**
 * Chrome shared by every printable document: screen toolbar, paper size and optional auto-print.
 * Print templates only lay out data; they never compute business values.
 */
export function PrintPage({ title, paper, pageMargin = '0', autoPrint = false, actions, hint, className, children }: PrintPageProps) {
    const printed = useRef(false);
    const content = useRef<HTMLDivElement>(null);
    const roll = typeof paper === 'object' ? paper.roll : null;
    const [rollHeightMm, setRollHeightMm] = useState<number | null>(null);

    // CSS has no "auto" page height, so measure the slip and size the page to it.
    useEffect(() => {
        if (!roll || !content.current) return;

        const element = content.current;
        const measure = () => setRollHeightMm(Math.ceil(element.getBoundingClientRect().height / PX_PER_MM) + ROLL_TAIL_MM);
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        measure();

        return () => observer.disconnect();
    }, [roll]);

    useEffect(() => {
        if (!autoPrint || printed.current || (roll && rollHeightMm === null)) return;
        printed.current = true;
        // Let images (barcodes) decode before the print dialog snapshots the page.
        const timer = window.setTimeout(() => window.print(), 300);

        return () => window.clearTimeout(timer);
    }, [autoPrint, roll, rollHeightMm]);

    const pageSize = roll ? `${roll} ${rollHeightMm ?? 200}mm` : (paper as string);

    return (
        <div className="bg-muted/40 min-h-svh py-6 print:bg-white print:py-0">
            <Head title={title} />
            <style>{`@page { size: ${pageSize}; margin: ${pageMargin}; } @media print { html, body { background: #fff; } }`}</style>

            <div className={cn('mx-auto mb-4 flex w-full flex-wrap justify-center gap-2 px-2 print:hidden', className)}>
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" /> Print
                </Button>
                {actions}
                {hint && <div className="text-muted-foreground w-full text-center text-xs">{hint}</div>}
            </div>

            <div ref={content}>{children}</div>
        </div>
    );
}
