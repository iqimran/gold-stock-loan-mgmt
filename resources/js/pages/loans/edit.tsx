import Heading from '@/components/heading';
import { LoanTermsForm } from '@/features/loans/loan-terms-form';
import { type Loan } from '@/features/loans/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface EditLoanProps {
    loan: Loan;
    rateTypes: string[];
    periodUnits: string[];
}

export default function EditLoan({ loan, rateTypes, periodUnits }: EditLoanProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Loans', href: '/loans' },
        { title: loan.loan_no, href: `/loans/${loan.loan_no}` },
        { title: 'Edit', href: `/loans/${loan.loan_no}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${loan.loan_no}`} />
            <div className="p-4 md:p-6">
                <Heading
                    title={`Edit ${loan.loan_no}`}
                    description={loan.customer ? `${loan.customer.name} · ${loan.customer.customer_no}` : undefined}
                />
                <div className="max-w-3xl">
                    <LoanTermsForm loan={loan} rateTypes={rateTypes} periodUnits={periodUnits} today={loan.start_date} />
                </div>
            </div>
        </AppLayout>
    );
}
