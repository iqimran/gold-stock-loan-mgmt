import { Pagination } from '@/components/pagination';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { type Paginated } from '@/types';
import { CircleAlert } from 'lucide-react';
import { type ReactNode } from 'react';

export interface DataTableColumn<T> {
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    /** Applied to the header and cells, e.g. 'text-right' or 'hidden md:table-cell' for responsive columns. */
    className?: string;
}

interface DataTableProps<T> {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    /** Server pagination (Laravel resource collection meta). */
    meta?: Paginated<T>['meta'];
    /** Shown while a page/filter request is in flight. */
    loading?: boolean;
    /** Shown instead of the rows when the last request failed. */
    error?: string | null;
    onRetry?: () => void;
    empty?: ReactNode;
}

/**
 * Server-driven table in the application's table style: responsive horizontal scroll, loading,
 * empty and error states, and server pagination. Sorting/filtering stay with the page (query string).
 */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    meta,
    loading = false,
    error = null,
    onRetry,
    empty = 'No records found.',
}: DataTableProps<T>) {
    if (error) {
        return (
            <Alert variant="destructive">
                <CircleAlert className="size-4" />
                <AlertTitle>Could not load the list</AlertTitle>
                <AlertDescription>
                    <p>{error}</p>
                    {onRetry && (
                        <Button variant="outline" size="sm" className="mt-2" onClick={onRetry}>
                            Try again
                        </Button>
                    )}
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <div className="space-y-4">
            <div className="relative overflow-x-auto rounded-lg border" aria-busy={loading}>
                <table className={cn('w-full text-sm transition-opacity', loading && rows.length > 0 && 'opacity-50')}>
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            {columns.map((column) => (
                                <th key={column.key} scope="col" className={cn('px-4 py-3 font-medium whitespace-nowrap', column.className)}>
                                    {column.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {loading &&
                            rows.length === 0 &&
                            Array.from({ length: 5 }, (_, index) => (
                                <tr key={`skeleton-${index}`} className="border-t">
                                    {columns.map((column) => (
                                        <td key={column.key} className={cn('px-4 py-3', column.className)}>
                                            <Skeleton className="h-4 w-full" />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        {!loading && rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="text-muted-foreground px-4 py-8 text-center">
                                    {empty}
                                </td>
                            </tr>
                        )}
                        {rows.map((row) => (
                            <tr key={rowKey(row)} className="hover:bg-muted/30 border-t">
                                {columns.map((column) => (
                                    <td key={column.key} className={cn('px-4 py-3', column.className)}>
                                        {column.cell(row)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {meta && <Pagination meta={meta} />}
        </div>
    );
}
