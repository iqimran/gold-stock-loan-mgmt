import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { FileSpreadsheet, FileText } from 'lucide-react';

interface ExportButtonsProps {
    /** Export route name, e.g. "loans.export" or "reports.export". */
    routeName: string;
    /** Route parameters (e.g. { report: 'collections' }). */
    params?: Record<string, string>;
    /** The filters currently applied on the page (the page's filters prop); the export contains exactly those rows. */
    filters: object;
}

/**
 * Excel / PDF download of the current list with its applied filters. Hidden without reports.export;
 * the server re-checks that permission and the list's own view permission.
 */
export function ExportButtons({ routeName, params = {}, filters }: ExportButtonsProps) {
    const can = useCan();

    if (!can('reports.export')) {
        return null;
    }

    const query = Object.fromEntries(
        Object.entries(filters as Record<string, unknown>)
            .filter(([, value]) => value !== '' && value !== null && value !== undefined && value !== false)
            .map(([key, value]) => [key, value === true ? '1' : String(value)]),
    );

    const href = (format: 'xlsx' | 'pdf') => route(routeName, { ...params, ...query, format });

    return (
        <div className="flex gap-2">
            <Button variant="outline" size="sm" asChild>
                <a href={href('xlsx')} download>
                    <FileSpreadsheet className="size-4" /> Excel
                </a>
            </Button>
            <Button variant="outline" size="sm" asChild>
                <a href={href('pdf')} download>
                    <FileText className="size-4" /> PDF
                </a>
            </Button>
        </div>
    );
}
