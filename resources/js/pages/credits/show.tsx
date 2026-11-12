import { CreditMeter } from '@/components/credit-meter';
import InputError from '@/components/input-error';
import { LeaveChip } from '@/components/leave-chip';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { creditInputValue, figurePayload, looksLikeFigure } from '@/lib/credits';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type CreditColumn, type CreditRow, type EmployeeRecord } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Loader2, Save } from 'lucide-react';
import { useEffect, useState } from 'react';

interface CreditsShowProps {
    employee: EmployeeRecord;
    credits: CreditRow;
    leaveTypes: CreditColumn[];
    year: number;
    years: number[];
    can: { manage: boolean };
}

/** What the form starts from: the figure on file per leave type, '' for none. */
function savedValues(credits: CreditRow, columns: CreditColumn[]): Record<string, string> {
    return Object.fromEntries(columns.map((column) => [String(column.id), creditInputValue(credits.cells[String(column.id)])]));
}

/**
 * One employee's credits for one year: for the person whose figures are not
 * the standard grant, and for checking a balance before encoding a long leave.
 */
export default function CreditsShow({ employee, credits, leaveTypes, year, years, can }: CreditsShowProps) {
    const [values, setValues] = useState(() => savedValues(credits, leaveTypes));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    // A save or a change of year brings new figures; the form follows them.
    useEffect(() => {
        setValues(savedValues(credits, leaveTypes));
        setErrors({});
    }, [credits, leaveTypes]);

    const saved = savedValues(credits, leaveTypes);
    const changed = leaveTypes.filter((column) => values[String(column.id)] !== saved[String(column.id)]);
    const invalid = leaveTypes.some((column) => !looksLikeFigure(values[String(column.id)] ?? ''));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Leave credits', href: route('credits.index', { year }) },
        { title: employee.listName, href: route('credits.show', { employee: employee.id, year }) },
    ];

    const changeYear = (next: string) => {
        if (changed.length > 0 && !window.confirm('You have unsaved credit changes. Switch years without saving them?')) {
            return;
        }

        router.get(route('credits.show', { employee: employee.id, year: next }), {}, { preserveScroll: true });
    };

    const save = () => {
        router.put(
            route('credits.save', employee.id),
            {
                year,
                credits: Object.fromEntries(changed.map((column) => [column.id, figurePayload(values[String(column.id)] ?? '')])),
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (received) => setErrors(received),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${employee.listName} — credits ${year}`} />

            <PageHeader
                eyebrow={`Leave credits · ${year}`}
                title={employee.listName}
                description={[employee.positionTitle, employee.officeName, employee.isActive ? null : 'no longer active'].filter(Boolean).join(' · ')}
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('credits.index', { year })}>
                            <ArrowLeft aria-hidden="true" />
                            All employees
                        </Link>
                    </Button>
                }
            />

            <section className="border-border/80 bg-card overflow-hidden rounded-xl border shadow-xs">
                <div className="flex flex-wrap items-end justify-between gap-4 border-b p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="year" className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                            Year
                        </Label>
                        <Select value={String(year)} onValueChange={changeYear}>
                            <SelectTrigger id="year" className="h-11 w-28 cursor-pointer rounded-lg font-medium" data-numeric>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {years.map((option) => (
                                    <SelectItem key={option} value={String(option)}>
                                        {option}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <dl className="flex gap-6 text-right">
                        <Summary label="Granted" value={credits.hasCredits ? credits.creditsTotalText : '—'} />
                        <Summary label="Taken" value={credits.usedTotalText} />
                        <Summary
                            label={credits.remainingTotal < 0 ? 'Left (overdrawn)' : 'Left'}
                            value={credits.hasCredits ? credits.remainingTotalText : '—'}
                            tone={credits.remainingTotal < 0 ? 'destructive' : 'strong'}
                        />
                    </dl>
                </div>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        save();
                    }}
                >
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">
                                {employee.listName}&rsquo;s leave credits for {year}: granted, taken and left per leave type.
                            </caption>
                            <thead className="bg-table-head text-muted-foreground text-2xs font-semibold tracking-[0.04em] uppercase">
                                <tr>
                                    <th scope="col" className="px-4 py-2.5">
                                        Leave type
                                    </th>
                                    <th scope="col" className="w-36 px-4 py-2.5 text-center">
                                        Granted
                                    </th>
                                    <th scope="col" className="w-24 px-4 py-2.5 text-center">
                                        Taken
                                    </th>
                                    <th scope="col" className="w-24 px-4 py-2.5 text-center">
                                        Left
                                    </th>
                                    <th scope="col" className="hidden w-48 px-4 py-2.5 md:table-cell">
                                        <span className="sr-only">How much is used</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {leaveTypes.map((column) => {
                                    const key = String(column.id);
                                    const cell = credits.cells[key];
                                    const value = values[key] ?? '';
                                    const error = errors[`credits.${key}`];
                                    const locked = !column.isActive && cell?.credits === null;
                                    const isChanged = value !== saved[key];

                                    return (
                                        <tr key={column.id} className="hover:bg-row-hover">
                                            <th scope="row" className="px-4 py-3 font-normal">
                                                <div className="flex items-center gap-2">
                                                    <LeaveChip color={column.color} code={column.code} />
                                                    <span className="truncate">{column.name}</span>
                                                    {!column.isActive && <span className="text-muted-foreground text-xs">(retired)</span>}
                                                </div>
                                            </th>
                                            <td className="px-4 py-2 text-center">
                                                {can.manage ? (
                                                    <div className="mx-auto max-w-28">
                                                        <input
                                                            type="text"
                                                            inputMode="decimal"
                                                            autoComplete="off"
                                                            value={value}
                                                            placeholder="—"
                                                            disabled={saving || locked}
                                                            onChange={(event) => setValues((current) => ({ ...current, [key]: event.target.value }))}
                                                            aria-label={`${column.code} days granted for ${year}`}
                                                            aria-invalid={error !== undefined || !looksLikeFigure(value) ? true : undefined}
                                                            title={locked ? `${column.code} is retired: no new figure can be added` : undefined}
                                                            className={cn(
                                                                'border-input bg-card h-10 w-full rounded-md border px-2 text-center font-mono font-semibold tabular-nums',
                                                                'focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-2 focus-visible:outline-none',
                                                                'disabled:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-70',
                                                                isChanged && 'border-primary/50 bg-success-surface',
                                                                (error !== undefined || !looksLikeFigure(value)) &&
                                                                    'border-destructive bg-destructive-surface',
                                                            )}
                                                        />
                                                        <InputError message={error} className="mt-1 text-left" />
                                                    </div>
                                                ) : (
                                                    <span className="font-mono font-semibold" data-numeric>
                                                        {cell?.credits === null || cell === undefined ? '—' : cell.creditsText}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center font-mono" data-numeric>
                                                {cell && cell.used > 0 ? cell.usedText : <span className="text-muted-foreground">0</span>}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-3 text-center font-mono font-semibold',
                                                    cell?.isOverdrawn && 'text-destructive',
                                                )}
                                                data-numeric
                                            >
                                                {cell?.remaining === null || cell === undefined ? (
                                                    <span className="text-muted-foreground font-normal">—</span>
                                                ) : (
                                                    cell.remainingText
                                                )}
                                            </td>
                                            <td className="hidden px-4 py-3 md:table-cell">
                                                {cell && <CreditMeter credits={cell.credits} used={cell.used} isOverdrawn={cell.isOverdrawn} />}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {can.manage && (
                        <div className="bg-muted/30 flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3">
                            <p className="text-muted-foreground text-xs">
                                {changed.length > 0
                                    ? `${changed.length} unsaved ${changed.length === 1 ? 'change' : 'changes'}. Empty a box to remove its figure.`
                                    : 'Days in steps of 0.5. Blank means no figure yet; 0 means none granted.'}
                                {errors.year && <span className="text-destructive block">{errors.year}</span>}
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={saving || changed.length === 0}
                                    onClick={() => {
                                        setValues(saved);
                                        setErrors({});
                                    }}
                                    className="cursor-pointer"
                                >
                                    Discard
                                </Button>
                                <Button type="submit" disabled={saving || changed.length === 0 || invalid} className="cursor-pointer">
                                    {saving ? <Loader2 aria-hidden="true" className="animate-spin" /> : <Save aria-hidden="true" />}
                                    Save credits
                                </Button>
                            </div>
                        </div>
                    )}
                </form>
            </section>
        </AppLayout>
    );
}

function Summary({ label, value, tone = 'default' }: { label: string; value: string; tone?: 'default' | 'strong' | 'destructive' }) {
    return (
        <div>
            <dt className="text-muted-foreground text-2xs font-semibold tracking-wider uppercase">{label}</dt>
            <dd
                className={cn(
                    'font-mono text-xl font-semibold tracking-tight',
                    tone === 'strong' && 'text-primary',
                    tone === 'destructive' && 'text-destructive',
                )}
                data-numeric
            >
                {value}
            </dd>
        </div>
    );
}
