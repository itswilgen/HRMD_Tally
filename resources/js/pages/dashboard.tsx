import { EmptyState } from '@/components/empty-state';
import { LeaveChip } from '@/components/leave-chip';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { useAuthUser } from '@/hooks/use-auth';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type DayPartValue, type PermissionKey } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Building2, CalendarCheck2, CalendarPlus, CalendarRange, Contact, Scale } from 'lucide-react';

interface OnLeavePerson {
    id: number;
    name: string;
    office: string | null;
    code: string;
    color: string;
    dayPart: DayPartValue;
}

interface RecentRecord {
    id: number;
    name: string;
    code: string;
    color: string;
    date: string;
    dayPart: DayPartValue;
    encodedBy: string | null;
    encodedAt: string | null;
}

interface DashboardProps {
    today: string;
    facts: {
        /** Null for anyone without the tally permission. */
        monthDaysText: string | null;
        activeEmployees: number;
        offices: number;
    };
    onLeaveToday: { total: number; people: OnLeavePerson[] } | null;
    recent: RecentRecord[] | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const shortcuts: { title: string; description: string; href: string; icon: typeof CalendarRange; permission?: PermissionKey }[] = [
    { title: 'Leave tally', description: 'Encode leave and read the yearly sheet', href: '/tally', icon: CalendarRange, permission: 'viewTally' },
    { title: 'Leave credits', description: 'Granted, taken and left per employee', href: '/credits', icon: Scale, permission: 'viewCredits' },
    { title: 'Employees', description: 'The masterlist and imports', href: '/employees', icon: Contact, permission: 'manageEmployees' },
    { title: 'Offices', description: 'Offices the sheet is filtered by', href: '/settings/offices', icon: Building2 },
];

/**
 * The first screen after signing in: today, in facts.
 *
 * Who is out is the question HR answers first every morning, so it takes the
 * wide column. The figures beside it are counts read from the records, not
 * decoration; anything this person cannot open is left out rather than shown
 * disabled.
 */
export default function Dashboard({ today, facts, onLeaveToday, recent }: DashboardProps) {
    const user = useAuthUser();
    const visibleShortcuts = shortcuts.filter((shortcut) => !shortcut.permission || user.can[shortcut.permission]);
    const monthName = new Date(`${today}T00:00:00`).toLocaleDateString('en-PH', { month: 'long' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <PageHeader
                eyebrow={formatLongDate(today)}
                title={`${greeting()}, ${user.name.split(' ')[0]}`}
                actions={
                    user.can.viewTally && (
                        <Button asChild>
                            <Link href="/tally">
                                {user.can.manageLeave ? <CalendarPlus aria-hidden="true" /> : <CalendarRange aria-hidden="true" />}
                                Open the tally sheet
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
                {onLeaveToday ? (
                    <section aria-labelledby="on-leave-heading" className="bg-card rounded-lg border">
                        <header className="flex items-baseline justify-between gap-3 border-b px-5 py-4">
                            <h2 id="on-leave-heading" className="text-base font-semibold">
                                On leave today
                            </h2>
                            <p className="text-muted-foreground text-sm" data-numeric>
                                {onLeaveToday.total === 0 ? 'Nobody' : `${onLeaveToday.total} ${onLeaveToday.total === 1 ? 'person' : 'people'}`}
                            </p>
                        </header>

                        {onLeaveToday.people.length === 0 ? (
                            <EmptyState
                                icon={CalendarCheck2}
                                title="Everyone is in today"
                                description="No leave is encoded for today. Days encoded on the tally sheet appear here on the day itself."
                            />
                        ) : (
                            <ul className="divide-y">
                                {onLeaveToday.people.map((person) => (
                                    <li key={person.id} className="flex items-center justify-between gap-4 px-5 py-3">
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold">{person.name}</p>
                                            <p className="text-muted-foreground truncate text-xs">{person.office ?? 'No office assigned'}</p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            {person.dayPart !== 'WHOLE' && (
                                                <span className="text-muted-foreground text-xs font-medium">{person.dayPart}</span>
                                            )}
                                            <LeaveChip color={person.color} code={person.code} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {onLeaveToday.total > onLeaveToday.people.length && (
                            <footer className="border-t px-5 py-3">
                                <Link href="/tally" className="text-primary text-sm font-semibold underline-offset-4 hover:underline">
                                    {onLeaveToday.total - onLeaveToday.people.length} more on the tally sheet
                                </Link>
                            </footer>
                        )}
                    </section>
                ) : (
                    <section className="bg-card rounded-lg border px-5 py-4">
                        <h2 className="text-base font-semibold">Welcome</h2>
                        <p className="text-muted-foreground mt-1 max-w-[60ch] text-sm">
                            Your account can open the screens listed on the right. Ask an HR Admin if you need the tally sheet.
                        </p>
                    </section>
                )}

                <aside className="flex flex-col gap-6">
                    <dl className="bg-card grid divide-y rounded-lg border sm:grid-cols-3 sm:divide-x sm:divide-y-0 lg:grid-cols-1 lg:divide-x-0 lg:divide-y">
                        {facts.monthDaysText !== null && (
                            <Fact label={`Leave days in ${monthName}`} value={facts.monthDaysText} note="Encoded so far, whole days and halves" />
                        )}
                        <Fact label="Active employees" value={facts.activeEmployees.toLocaleString('en-PH')} note="On the masterlist and encodable" />
                        <Fact label="Offices" value={String(facts.offices)} note="That the sheet can be filtered by" />
                    </dl>

                    <nav aria-label="Go to" className="bg-card rounded-lg border">
                        <ul className="divide-y">
                            {visibleShortcuts.map((shortcut) => (
                                <li key={shortcut.href}>
                                    <Link
                                        href={shortcut.href}
                                        className="group hover:bg-accent focus-visible:bg-accent flex items-center gap-3 px-4 py-3 transition-colors first:rounded-t-lg last:rounded-b-lg focus-visible:outline-none"
                                    >
                                        <shortcut.icon
                                            aria-hidden="true"
                                            className="text-muted-foreground group-hover:text-primary size-4 shrink-0 transition-colors"
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-semibold">{shortcut.title}</span>
                                            <span className="text-muted-foreground block truncate text-xs">{shortcut.description}</span>
                                        </span>
                                        <ArrowRight
                                            aria-hidden="true"
                                            className="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                </aside>
            </div>

            {recent && (
                <section aria-labelledby="recent-heading" className="bg-card overflow-hidden rounded-lg border">
                    <header className="border-b px-5 py-4">
                        <h2 id="recent-heading" className="text-base font-semibold">
                            Recently encoded
                        </h2>
                    </header>

                    {recent.length === 0 ? (
                        <EmptyState
                            icon={CalendarRange}
                            title="No leave encoded yet"
                            description="The latest days saved on the tally sheet will be listed here."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-table-head text-muted-foreground text-2xs font-semibold tracking-[0.04em] uppercase">
                                    <tr>
                                        <th scope="col" className="px-5 py-2.5">
                                            Employee
                                        </th>
                                        <th scope="col" className="px-3 py-2.5">
                                            Leave
                                        </th>
                                        <th scope="col" className="px-3 py-2.5">
                                            Date
                                        </th>
                                        <th scope="col" className="hidden px-3 py-2.5 md:table-cell">
                                            Encoded by
                                        </th>
                                        <th scope="col" className="px-5 py-2.5 text-right">
                                            When
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recent.map((record) => (
                                        <tr key={record.id} className="hover:bg-row-hover">
                                            <td className="max-w-56 truncate px-5 py-2.5 font-semibold">{record.name}</td>
                                            <td className="px-3 py-2.5">
                                                <span className="inline-flex items-center gap-1.5">
                                                    <LeaveChip color={record.color} code={record.code} />
                                                    {record.dayPart !== 'WHOLE' && (
                                                        <span className="text-muted-foreground text-xs">{record.dayPart}</span>
                                                    )}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap" data-numeric>
                                                {formatShortDate(record.date)}
                                            </td>
                                            <td className="text-muted-foreground hidden px-3 py-2.5 md:table-cell">{record.encodedBy ?? '—'}</td>
                                            <td className="text-muted-foreground px-5 py-2.5 text-right whitespace-nowrap">
                                                {record.encodedAt ? (
                                                    <time dateTime={record.encodedAt} title={new Date(record.encodedAt).toLocaleString('en-PH')}>
                                                        {relativeTime(record.encodedAt)}
                                                    </time>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            )}
        </AppLayout>
    );
}

function Fact({ label, value, note }: { label: string; value: string; note: string }) {
    return (
        <div className="px-5 py-4">
            <dt className="text-muted-foreground text-xs font-semibold tracking-[0.06em] uppercase">{label}</dt>
            <dd className="mt-1 text-3xl leading-none font-semibold tracking-tight" data-numeric>
                {value}
            </dd>
            <dd className="text-muted-foreground mt-1.5 text-xs">{note}</dd>
        </div>
    );
}

function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
}

/** "Thursday, 12 November 2026" — the way the office dates a memo. */
function formatLongDate(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('en-PH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function formatShortDate(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('en-PH', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** "5 minutes ago", "yesterday" — enough to tell fresh work from old. */
function relativeTime(iso: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}
