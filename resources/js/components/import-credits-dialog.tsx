import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { JsonRequestError, postFile } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { AlertCircle, ArrowRight, CheckCircle2, Download, FileSpreadsheet, Loader2, Minus, Upload } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { type ChangeEvent, type DragEvent, useRef, useState } from 'react';

interface ImportCreditsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    year: number;
}

/** Mirrors LeaveCreditImportService's Change. */
interface FigureChange {
    code: string;
    from: string | null;
    to: string;
}

/** Mirrors LeaveCreditImportService's PreviewRow. */
interface PreviewRow {
    line: number;
    employeeNo: string;
    name: string;
    changes: FigureChange[];
    errors: string[];
}

interface ImportPreview {
    rows: PreviewRow[];
    readyCount: number;
    unchangedCount: number;
    problemCount: number;
    figureCount: number;
    ignoredColumns: string[];
}

type RowFilter = 'changes' | 'problems' | 'all';

/** Enough to scan a file; the counts above the table always cover every row. */
const MAX_VISIBLE_ROWS = 300;

/**
 * Filling a year from Excel: download the year's sheet, type over it, choose
 * it here, check what would change, save.
 *
 * Rows are matched by employee number. A blank cell leaves that figure alone,
 * which the guide says in so many words, because it is the one place this
 * import behaves differently from the grid.
 */
export function ImportCreditsDialog({ open, onOpenChange, year }: ImportCreditsDialogProps) {
    const reduceMotion = useReducedMotion();
    const inputRef = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [checking, setChecking] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const [filter, setFilter] = useState<RowFilter>('changes');

    const busy = checking || saving;

    const reset = () => {
        setFile(null);
        setPreview(null);
        setError(null);
        setFilter('changes');

        if (inputRef.current) {
            inputRef.current.value = '';
        }
    };

    const close = (next: boolean) => {
        if (busy) {
            return;
        }

        onOpenChange(next);

        if (!next) {
            reset();
        }
    };

    const check = async (chosen: File) => {
        setFile(chosen);
        setPreview(null);
        setError(null);
        setChecking(true);

        try {
            const result = await postFile<ImportPreview>(route('credits.import.preview'), { file: chosen, year: String(year) });

            setPreview(result);
            setFilter(result.problemCount > 0 ? 'problems' : 'changes');
        } catch (exception) {
            const errors = exception instanceof JsonRequestError ? exception.errors : undefined;

            setError(errors?.file?.[0] ?? errors?.year?.[0] ?? 'The file could not be checked. Please try again.');
        } finally {
            setChecking(false);
        }
    };

    const onFileChange = (event: ChangeEvent<HTMLInputElement>) => {
        const chosen = event.target.files?.[0];

        if (chosen) {
            void check(chosen);
        }
    };

    const onDrop = (event: DragEvent<HTMLLabelElement>) => {
        event.preventDefault();
        setDragging(false);

        const dropped = event.dataTransfer.files[0];

        if (dropped && !busy) {
            void check(dropped);
        }
    };

    const save = () => {
        if (!file) {
            return;
        }

        router.post(
            route('credits.import.store'),
            { file, year },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    onOpenChange(false);
                    reset();
                },
                onError: (errors) => setError(errors.file ?? errors.year ?? 'The credits could not be saved. Please try again.'),
            },
        );
    };

    const rows = preview
        ? preview.rows.filter((row) =>
              filter === 'all' ? true : filter === 'problems' ? row.errors.length > 0 : row.errors.length === 0 && row.changes.length > 0,
          )
        : [];
    const visibleRows = rows.slice(0, MAX_VISIBLE_ROWS);

    const fade = reduceMotion
        ? { initial: { opacity: 1 }, animate: { opacity: 1 }, exit: { opacity: 1 } }
        : { initial: { opacity: 0, y: 6 }, animate: { opacity: 1, y: 0 }, exit: { opacity: 0, y: -4 } };

    const filters: { value: RowFilter; label: string; count: number }[] = preview
        ? [
              { value: 'changes', label: 'Changes', count: preview.readyCount },
              ...(preview.problemCount > 0 ? [{ value: 'problems' as const, label: 'Problems', count: preview.problemCount }] : []),
              { value: 'all', label: 'All rows', count: preview.rows.length },
          ]
        : [];

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="flex max-h-[92vh] w-[calc(100vw-2rem)] max-w-4xl flex-col gap-0 overflow-hidden p-0 sm:max-w-4xl">
                <DialogHeader className="border-b px-6 pt-6 pb-4 text-left">
                    <DialogTitle>Import {year} credits from Excel</DialogTitle>
                    <DialogDescription>Choose the filled-in sheet. Nothing is saved until you have checked what will change.</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    <div className="grid gap-4 lg:grid-cols-[1fr_17rem]">
                        <label
                            htmlFor="credits-file"
                            onDragOver={(event) => {
                                event.preventDefault();
                                setDragging(true);
                            }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={onDrop}
                            className={cn(
                                'border-border bg-background flex min-h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-5 text-center transition-colors',
                                'hover:border-primary/60 hover:bg-accent/40 focus-within:ring-ring focus-within:ring-2 focus-within:ring-offset-2',
                                dragging && 'border-primary bg-accent/60',
                                busy && 'pointer-events-none opacity-60',
                            )}
                        >
                            <input
                                ref={inputRef}
                                id="credits-file"
                                type="file"
                                accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                                onChange={onFileChange}
                                disabled={busy}
                                className="sr-only"
                            />
                            {checking ? (
                                <Loader2 aria-hidden="true" className="text-primary size-6 animate-spin" />
                            ) : (
                                <FileSpreadsheet aria-hidden="true" className="text-muted-foreground size-6" />
                            )}
                            {file ? (
                                <>
                                    <span className="text-sm font-semibold break-all">{file.name}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {checking ? 'Checking every row…' : 'Choose or drop another file to replace it'}
                                    </span>
                                </>
                            ) : (
                                <>
                                    <span className="text-sm font-semibold">Choose the filled-in {year} sheet</span>
                                    <span className="text-muted-foreground text-xs">or drop it here · .xlsx or .csv · up to 2 MB</span>
                                </>
                            )}
                        </label>

                        <div className="bg-muted/40 space-y-3 rounded-lg border p-4 text-xs">
                            <p className="text-sm font-semibold">How it reads the file</p>
                            <ul className="text-muted-foreground list-disc space-y-1 pl-4">
                                <li>
                                    Rows are matched by <span className="text-foreground">EMPLOYEE NO.</span> Names and offices are only for reading.
                                </li>
                                <li>One column per leave code, in days, steps of 0.5.</li>
                                <li>
                                    <span className="text-foreground">A blank cell leaves that figure as it is.</span> To clear one, empty it in the
                                    grid.
                                </li>
                            </ul>
                            <Button variant="outline" size="sm" asChild className="w-full">
                                <a href={route('credits.import.template', { year })} download>
                                    <Download aria-hidden="true" />
                                    Download the {year} sheet
                                </a>
                            </Button>
                        </div>
                    </div>

                    {error && (
                        <div
                            role="alert"
                            className="border-destructive/30 bg-destructive/5 text-destructive flex items-start gap-2 rounded-lg border p-3 text-sm"
                        >
                            <AlertCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                            <span>{error}</span>
                        </div>
                    )}

                    <AnimatePresence mode="wait">
                        {preview && (
                            <motion.section
                                key={file?.name ?? 'preview'}
                                {...fade}
                                transition={{ duration: reduceMotion ? 0 : 0.22, ease: [0.22, 1, 0.36, 1] }}
                                aria-label="Preview"
                                className="space-y-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-sm" aria-live="polite">
                                        <span className="text-success font-semibold" data-numeric>
                                            {preview.figureCount}
                                        </span>{' '}
                                        {preview.figureCount === 1 ? 'figure' : 'figures'} to save for{' '}
                                        <span className="font-semibold" data-numeric>
                                            {preview.readyCount}
                                        </span>{' '}
                                        {preview.readyCount === 1 ? 'employee' : 'employees'}
                                        <span className="text-muted-foreground"> · {preview.unchangedCount} unchanged</span>
                                        {preview.problemCount > 0 && (
                                            <>
                                                <span className="text-muted-foreground"> · </span>
                                                <span className="text-destructive font-semibold" data-numeric>
                                                    {preview.problemCount}
                                                </span>{' '}
                                                {preview.problemCount === 1 ? 'needs' : 'need'} fixing
                                            </>
                                        )}
                                    </p>

                                    <div className="flex rounded-md border p-0.5 text-xs" role="group" aria-label="Rows to show">
                                        {filters.map((option) => (
                                            <button
                                                key={option.value}
                                                type="button"
                                                aria-pressed={filter === option.value}
                                                onClick={() => setFilter(option.value)}
                                                className={cn(
                                                    'focus-visible:ring-ring cursor-pointer rounded px-2.5 py-1.5 font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                                    filter === option.value
                                                        ? 'bg-foreground text-background'
                                                        : 'text-muted-foreground hover:text-foreground',
                                                )}
                                            >
                                                {option.label} <span data-numeric>{option.count}</span>
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                {preview.ignoredColumns.length > 0 && (
                                    <p className="border-warning/30 bg-warning-surface text-warning rounded-lg border p-3 text-xs">
                                        Not read: {preview.ignoredColumns.join(', ')}. Only the employee number and the leave code columns are
                                        imported.
                                    </p>
                                )}

                                <div className="max-h-[22rem] overflow-auto rounded-lg border">
                                    {visibleRows.length === 0 ? (
                                        <p className="text-muted-foreground px-4 py-8 text-center text-sm">
                                            {filter === 'changes' ? 'No figure in this file differs from what is on file.' : 'No rows to show.'}
                                        </p>
                                    ) : (
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-muted text-muted-foreground text-2xs sticky top-0 z-10 font-semibold tracking-wider uppercase">
                                                <tr>
                                                    <th scope="col" className="w-14 px-3 py-2.5">
                                                        Row
                                                    </th>
                                                    <th scope="col" className="px-3 py-2.5">
                                                        Employee
                                                    </th>
                                                    <th scope="col" className="px-3 py-2.5">
                                                        What changes
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {visibleRows.map((row) => (
                                                    <PreviewLine key={row.line} row={row} />
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </div>

                                {rows.length > visibleRows.length && (
                                    <p className="text-muted-foreground text-xs">
                                        Showing the first {MAX_VISIBLE_ROWS} of {rows.length} rows.
                                    </p>
                                )}
                            </motion.section>
                        )}
                    </AnimatePresence>
                </div>

                <DialogFooter className="bg-card flex-col gap-3 border-t px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="text-muted-foreground text-xs sm:max-w-md">
                        {preview && preview.problemCount > 0 && (
                            <p className="text-destructive font-medium">
                                Fix the rows marked above in your file, save it, and choose it again. Nothing is saved while any row has a problem.
                            </p>
                        )}
                    </div>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row">
                        <Button type="button" variant="outline" onClick={() => close(false)} disabled={busy} className="cursor-pointer">
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={save}
                            disabled={busy || !preview || preview.problemCount > 0 || preview.figureCount === 0}
                            className="cursor-pointer"
                        >
                            {saving ? <Loader2 aria-hidden="true" className="animate-spin" /> : <Upload aria-hidden="true" />}
                            {preview && preview.problemCount === 0 && preview.figureCount > 0
                                ? `Save ${preview.figureCount} ${preview.figureCount === 1 ? 'figure' : 'figures'}`
                                : 'Save figures'}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function PreviewLine({ row }: { row: PreviewRow }) {
    const ok = row.errors.length === 0;

    return (
        <tr className={ok ? undefined : 'bg-destructive/5'}>
            <td className="text-muted-foreground px-3 py-2.5 align-top" data-numeric>
                {row.line}
            </td>
            <td className="px-3 py-2.5 align-top">
                <div className="flex items-start gap-2">
                    {ok ? (
                        <CheckCircle2 aria-label="Ready" className="text-success mt-0.5 size-4 shrink-0" />
                    ) : (
                        <AlertCircle aria-label="Needs fixing" className="text-destructive mt-0.5 size-4 shrink-0" />
                    )}
                    <div className="min-w-0">
                        <p className="font-medium">{row.name || <span className="text-muted-foreground italic">Unknown employee</span>}</p>
                        <p className="text-muted-foreground text-xs" data-numeric>
                            {row.employeeNo || 'no number'}
                        </p>
                    </div>
                </div>
            </td>
            <td className="px-3 py-2.5 align-top">
                {!ok ? (
                    <ul className="text-destructive space-y-0.5 text-xs">
                        {row.errors.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                ) : row.changes.length === 0 ? (
                    <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                        <Minus aria-hidden="true" className="size-3" />
                        No change
                    </span>
                ) : (
                    <ul className="flex flex-wrap gap-1.5">
                        {row.changes.map((change) => (
                            <li
                                key={change.code}
                                className="bg-success-surface inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-xs"
                                data-numeric
                            >
                                <span className="font-sans font-bold">{change.code}</span>
                                <span className="text-muted-foreground">{change.from ?? '—'}</span>
                                <ArrowRight aria-label="becomes" className="size-3" />
                                <span className="font-semibold">{change.to}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </td>
        </tr>
    );
}
