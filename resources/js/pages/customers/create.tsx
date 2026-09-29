import Heading from '@/components/heading';
import { CustomerForm } from '@/features/customers/customer-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: 'New customer', href: '/customers/create' },
];

export default function CreateCustomer() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New customer" />
            <div className="p-4 md:p-6">
                <Heading title="New customer" description="A customer number is assigned automatically." />
                <CustomerForm />
            </div>
        </AppLayout>
    );
}
