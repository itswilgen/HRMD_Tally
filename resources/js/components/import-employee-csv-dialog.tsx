import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { JsonRequestError, postFile } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Download, FileSpreadsheet, Loader2, Upload } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { type ChangeEvent, type DragEvent, useRef, useState } from 'react';

interface ImportEmployeeCsvDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The HR Admin columns this user may fill, so the guide matches the template they download. */
    adminColumns: string[];
}

/** One row as the server read it. Mirrors EmployeeImportService's PreviewRow. */
interface PreviewRow {
    line: number;
    employeeNo: string;
    name: string;
    positionTitle: string;
    officeName: string | null;
    salaryGrade: string | null;
    employmentStatus: string | null;
    errors: string[];
}

interface ImportPreview {
    rows: PreviewRow[];
    readyCount: number;
    problemCount: number;
    ignoredColumns: string[];
}

/** Enough to scan a file; the counts above the table always cover every row. */
const MAX_VISIBLE_ROWS = 300;

export function ImportEmployeeCsvDialog({ open, onOpenChange, adminColumns }: ImportEmployeeCsvDialogProps) {
    const reduceMotion = useReducedMotion();
    const inputRef = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [checking, setChecking] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const [onlyProblems, setOnlyProblems] = useState(false);

    const busy = checking || saving;

    const reset = () => {
        setFile(null);
        setPreview(null);
        setError(null);
        setOnlyProblems(false);

        if (inputRef.current) {
            inputRef.current.value = '';
        }
    };

    const close = (next: boolean) => {
        // Closing mid-save would hide the result the person is waiting for.
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
        setOnlyProblems(false);
        setChecking(true);

        try {
            const result = await postFile<ImportPreview>(route('employees.import.preview'), { file: chosen });

            setPreview(result);
            setOnlyProblems(result.problemCount > 0);
        } catch (exception) {
            setError(
                exception instanceof JsonRequestError && exception.errors.file?.[0]
                    ? exception.errors.file[0]
                    : 'The file could not be checked. Please try again.',
            );
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

        // A normal Inertia visit, not fetch: the server checks the file again,
        // and the success message arrives as the same toast as any other save.
        router.post(
            route('employees.import.store'),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    onOpenChange(false);
                    reset();
                },
                onError: (errors) => setError(errors.file ?? 'The employees could not be added. Please try again.'),
            },
        );
    };

    const rows = preview ? (onlyProblems ? preview.rows.filter((row) => row.errors.length > 0) : preview.rows) : [];
    const visibleRows = rows.slice(0, MAX_VISIBLE_ROWS);

    const fade = reduceMotion
        ? { initial: { opacity: 1 }, animate: { opacity: 1 }, exit: { opacity: 1 } }
        : { initial: { opacity: 0, y: 6 }, animate: { opacity: 1, y: 0 }, exit: { opacity: 0, y: -4 } };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="flex max-h-[92vh] w-[calc(100vw-2rem)] max-w-5xl flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl">
                <DialogHeader className="border-b px-6 pt-6 pb-4 text-left">
                    <DialogTitle className="text-lg font-bold">Import employees from CSV</DialogTitle>
                    <DialogDescription>
                        Fill in the template, save it as CSV, and choose it here. Nothing is added until you have checked the preview.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
                        <label
                            htmlFor="masterlist-file"
                            onDragOver={(event) => {
                                event.preventDefault();
                                setDragging(true);
                            }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={onDrop}
                            className={cn(
                                'border-border bg-background flex min-h-32 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-5 text-center transition-colors',
                                'hover:border-primary/60 hover:bg-accent/40 focus-within:ring-ring focus-within:ring-2 focus-within:ring-offset-2',
                                dragging && 'border-primary bg-accent/60',
                                busy && 'pointer-events-none opacity-60',
                            )}
                        >
                            <input
                                ref={inputRef}
                                id="masterlist-file"
                                type="file"
                                accept=".csv,text/csv"
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
                                    <span className="text-sm font-semibold">Choose a CSV file</span>
                                    <span className="text-muted-foreground text-xs">or drop it here · up to 2 MB</span>
                                </>
                            )}
                        </label>

                        <div className="bg-muted/40 space-y-3 rounded-lg border p-4 text-xs">
                            <div className="space-y-1">
                                <p className="text-sm font-semibold">Columns</p>
                                <p className="text-muted-foreground">
                                    Required: <span className="text-foreground">Employee No, Last Name, First Name, Position Title, Office</span>
                                </p>
                                <p className="text-muted-foreground">
                                    Optional: Middle Name, Suffix, Salary Grade
                                    {adminColumns.map((column) => `, ${column}`).join('')}
                                </p>
                            </div>
                            <p className="text-muted-foreground">Write the office exactly as it is named under Settings → Offices.</p>
                            <Button variant="outline" size="sm" asChild className="w-full">
                                <a href={route('employees.import.template')} download>
                                    <Download aria-hidden="true" />
                                    Download template
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
                                            {preview.readyCount}
                                        </span>{' '}
                                        ready
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

                                    {preview.problemCount > 0 && (
                                        <div className="flex rounded-md border p-0.5 text-xs" role="group" aria-label="Rows to show">
                                            {[
                                                { value: true, label: 'Rows with problems' },
                                                { value: false, label: 'All rows' },
                                            ].map((option) => (
                                                <button
                                                    key={option.label}
                                                    type="button"
                                                    aria-pressed={onlyProblems === option.value}
                                                    onClick={() => setOnlyProblems(option.value)}
                                                    className={cn(
                                                        'focus-visible:ring-ring cursor-pointer rounded px-2.5 py-1.5 font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                                        onlyProblems === option.value
                                                            ? 'bg-foreground text-background'
                                                            : 'text-muted-foreground hover:text-foreground',
                                                    )}
                                                >
                                                    {option.label}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                {preview.ignoredColumns.length > 0 && (
                                    <p className="border-warning/30 bg-warning-surface text-warning rounded-lg border p-3 text-xs">
                                        The {preview.ignoredColumns.join(' and ')} {preview.ignoredColumns.length === 1 ? 'column' : 'columns'} will
                                        be left blank: only an HR Admin can fill {preview.ignoredColumns.length === 1 ? 'it' : 'them'} in.
                                    </p>
                                )}

                                <div className="max-h-[22rem] overflow-auto rounded-lg border">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-muted-foreground text-2xs sticky top-0 z-10 font-semibold tracking-wider uppercase">
                                            <tr>
                                                <th scope="col" className="w-14 px-3 py-2.5">
                                                    Row
                                                </th>
                                                <th scope="col" className="px-3 py-2.5">
                                                    Employee
                                                </th>
                                                <th scope="col" className="hidden px-3 py-2.5 md:table-cell">
                                                    Position
                                                </th>
                                                <th scope="col" className="hidden px-3 py-2.5 lg:table-cell">
                                                    Office
                                                </th>
                                                <th scope="col" className="hidden w-12 px-3 py-2.5 text-right sm:table-cell">
                                                    SG
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {visibleRows.map((row) => {
                                                const ok = row.errors.length === 0;

                                                return (
                                                    <tr key={row.line} className={ok ? undefined : 'bg-destructive/5'}>
                                                        <td className="text-muted-foreground px-3 py-2.5 align-top" data-numeric>
                                                            {row.line}
                                                        </td>
                                                        <td className="px-3 py-2.5 align-top">
                                                            <div className="flex items-start gap-2">
                                                                {ok ? (
                                                                    <CheckCircle2
                                                                        aria-label="Ready"
                                                                        className="text-success mt-0.5 size-4 shrink-0"
                                                                    />
                                                                ) : (
                                                                    <AlertCircle
                                                                        aria-label="Needs fixing"
                                                                        className="text-destructive mt-0.5 size-4 shrink-0"
                                                                    />
                                                                )}
                                                                <div className="min-w-0">
                                                                    <p className="font-medium">
                                                                        {row.name || <span className="text-muted-foreground italic">No name</span>}
                                                                    </p>
                                                                    <p className="text-muted-foreground text-xs" data-numeric>
                                                                        {row.employeeNo || '—'}
                                                                        {row.employmentStatus && <span> · {row.employmentStatus}</span>}
                                                                    </p>
                                                                    {!ok && (
                                                                        <ul className="text-destructive mt-1.5 space-y-0.5 text-xs">
                                                                            {row.errors.map((message) => (
                                                                                <li key={message}>{message}</li>
                                                                            ))}
                                                                        </ul>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="hidden px-3 py-2.5 align-top md:table-cell">{row.positionTitle || '—'}</td>
                                                        <td className="text-muted-foreground hidden px-3 py-2.5 align-top lg:table-cell">
                                                            {row.officeName ?? '—'}
                                                        </td>
                                                        <td className="hidden px-3 py-2.5 text-right align-top sm:table-cell" data-numeric>
                                                            {row.salaryGrade ?? '—'}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
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
                                Fix the rows marked above in your file, save it, and choose it again. Nothing is added while any row has a problem.
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
                            disabled={busy || !preview || preview.problemCount > 0 || preview.readyCount === 0}
                            className="cursor-pointer"
                        >
                            {saving ? <Loader2 aria-hidden="true" className="animate-spin" /> : <Upload aria-hidden="true" />}
                            {preview && preview.problemCount === 0
                                ? `Add ${preview.readyCount} ${preview.readyCount === 1 ? 'employee' : 'employees'}`
                                : 'Add employees'}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
