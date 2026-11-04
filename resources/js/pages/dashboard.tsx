import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuthUser } from '@/hooks/use-auth';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    CalendarRange,
    CheckCircle2,
    Clock,
    Contact,
    FileSpreadsheet,
    ScrollText,
    ShieldCheck,
    Sparkles,
    UsersRound,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const upcomingPhases = [
    {
        icon: CalendarRange,
        title: 'Yearly Leave Tally',
        detail: 'Dynamic multi-month grid tracking employee attendance, vacation, and sick leave balances.',
        status: 'Active',
        phase: 'Phase 1',
    },
    {
        icon: Contact,
        title: 'Employee Masterlist',
        detail: 'Comprehensive 201 personnel records, salary grade tracking, and administrative office assignments.',
        status: 'Active',
        phase: 'Phase 1',
    },
    {
        icon: FileSpreadsheet,
        title: 'Printable PDF & Excel Exports',
        detail: 'Official signed CSC-format tally sheets for archival filing and spreadsheet audit exports.',
        status: 'Upcoming',
        phase: 'Phase 2',
    },
    {
        icon: ScrollText,
        title: 'Security Activity Log',
        detail: 'Immutable audit trail recording every encoded leave, alteration, and administrative access.',
        status: 'Upcoming',
        phase: 'Phase 2',
    },
];

export default function Dashboard() {
    const user = useAuthUser();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <PageHeader
                eyebrow={user.roleLabel ?? 'HR Portal'}
                title={`Good day, ${user.name.split(' ')[0]}.`}
                description="Welcome to the Human Resource Management Division Leave Tally & Records Portal. Access active encoding tools or browse personnel rosters below."
                actions={
                    <div className="flex items-center gap-2">
                        {user.can.viewTally && (
                            <Button asChild className="cursor-pointer shadow-xs">
                                <Link href="/tally">
                                    <CalendarRange aria-hidden="true" />
                                    Open Tally Sheet
                                </Link>
                            </Button>
                        )}
                        {user.can.manageUsers && (
                            <Button asChild variant="outline" className="cursor-pointer">
                                <Link href={route('users.index')}>
                                    <UsersRound aria-hidden="true" />
                                    User accounts
                                </Link>
                            </Button>
                        )}
                    </div>
                }
            />

            {/* Quick Access Modules */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {user.can.viewTally && (
                    <Card className="group hover:border-primary/40 relative overflow-hidden transition-all hover:shadow-sm">
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <div className="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-xl transition-transform group-hover:scale-105">
                                    <CalendarRange className="size-5" />
                                </div>
                                <Badge variant="success">Core Module</Badge>
                            </div>
                            <CardTitle className="mt-3 text-lg font-bold">Leave Tally Sheet</CardTitle>
                            <CardDescription className="text-xs leading-relaxed">
                                View the 12-month leave matrix, encode daily leaves, and track vacation and sick totals.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button asChild variant="outline" size="sm" className="group-hover:border-primary/50 w-full justify-between">
                                <Link href="/tally">
                                    <span>Launch Tally Sheet</span>
                                    <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-1" />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {user.can.manageEmployees && (
                    <Card className="group hover:border-primary/40 relative overflow-hidden transition-all hover:shadow-sm">
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <div className="flex size-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-700 transition-transform group-hover:scale-105 dark:text-emerald-400">
                                    <Contact className="size-5" />
                                </div>
                                <Badge variant="secondary">Records</Badge>
                            </div>
                            <CardTitle className="mt-3 text-lg font-bold">Employee Masterlist</CardTitle>
                            <CardDescription className="text-xs leading-relaxed">
                                Manage personnel 201 records, update office assignments, positions, and track status.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button asChild variant="outline" size="sm" className="group-hover:border-primary/50 w-full justify-between">
                                <Link href="/employees">
                                    <span>Browse Employees</span>
                                    <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-1" />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <Card className="group hover:border-primary/40 relative overflow-hidden transition-all hover:shadow-sm">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-700 transition-transform group-hover:scale-105 dark:text-amber-400">
                                <Building2 className="size-5" />
                            </div>
                            <Badge variant="secondary">Structure</Badge>
                        </div>
                        <CardTitle className="mt-3 text-lg font-bold">Offices & Units</CardTitle>
                        <CardDescription className="text-xs leading-relaxed">
                            Organize departmental divisions, agency units, and branch assignments across the roster.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild variant="outline" size="sm" className="group-hover:border-primary/50 w-full justify-between">
                            <Link href="/settings/offices">
                                <span>View Offices</span>
                                <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-1" />
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            </div>

            {/* Implementation Roadmap */}
            <section className="border-border/80 bg-card overflow-hidden rounded-xl border shadow-xs">
                <div className="flex items-center justify-between border-b px-5 py-4">
                    <div>
                        <h2 className="text-foreground text-sm font-semibold tracking-tight">System Implementation & Roadmap</h2>
                        <p className="text-muted-foreground mt-0.5 text-xs">
                            Features are developed and verified in structured phases with the HRMD administration.
                        </p>
                    </div>
                    <Badge variant="outline" className="hidden sm:inline-flex">
                        <Sparkles className="text-primary size-3" />
                        Release 1.0
                    </Badge>
                </div>

                <div className="divide-border/60 divide-y">
                    {upcomingPhases.map((item, index) => {
                        const isLive = item.status === 'Active';

                        return (
                            <div key={item.title} className="hover:bg-muted/20 flex items-start gap-4 p-4.5 transition-colors">
                                <span
                                    className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold ${
                                        isLive ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
                                    }`}
                                    aria-hidden="true"
                                >
                                    {index + 1}
                                </span>

                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="text-foreground flex items-center gap-1.5 text-sm font-semibold">
                                            <item.icon className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                                            {item.title}
                                        </p>
                                        <Badge variant={isLive ? 'success' : 'muted'} className="text-[10px]">
                                            {isLive ? (
                                                <>
                                                    <CheckCircle2 className="size-3" />
                                                    Ready
                                                </>
                                            ) : (
                                                <>
                                                    <Clock className="size-3" />
                                                    {item.phase}
                                                </>
                                            )}
                                        </Badge>
                                    </div>
                                    <p className="text-muted-foreground mt-1 text-xs leading-relaxed">{item.detail}</p>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </section>

            {/* Privacy Compliance Banner */}
            <div className="border-primary/20 bg-primary/5 text-muted-foreground flex items-start gap-3 rounded-xl border p-4 text-xs shadow-2xs">
                <ShieldCheck className="text-primary mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div className="space-y-0.5">
                    <p className="text-foreground font-semibold">Data Privacy Compliance (RA 10173)</p>
                    <p className="leading-relaxed">
                        Personnel records, leave files, and salary details are classified government personal information. Authorized access is logged
                        and restricted to authorized HRMD operations.
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}
