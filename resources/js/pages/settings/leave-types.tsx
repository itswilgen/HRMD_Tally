import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { chipTokens, LeaveChip } from '@/components/leave-chip';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type ChipColorOption, type LeaveTypeRow } from '@/types';
import { Head, type InertiaFormProps, router, useForm } from '@inertiajs/react';
import { ArchiveRestore, ArrowDown, ArrowUp, Check, Loader2, Lock, Pencil, Plus, Tags, Trash2, X } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { type FormEventHandler, useState } from 'react';

interface LeaveTypesPageProps {
    leaveTypes: LeaveTypeRow[];
    colors: ChipColorOption[];
    can: { manage: boolean };
}

// A type alias, not an interface: Inertia's form data needs an index signature.
type LeaveTypeForm = {
    code: string;
    name: string;
    color: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Leave types', href: '/settings/leave-types' }];

const EASE = [0.22, 1, 0.36, 1] as const;

function plural(count: number, one: string, many: string): string {
    return `${count.toLocaleString()} ${count === 1 ? one : many}`;
}

/** "Used on 1,204 leave records and 650 credit figures", or why it is free. */
function usage(type: LeaveTypeRow): string {
    if (!type.inUse) {
        return 'Not used yet';
    }

    const parts = [
        type.leaveRecordsCount > 0 ? plural(type.leaveRecordsCount, 'leave record', 'leave records') : null,
        type.leaveCreditsCount > 0 ? plural(type.leaveCreditsCount, 'credit figure', 'credit figures') : null,
    ].filter((part): part is string => part !== null);

    return `Used on ${parts.join(' and ')}`;
}

/**
 * Code, name and colour, shared by the add form and the inline edit so the
 * two can never ask different questions. The chip beside the fields is the
 * one HR will see on the tally, drawn by the same LeaveChip component.
 */
function LeaveTypeFields({
    form,
    colors,
    idPrefix,
    codeLocked = false,
}: {
    form: InertiaFormProps<LeaveTypeForm>;
    colors: ChipColorOption[];
    idPrefix: string;
    codeLocked?: boolean;
}) {
    const { data, setData, errors } = form;

    return (
        <div className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-[8.5rem_1fr]">
                <div className="grid content-start gap-1.5">
                    <Label htmlFor={`${idPrefix}-code`}>Code</Label>
                    <Input
                        id={`${idPrefix}-code`}
                        value={data.code}
                        onChange={(event) => setData('code', event.target.value.toUpperCase().replace(/\s/g, ''))}
                        placeholder="SBL"
                        autoComplete="off"
                        spellCheck={false}
                        maxLength={10}
                        readOnly={codeLocked}
                        aria-readonly={codeLocked}
                        aria-describedby={codeLocked ? `${idPrefix}-code-locked` : undefined}
                        className={cn('font-mono font-semibold tracking-wide uppercase', codeLocked && 'bg-muted text-muted-foreground')}
                        required
                    />
                    {codeLocked && (
                        <p id={`${idPrefix}-code-locked`} className="text-muted-foreground flex items-center gap-1 text-xs">
                            <Lock aria-hidden="true" className="size-3" />
                            Locked: already on records
                        </p>
                    )}
                    <InputError message={errors.code} />
                </div>
                <div className="grid content-start gap-1.5">
                    <Label htmlFor={`${idPrefix}-name`}>Name</Label>
                    <Input
                        id={`${idPrefix}-name`}
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        placeholder="Special Birthday Leave"
                        autoComplete="off"
                        maxLength={100}
                        required
                    />
                    <InputError message={errors.name} />
                </div>
            </div>

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm leading-none font-medium">Chip colour</legend>
                <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
                    <div role="radiogroup" aria-label="Chip colour" className="flex flex-wrap gap-2">
                        {colors.map((color) => (
                            <label key={color.value} className="relative cursor-pointer" title={color.label}>
                                <input
                                    type="radio"
                                    name={`${idPrefix}-color`}
                                    value={color.value}
                                    checked={data.color === color.value}
                                    onChange={() => setData('color', color.value)}
                                    className="peer sr-only"
                                />
                                <span className="sr-only">{color.label}</span>
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'flex size-8 items-center justify-center rounded-full ring-1 ring-current/25 transition-transform ring-inset',
                                        'peer-focus-visible:outline-ring peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2',
                                        'peer-checked:outline-foreground peer-checked:outline-2 peer-checked:outline-offset-2',
                                        'hover:scale-105 active:scale-95',
                                        chipTokens(color.value),
                                    )}
                                >
                                    {data.color === color.value && <Check className="size-4" strokeWidth={2.5} />}
                                </span>
                            </label>
                        ))}
                    </div>

                    <div className="flex items-center gap-2" aria-live="polite">
                        <span className="text-muted-foreground text-xs">On the sheet:</span>
                        <LeaveChip color={data.color} code={data.code || 'CODE'} figures="23–25" className="text-xs" />
                    </div>
                </div>
                <InputError message={errors.color} />
            </fieldset>
        </div>
    );
}

export default function LeaveTypes({ leaveTypes, colors, can }: LeaveTypesPageProps) {
    const reduceMotion = useReducedMotion();
    const [editing, setEditing] = useState<LeaveTypeRow | null>(null);
    const [deleting, setDeleting] = useState<LeaveTypeRow | null>(null);
    const [busy, setBusy] = useState(false);

    const addForm = useForm<LeaveTypeForm>({ code: '', name: '', color: colors[0]?.value ?? 'sky' });
    const editForm = useForm<LeaveTypeForm>({ code: '', name: '', color: '' });

    const retiredCount = leaveTypes.filter((type) => !type.isActive).length;

    const add: FormEventHandler = (event) => {
        event.preventDefault();

        addForm.post(route('leave-types.store'), {
            preserveScroll: true,
            onSuccess: () => addForm.reset(),
        });
    };

    const startEditing = (type: LeaveTypeRow) => {
        setEditing(type);
        editForm.setData({ code: type.code, name: type.name, color: type.color });
        editForm.clearErrors();
    };

    const saveEdit: FormEventHandler = (event) => {
        event.preventDefault();

        if (!editing) {
            return;
        }

        editForm.patch(route('leave-types.update', editing.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    /**
     * Sends the whole order as it will look, not "move this one": the server
     * checks every type is named once, so a stale page cannot scramble it.
     */
    const move = (index: number, offset: -1 | 1) => {
        const target = index + offset;

        if (target < 0 || target >= leaveTypes.length) {
            return;
        }

        const ids = leaveTypes.map((type) => type.id);
        [ids[index], ids[target]] = [ids[target], ids[index]];

        router.put(
            route('leave-types.reorder'),
            { ids },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    };

    const setActive = (type: LeaveTypeRow, active: boolean) => {
        router.patch(
            route(active ? 'leave-types.restore' : 'leave-types.retire', type.id),
            {},
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
    };

    const confirmDelete = () => {
        if (!deleting) {
            return;
        }

        router.delete(route('leave-types.destroy', deleting.id), {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setDeleting(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave types" />

            <PageHeader
                eyebrow="Administration"
                title="Leave types"
                description="The codes written on the tally sheet. This order is the order of the encoding list and of the totals columns on the tally."
            />

            {can.manage && (
                <form onSubmit={add} className="bg-card rounded-lg border p-4 sm:p-5" aria-labelledby="add-leave-type">
                    <div className="form-rule mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 pb-3">
                        <h2 id="add-leave-type" className="font-display text-base font-semibold tracking-[-0.01em]">
                            Add a leave type
                        </h2>
                        <p className="text-muted-foreground text-xs">It goes to the end of the list. Move it up after.</p>
                    </div>
                    <LeaveTypeFields form={addForm} colors={colors} idPrefix="add" />
                    <div className="mt-5 flex justify-end">
                        <Button type="submit" disabled={addForm.processing} className="w-full sm:w-auto">
                            {addForm.processing ? <Loader2 className="animate-spin" aria-hidden="true" /> : <Plus aria-hidden="true" />}
                            Add leave type
                        </Button>
                    </div>
                </form>
            )}

            <section className="bg-card overflow-hidden rounded-lg border" aria-labelledby="leave-type-list">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b px-4 py-3">
                    <h2 id="leave-type-list" className="text-sm font-semibold">
                        <span data-numeric>{leaveTypes.length}</span> leave {leaveTypes.length === 1 ? 'type' : 'types'}
                        {retiredCount > 0 && (
                            <span className="text-muted-foreground font-normal">
                                {' '}
                                · <span data-numeric>{retiredCount}</span> retired
                            </span>
                        )}
                    </h2>
                    {can.manage && <p className="text-muted-foreground text-xs">A code on records is retired, never deleted.</p>}
                </div>

                {leaveTypes.length === 0 ? (
                    <EmptyState
                        icon={Tags}
                        title="No leave types yet"
                        description={
                            can.manage
                                ? 'Add the codes HR writes on the tally sheet, such as VL for vacation leave and SL for sick leave.'
                                : 'An HR Admin adds the leave codes. Ask them to set these up before encoding leave.'
                        }
                    />
                ) : (
                    <ol className="divide-y">
                        {leaveTypes.map((type, index) => (
                            <motion.li
                                key={type.id}
                                // The one moving part: a row moved up or down
                                // slides to its new place, so HR sees what the
                                // click changed.
                                layout={reduceMotion ? false : 'position'}
                                initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={
                                    reduceMotion
                                        ? { duration: 0 }
                                        : {
                                              layout: { type: 'spring', stiffness: 380, damping: 32 },
                                              default: { duration: 0.2, delay: Math.min(index, 10) * 0.03, ease: EASE },
                                          }
                                }
                                className="bg-card relative px-4 py-3"
                            >
                                {editing?.id === type.id ? (
                                    <form onSubmit={saveEdit} className="py-1" aria-label={`Edit ${type.code}`}>
                                        <LeaveTypeFields form={editForm} colors={colors} idPrefix={`edit-${type.id}`} codeLocked={type.inUse} />
                                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                                            <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(null)}>
                                                <X aria-hidden="true" />
                                                Cancel
                                            </Button>
                                            <Button type="submit" size="sm" disabled={editForm.processing}>
                                                {editForm.processing ? (
                                                    <Loader2 className="animate-spin" aria-hidden="true" />
                                                ) : (
                                                    <Check aria-hidden="true" />
                                                )}
                                                Save {type.code}
                                            </Button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                        <div className="flex min-w-0 flex-1 items-center gap-3">
                                            <span className="text-muted-foreground w-6 shrink-0 text-right font-mono text-xs" data-numeric>
                                                {index + 1}
                                            </span>
                                            <span className="w-16 shrink-0">
                                                <LeaveChip
                                                    color={type.color}
                                                    code={type.code}
                                                    className={cn('text-xs', !type.isActive && 'opacity-55 saturate-50')}
                                                />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span className={cn('font-medium', !type.isActive && 'text-muted-foreground')}>{type.name}</span>
                                                    {!type.isActive && (
                                                        <Badge variant="muted" title="Kept on the tally; not offered when encoding">
                                                            Retired
                                                        </Badge>
                                                    )}
                                                </p>
                                                <p className="text-muted-foreground text-xs">{usage(type)}</p>
                                            </div>
                                        </div>

                                        {can.manage && (
                                            <div className="flex shrink-0 flex-wrap items-center gap-1.5 pl-9 sm:pl-0">
                                                <div className="flex items-center" role="group" aria-label={`Order of ${type.code}`}>
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-9"
                                                        onClick={() => move(index, -1)}
                                                        disabled={busy || index === 0}
                                                        aria-label={`Move ${type.code} up`}
                                                        title="Move up"
                                                    >
                                                        <ArrowUp aria-hidden="true" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-9"
                                                        onClick={() => move(index, 1)}
                                                        disabled={busy || index === leaveTypes.length - 1}
                                                        aria-label={`Move ${type.code} down`}
                                                        title="Move down"
                                                    >
                                                        <ArrowDown aria-hidden="true" />
                                                    </Button>
                                                </div>
                                                <Button type="button" size="sm" variant="outline" onClick={() => startEditing(type)}>
                                                    <Pencil aria-hidden="true" />
                                                    Edit
                                                </Button>
                                                {type.isActive ? (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={busy}
                                                        onClick={() => setActive(type, false)}
                                                        title="Stop offering this code when encoding. Its records stay."
                                                    >
                                                        Retire
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={busy}
                                                        onClick={() => setActive(type, true)}
                                                    >
                                                        <ArchiveRestore aria-hidden="true" />
                                                        Restore
                                                    </Button>
                                                )}
                                                {!type.inUse && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={busy}
                                                        onClick={() => setDeleting(type)}
                                                        className="text-destructive hover:text-destructive"
                                                    >
                                                        <Trash2 aria-hidden="true" />
                                                        Delete
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </motion.li>
                        ))}
                    </ol>
                )}
            </section>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                processing={busy}
                tone="destructive"
                title={`Delete ${deleting?.code ?? 'this leave type'}?`}
                confirmLabel="Delete leave type"
                onConfirm={confirmDelete}
                description={
                    <p>
                        <span className="text-foreground font-medium">
                            {deleting?.code} — {deleting?.name}
                        </span>{' '}
                        has never been used, so no records are affected. It will no longer be offered when encoding.
                    </p>
                }
            />
        </AppLayout>
    );
}
