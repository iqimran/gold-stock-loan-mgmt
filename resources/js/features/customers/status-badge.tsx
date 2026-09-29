import { Badge } from '@/components/ui/badge';
import { type CustomerStatus } from '@/features/customers/types';

export function CustomerStatusBadge({ status }: { status: CustomerStatus }) {
    return status === 'archived' ? <Badge variant="outline">Archived</Badge> : <Badge variant="secondary">Active</Badge>;
}

/**
 * Consecutive missed interest periods; highlighted once any period is missed.
 */
export function MissedBadge({ count }: { count: number }) {
    return count > 0 ? <Badge variant="destructive">{count}</Badge> : <span className="text-muted-foreground">0</span>;
}
