import { DataTable, type DataTableColumn } from '@/components/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useVisitState } from '@/hooks/use-visit-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/admin/audit-logs' }];
const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

const AREA_LABELS: Record<string, string> = {
    loan: 'Loans',
    payment: 'Payments',
    collateral: 'Collateral',
    customer: 'Customers',
    settings: 'Settings',
    user: 'Users',
    role: 'Roles',
    auth: 'Sign-ins',
};

type Values = Record<string, unknown> | null;

interface AuditEntry {
    id: number;
    event: string;
    event_label: string;
    description: string | null;
    user: { name: string; email: string } | null;
    entity: { type: string; reference: string | null; url: string | null } | null;
    old_values: Values;
    new_values: Values;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string | null;
}

interface Filters {
    q: string;
    event: string;
    area: string;
    reference: string;
    user: string;
    system: string | boolean;
    from: string;
    to: string;
    [key: string]: string | boolean;
}

interface AuditLogProps {
    logs: Paginated<AuditEntry>;
    filters: Filters;
    events: Record<string, string>;
    areas: string[];
    users: { id: number; name: string; email: string }[];
}

function show(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

/** Field-by-field before → after of one entry (only what changed was recorded). */
function Changes({ entry }: { entry: AuditEntry }) {
    const keys = Array.from(new Set([...Object.keys(entry.old_values ?? {}), ...Object.keys(entry.new_values ?? {})]));

    return (
        <div className="space-y-3 text-sm">
            {keys.length > 0 ? (
                <table className="w-full text-left">
                    <thead className="text-muted-foreground text-xs">
                        <tr>
                            <th className="py-1 pr-4 font-medium">Field</th>
                            <th className="py-1 pr-4 font-medium">Before</th>
                            <th className="py-1 font-medium">After</th>
                        </tr>
                    </thead>
                    <tbody>
                        {keys.map((key) => (
                            <tr key={key} className="border-t align-top">
                                <td className="py-1 pr-4 font-mono text-xs">{key}</td>
                                <td className="py-1 pr-4 break-all">{show(entry.old_values?.[key])}</td>
                                <td className="py-1 break-all">{show(entry.new_values?.[key])}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <p className="text-muted-foreground">No field values recorded.</p>
            )}
            {(entry.ip_address || entry.user_agent) && (
                <p className="text-muted-foreground text-xs break-all">
                    {entry.ip_address} {entry.user_agent && `· ${entry.user_agent}`}
                </p>
            )}
        </div>
    );
}

/**
 * Administration → Audit log (audit.view). Every financial and sensitive change with who, when, what,
 * which record and the before/after values. Read-only; the server filters and paginates.
 */
export default function AuditLogIndex({ logs, filters, events, areas, users }: AuditLogProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [form, setForm] = useState<Filters>({ ...filters, system: filters.system ? '1' : '' });
    const [open, setOpen] = useState<number | null>(null);
    const { loading, error, retry } = useVisitState('/admin/audit-logs');
    const hasFilters = ['q', 'event', 'area', 'reference', 'user', 'from', 'to'].some((key) => filters[key] !== '') || Boolean(filters.system);

    const load = (values: Filters) =>
        router.get(
            route('admin.audit-logs.index'),
            Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value !== false)),
            {
                preserveState: true,
                replace: true,
            },
        );

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        load(form);
    };

    const set = (key: keyof Filters) => (e: { target: { value: string } }) => setForm({ ...form, [key]: e.target.value });

    const columns: DataTableColumn<AuditEntry>[] = [
        {
            key: 'toggle',
            header: <span className="sr-only">Details</span>,
            cell: (entry) => (
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label={open === entry.id ? 'Hide changes' : 'Show changes'}
                    aria-expanded={open === entry.id}
                    onClick={() => setOpen(open === entry.id ? null : entry.id)}
                >
                    {open === entry.id ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                </Button>
            ),
        },
        { key: 'when', header: 'When', cell: (entry) => formatDateTime(entry.created_at), className: 'whitespace-nowrap' },
        {
            key: 'action',
            header: 'Action',
            cell: (entry) => (
                <div className="min-w-40">
                    <Badge variant="secondary">{entry.event_label}</Badge>
                    {entry.description && <div className="text-muted-foreground mt-1 text-xs">{entry.description}</div>}
                </div>
            ),
        },
        {
            key: 'record',
            header: 'Record',
            cell: (entry) =>
                entry.entity ? (
                    <div className="whitespace-nowrap">
                        <span className="text-muted-foreground text-xs">{entry.entity.type}</span>
                        <div>
                            {entry.entity.url ? (
                                <Link href={entry.entity.url} className="font-medium hover:underline">
                                    {entry.entity.reference}
                                </Link>
                            ) : (
                                entry.entity.reference
                            )}
                        </div>
                    </div>
                ) : (
                    '—'
                ),
            className: 'hidden md:table-cell',
        },
        {
            key: 'user',
            header: 'By',
            cell: (entry) =>
                entry.user ? (
                    <div className="whitespace-nowrap">
                        {entry.user.name}
                        <div className="text-muted-foreground text-xs">{entry.user.email}</div>
                    </div>
                ) : (
                    <span className="text-muted-foreground">System</span>
                ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit log" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    title="Audit log"
                    description="Every financial and sensitive change: who, when, what and the values before and after. Entries cannot be changed."
                />

                <form onSubmit={apply} className="space-y-2" aria-label="Filter audit log">
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Input
                            type="search"
                            placeholder="Search description"
                            aria-label="Search"
                            value={String(form.q)}
                            onChange={set('q')}
                            className="sm:max-w-56"
                        />
                        <Input
                            placeholder="Loan, receipt, collateral or customer no."
                            aria-label="Record number"
                            value={String(form.reference)}
                            onChange={set('reference')}
                            className="sm:max-w-64"
                        />
                        <select className={selectClass} value={String(form.area)} onChange={set('area')} aria-label="Area">
                            <option value="">All areas</option>
                            {areas.map((area) => (
                                <option key={area} value={area}>
                                    {AREA_LABELS[area] ?? area}
                                </option>
                            ))}
                        </select>
                        <select className={selectClass} value={String(form.event)} onChange={set('event')} aria-label="Action">
                            <option value="">All actions</option>
                            {Object.entries(events).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <select
                            className={selectClass}
                            value={form.system ? 'system' : String(form.user)}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    user: e.target.value === 'system' ? '' : e.target.value,
                                    system: e.target.value === 'system' ? '1' : '',
                                })
                            }
                            aria-label="User"
                        >
                            <option value="">Anyone</option>
                            <option value="system">System (automatic)</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                        <Input type="date" aria-label="From" value={String(form.from)} onChange={set('from')} className="sm:w-40" />
                        <Input type="date" aria-label="To" value={String(form.to)} onChange={set('to')} className="sm:w-40" />
                        <Button type="submit" variant="secondary">
                            Apply
                        </Button>
                        {hasFilters && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => load({ q: '', event: '', area: '', reference: '', user: '', system: '', from: '', to: '' })}
                            >
                                Clear
                            </Button>
                        )}
                    </div>
                    <InputError message={Object.values(errors)[0]} />
                </form>

                <DataTable
                    columns={columns}
                    rows={logs.data}
                    rowKey={(entry) => entry.id}
                    meta={logs.meta}
                    loading={loading}
                    error={error}
                    onRetry={retry}
                    empty="No audit entries match these filters."
                />

                {open !== null &&
                    logs.data
                        .filter((entry) => entry.id === open)
                        .map((entry) => (
                            <section key={entry.id} aria-label="Changes" className="rounded-lg border p-4">
                                <h3 className="mb-3 text-sm font-semibold">
                                    {entry.event_label} · {formatDateTime(entry.created_at)}
                                </h3>
                                <Changes entry={entry} />
                            </section>
                        ))}
            </div>
        </AppLayout>
    );
}
