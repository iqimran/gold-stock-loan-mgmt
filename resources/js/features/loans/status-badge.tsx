import { Badge } from '@/components/ui/badge';
import { type InterestPeriodStatus, type LoanStatus } from '@/features/loans/types';

const LOAN: Record<LoanStatus, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    draft: { label: 'Draft', variant: 'outline' },
    active: { label: 'Active', variant: 'secondary' },
    overdue: { label: 'Overdue', variant: 'destructive' },
    closed: { label: 'Closed', variant: 'outline' },
    cancelled: { label: 'Cancelled', variant: 'outline' },
};

export function LoanStatusBadge({ status }: { status: LoanStatus }) {
    return <Badge variant={LOAN[status].variant}>{LOAN[status].label}</Badge>;
}

const PERIOD: Record<InterestPeriodStatus, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    upcoming: { label: 'Upcoming', variant: 'outline' },
    due: { label: 'Due', variant: 'default' },
    partially_paid: { label: 'Partially paid', variant: 'secondary' },
    paid: { label: 'Paid', variant: 'secondary' },
    overdue: { label: 'Overdue', variant: 'destructive' },
    waived: { label: 'Waived', variant: 'outline' },
};

export function PeriodStatusBadge({ status }: { status: InterestPeriodStatus }) {
    return <Badge variant={PERIOD[status].variant}>{PERIOD[status].label}</Badge>;
}
