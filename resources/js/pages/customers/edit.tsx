import Heading from '@/components/heading';
import { CustomerForm } from '@/features/customers/customer-form';
import { type Customer } from '@/features/customers/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

export default function EditCustomer({ customer }: { customer: Customer }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/customers' },
        { title: customer.name, href: `/customers/${customer.customer_no}` },
        { title: 'Edit', href: `/customers/${customer.customer_no}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${customer.name}`} />
            <div className="p-4 md:p-6">
                <Heading title={`Edit ${customer.name}`} description={`Customer ${customer.customer_no}`} />
                <CustomerForm customer={customer} />
            </div>
        </AppLayout>
    );
}
