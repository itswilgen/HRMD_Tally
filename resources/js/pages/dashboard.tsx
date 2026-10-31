import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { useAuthUser } from '@/hooks/use-auth';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CalendarDays, FileSpreadsheet, ScrollText, Table2, UsersRound } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

/*
 * Deliberately no counters here yet. Numbers on this screen would have to be
 * real to be worth reading, and the records they would count arrive with the
 * phases listed below.
 */
const upcoming = [
    { icon: UsersRound, title: 'Employees', detail: 'The 201 file: name, office, position and employment status.' },
    { icon: CalendarDays, title: 'Leave encoding', detail: 'One row per leave day, with AM/PM halves and weekends skipped.' },
    { icon: Table2, title: 'Yearly tally', detail: 'The sheet itself — every employee against January to December.' },
    { icon: FileSpreadsheet, title: 'Print and export', detail: 'The signed PDF for filing, and Excel for checking.' },
    { icon: ScrollText, title: 'Activity log', detail: 'Who encoded or changed what, and when.' },
];

export default function Dashboard() {
    const user = useAuthUser();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <PageHeader
                eyebrow={user.roleLabel ?? 'Signed in'}
                title={`Good day, ${user.name.split(' ')[0]}.`}
                description="This is the HRMD leave tally sheet. It is being built in phases — account management is ready, and the records screens follow."
                actions={
                    user.can.manageUsers ? (
                        <Button asChild variant="outline">
                            <Link href={route('users.index')}>
                                <UsersRound aria-hidden="true" />
                                User accounts
                            </Link>
                        </Button>
                    ) : null
                }
            />

            <section className="bg-card overflow-hidden rounded-xl border">
                <div className="border-b px-5 py-4">
                    <h2 className="text-sm font-medium">Coming next</h2>
                    <p className="text-muted-foreground mt-0.5 text-xs">
                        Each one is reviewed with the HRMD before the next begins, so nothing arrives half-finished.
                    </p>
                </div>

                <ol className="divide-y">
                    {upcoming.map((item, index) => (
                        <li key={item.title} className="flex items-start gap-4 px-5 py-4">
                            <span
                                className="text-muted-foreground bg-muted mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md text-xs font-semibold"
                                data-numeric
                                aria-hidden="true"
                            >
                                {index + 1}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="flex items-center gap-2 text-sm font-medium">
                                    <item.icon className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                                    {item.title}
                                </p>
                                <p className="text-muted-foreground mt-0.5 text-sm">{item.detail}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            </section>

            <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                <ArrowRight className="size-3" aria-hidden="true" />
                Employee records are personal information under RA 10173. Only open what you need for the task in front of you.
            </p>
        </AppLayout>
    );
}
