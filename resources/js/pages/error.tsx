import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';

const messages: Record<number, { title: string; description: string }> = {
    403: { title: 'Access denied', description: 'You do not have permission to perform this action.' },
    404: { title: 'Page not found', description: 'The page you are looking for does not exist or has been moved.' },
    500: { title: 'Server error', description: 'Something went wrong on our side. Please try again shortly.' },
    503: { title: 'Service unavailable', description: 'The application is under maintenance. Please check back soon.' },
};

export default function Error({ status }: { status: number }) {
    const { title, description } = messages[status] ?? messages[500];

    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-4 p-6 text-center">
            <Head title={title} />
            <p className="text-muted-foreground text-sm font-semibold">{status}</p>
            <h1 className="text-2xl font-semibold">{title}</h1>
            <p className="text-muted-foreground max-w-md text-sm">{description}</p>
            <div className="flex gap-2">
                <Button variant="outline" onClick={() => window.history.back()}>
                    Go back
                </Button>
                <Button asChild>
                    <Link href="/dashboard">Dashboard</Link>
                </Button>
            </div>
        </div>
    );
}
