import Heading from '@/components/heading';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

export default function Dashboard() {
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <Heading title={`Welcome, ${auth.user.name}`} description="Use the navigation to access the modules available to your role." />
                <Card className="max-w-md">
                    <CardHeader>
                        <CardTitle className="text-base">Your access</CardTitle>
                        <CardDescription>
                            Role: {auth.user.roles.join(', ') || 'None assigned'} · {auth.user.permissions.length} permission(s)
                        </CardDescription>
                    </CardHeader>
                </Card>
            </div>
        </AppLayout>
    );
}
