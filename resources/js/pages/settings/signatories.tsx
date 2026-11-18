import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, type InertiaFormProps, router, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Loader2, Pencil, Plus, Signature, Trash2, X } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { type FormEventHandler, useState } from 'react';

interface SignatoryRow {
    id: number;
    label: string;
    name: string;
    position: string;
}

interface SignatoriesPageProps {
    signatories: SignatoryRow[];
    can: { manage: boolean };
}

// A type alias, not an interface: Inertia's form data needs an index signature.
type SignatoryForm = {
    label: string;
    name: string;
    position: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Signatories', href: '/settings/signatories' }];

const EASE = [0.22, 1, 0.36, 1] as const;

/** Line, name and position, shared by the add form and the inline edit. */
function SignatoryFields({ form, idPrefix }: { form: InertiaFormProps<SignatoryForm>; idPrefix: string }) {
    const { data, setData, errors } = form;

    return (
        <div className="grid gap-4 sm:grid-cols-[11rem_1fr_1fr]">
            <div className="grid content-start gap-1.5">
                <Label htmlFor={`${idPrefix}-label`}>Signs under</Label>
                <Input
                    id={`${idPrefix}-label`}
                    value={data.label}
                    onChange={(event) => setData('label', event.target.value)}
                    placeholder="Prepared by"
                    list="signatory-lines"
                    autoComplete="off"
                    maxLength={60}
                    required
                />
                <InputError message={errors.label} />
            </div>
            <div className="grid content-start gap-1.5">
                <Label htmlFor={`${idPrefix}-name`}>Full name</Label>
                <Input
                    id={`${idPrefix}-name`}
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    placeholder="Ma. Lourdes D. Reyes"
                    autoComplete="off"
                    maxLength={150}
                    required
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid content-start gap-1.5">
                <Label htmlFor={`${idPrefix}-position`}>Position</Label>
                <Input
                    id={`${idPrefix}-position`}
                    value={data.position}
                    onChange={(event) => setData('position', event.target.value)}
                    placeholder="City Human Resource Management Officer"
                    autoComplete="off"
                    maxLength={150}
                    required
                />
                <InputError message={errors.position} />
            </div>
        </div>
    );
}

/**
 * The signature row as the PDF prints it: the line they sign under, a
 * signature line, the name in capitals and the position. The page is built
 * around this preview, so HR sees the paper before printing it.
 */
function SignaturePreview({ signatories }: { signatories: SignatoryForm[] }) {
    return (
        <figure className="bg-background rounded-md border p-5">
            <figcaption className="text-muted-foreground mb-4 text-xs font-semibold tracking-wider uppercase">On the printed sheet</figcaption>
            <div className="flex flex-wrap gap-x-10 gap-y-6">
                {signatories.map((signatory, index) => (
                    <div key={index} className="w-56 text-sm">
                        <p className="mb-7">{signatory.label || 'Prepared by'}:</p>
                        <p className="border-foreground border-t pt-1 font-semibold uppercase">{signatory.name || 'Full name'}</p>
                        <p className="text-muted-foreground text-xs">{signatory.position || 'Position'}</p>
                    </div>
                ))}
            </div>
        </figure>
    );
}

export default function Signatories({ signatories, can }: SignatoriesPageProps) {
    const reduceMotion = useReducedMotion();
    const [editing, setEditing] = useState<SignatoryRow | null>(null);
    const [removing, setRemoving] = useState<SignatoryRow | null>(null);
    const [busy, setBusy] = useState(false);

    const addForm = useForm<SignatoryForm>({ label: '', name: '', position: '' });
    const editForm = useForm<SignatoryForm>({ label: '', name: '', position: '' });

    // The preview shows the edit as it is typed, and a new one at the end.
    const previewed: SignatoryForm[] = [
        ...signatories.map((signatory) => (editing?.id === signatory.id ? editForm.data : signatory)),
        ...(addForm.data.name || addForm.data.label || addForm.data.position ? [addForm.data] : []),
    ];

    const add: FormEventHandler = (event) => {
        event.preventDefault();

        addForm.post(route('signatories.store'), { preserveScroll: true, onSuccess: () => addForm.reset() });
    };

    const startEditing = (signatory: SignatoryRow) => {
        setEditing(signatory);
        editForm.setData({ label: signatory.label, name: signatory.name, position: signatory.position });
        editForm.clearErrors();
    };

    const saveEdit: FormEventHandler = (event) => {
        event.preventDefault();

        if (editing) {
            editForm.patch(route('signatories.update', editing.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
        }
    };

    const move = (signatory: SignatoryRow, direction: 'left' | 'right') => {
        router.patch(
            route('signatories.move', signatory.id),
            { direction },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
    };

    const confirmRemove = () => {
        if (!removing) {
            return;
        }

        router.delete(route('signatories.destroy', removing.id), {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setRemoving(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Signatories" />

            <PageHeader
                eyebrow="Administration"
                title="Signatories"
                description="The people who sign the printed leave tally sheet, left to right. Every office's sheet ends with this row."
            />

            {/* Suggestions for the usual lines on a government form; any text is allowed. */}
            <datalist id="signatory-lines">
                <option value="Prepared by" />
                <option value="Checked by" />
                <option value="Certified correct by" />
                <option value="Noted by" />
                <option value="Approved by" />
            </datalist>

            {can.manage && (
                <form onSubmit={add} className="bg-card rounded-lg border p-4 sm:p-5" aria-labelledby="add-signatory">
                    <div className="form-rule mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 pb-3">
                        <h2 id="add-signatory" className="font-display text-base font-semibold tracking-[-0.01em]">
                            Add a signatory
                        </h2>
                        <p className="text-muted-foreground text-xs">They go at the right end of the row. Move them after.</p>
                    </div>
                    <SignatoryFields form={addForm} idPrefix="add" />
                    <div className="mt-5 flex justify-end">
                        <Button type="submit" disabled={addForm.processing} className="w-full sm:w-auto">
                            {addForm.processing ? <Loader2 className="animate-spin" aria-hidden="true" /> : <Plus aria-hidden="true" />}
                            Add signatory
                        </Button>
                    </div>
                </form>
            )}

            {/* Below the form, so the form never moves while someone types into it. */}
            {previewed.length > 0 ? (
                <SignaturePreview signatories={previewed} />
            ) : (
                <section className="bg-card rounded-lg border">
                    <EmptyState
                        icon={Signature}
                        title="No signatories yet"
                        description={
                            can.manage
                                ? 'The printed sheet has no signature row until you add the people who sign it, for example Prepared by and Certified correct by.'
                                : 'An HR Admin adds the people who sign the printed sheet. Until then it prints without a signature row.'
                        }
                    />
                </section>
            )}

            {signatories.length > 0 && (
                <section className="bg-card overflow-hidden rounded-lg border" aria-label="Signatories, left to right">
                    <ol className="divide-y">
                        {signatories.map((signatory, index) => (
                            <motion.li
                                key={signatory.id}
                                layout={reduceMotion ? false : 'position'}
                                initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={
                                    reduceMotion
                                        ? { duration: 0 }
                                        : { layout: { type: 'spring', stiffness: 380, damping: 32 }, default: { duration: 0.2, ease: EASE } }
                                }
                                className="bg-card px-4 py-3"
                            >
                                {editing?.id === signatory.id ? (
                                    <form onSubmit={saveEdit} className="py-1" aria-label={`Edit ${signatory.name}`}>
                                        <SignatoryFields form={editForm} idPrefix={`edit-${signatory.id}`} />
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
                                                Save changes
                                            </Button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                        <div className="flex min-w-0 flex-1 items-baseline gap-3">
                                            <span className="text-muted-foreground w-5 shrink-0 text-right font-mono text-xs" data-numeric>
                                                {index + 1}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="font-medium">{signatory.name}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {signatory.label} · {signatory.position}
                                                </p>
                                            </div>
                                        </div>
                                        {can.manage && (
                                            <div className="flex shrink-0 items-center justify-between gap-1.5 sm:justify-start">
                                                <div className="flex items-center" role="group" aria-label={`Place of ${signatory.name}`}>
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-9"
                                                        disabled={busy || index === 0}
                                                        onClick={() => move(signatory, 'left')}
                                                        aria-label={`Move ${signatory.name} left`}
                                                        title="Move left"
                                                    >
                                                        <ArrowLeft aria-hidden="true" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-9"
                                                        disabled={busy || index === signatories.length - 1}
                                                        onClick={() => move(signatory, 'right')}
                                                        aria-label={`Move ${signatory.name} right`}
                                                        title="Move right"
                                                    >
                                                        <ArrowRight aria-hidden="true" />
                                                    </Button>
                                                </div>
                                                <div className="flex items-center gap-1.5">
                                                    <Button type="button" size="sm" variant="outline" onClick={() => startEditing(signatory)}>
                                                        <Pencil aria-hidden="true" />
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={busy}
                                                        onClick={() => setRemoving(signatory)}
                                                        className="text-destructive hover:text-destructive"
                                                    >
                                                        <Trash2 aria-hidden="true" />
                                                        Remove
                                                    </Button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </motion.li>
                        ))}
                    </ol>
                </section>
            )}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                processing={busy}
                tone="destructive"
                title={`Remove ${removing?.name ?? 'this signatory'}?`}
                confirmLabel="Remove signatory"
                onConfirm={confirmRemove}
                description={<p>They will no longer print on the tally sheet. Sheets already printed do not change.</p>}
            />
        </AppLayout>
    );
}
