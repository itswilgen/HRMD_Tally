import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type OfficeRow } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Building2, Check, Loader2, Pencil, Plus, Trash2, Users, X } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { type FormEventHandler, useState } from 'react';

interface OfficesPageProps {
    offices: OfficeRow[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Offices', href: '/settings/offices' }];

export default function Offices({ offices, can }: OfficesPageProps) {
    const reduceMotion = useReducedMotion();
    const [renaming, setRenaming] = useState<OfficeRow | null>(null);
    const [removing, setRemoving] = useState<OfficeRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const addForm = useForm<{ name: string }>({ name: '' });
    const renameForm = useForm<{ name: string }>({ name: '' });

    const add: FormEventHandler = (event) => {
        event.preventDefault();

        addForm.post(route('offices.store'), {
            preserveScroll: true,
            onSuccess: () => addForm.reset(),
        });
    };

    const startRenaming = (office: OfficeRow) => {
        setRenaming(office);
        renameForm.setData('name', office.name);
        renameForm.clearErrors();
    };

    const saveRename: FormEventHandler = (event) => {
        event.preventDefault();

        if (!renaming) {
            return;
        }

        renameForm.patch(route('offices.update', renaming.id), {
            preserveScroll: true,
            onSuccess: () => setRenaming(null),
        });
    };

    const confirmRemove = () => {
        if (!removing) {
            return;
        }

        router.delete(route('offices.destroy', removing.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemoving(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Offices" />

            <PageHeader
                eyebrow="Administration"
                title="Offices"
                description="Every employee reports to one office. The yearly tally sheet is printed per office, so these names are the ones that appear at the top of the sheet."
            />

            {can.manage && (
                <form onSubmit={add} className="bg-card rounded-xl border p-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="grid flex-1 gap-1.5">
                            <Label htmlFor="name">Add an office</Label>
                            <Input
                                id="name"
                                value={addForm.data.name}
                                onChange={(event) => addForm.setData('name', event.target.value)}
                                placeholder="Office of the City Treasurer"
                                autoComplete="off"
                                maxLength={150}
                                required
                            />
                        </div>
                        <Button type="submit" disabled={addForm.processing} className="sm:mb-px">
                            {addForm.processing ? <Loader2 className="animate-spin" aria-hidden="true" /> : <Plus aria-hidden="true" />}
                            Add office
                        </Button>
                    </div>
                    <InputError message={addForm.errors.name} className="mt-2" />
                </form>
            )}

            <section className="bg-card overflow-hidden rounded-xl border">
                {offices.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No offices yet"
                        description={
                            can.manage
                                ? 'Add each office the HRMD keeps leave records for. You can rename one later without affecting the records.'
                                : 'An HR Admin adds the offices. Ask them to set these up before encoding leave.'
                        }
                    />
                ) : (
                    <ul className="divide-y">
                        {offices.map((office, index) => (
                            <motion.li
                                key={office.id}
                                initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={
                                    reduceMotion ? { duration: 0 } : { duration: 0.2, delay: Math.min(index, 10) * 0.03, ease: [0.22, 1, 0.36, 1] }
                                }
                                className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                {renaming?.id === office.id ? (
                                    <form onSubmit={saveRename} className="flex w-full flex-col gap-2 sm:flex-row sm:items-start">
                                        <div className="min-w-0 flex-1">
                                            <Input
                                                value={renameForm.data.name}
                                                onChange={(event) => renameForm.setData('name', event.target.value)}
                                                aria-label={`New name for ${office.name}`}
                                                autoComplete="off"
                                                maxLength={150}
                                                autoFocus
                                                required
                                            />
                                            <InputError message={renameForm.errors.name} className="mt-1.5" />
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Button type="submit" size="sm" disabled={renameForm.processing}>
                                                {renameForm.processing ? (
                                                    <Loader2 className="animate-spin" aria-hidden="true" />
                                                ) : (
                                                    <Check aria-hidden="true" />
                                                )}
                                                Save
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setRenaming(null)}
                                                className="cursor-pointer"
                                            >
                                                <X aria-hidden="true" />
                                                Cancel
                                            </Button>
                                        </div>
                                    </form>
                                ) : (
                                    <>
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span
                                                aria-hidden="true"
                                                className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-md"
                                            >
                                                <Building2 className="size-4" />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{office.name}</p>
                                                {office.employeesCount === 0 ? (
                                                    <p className="text-muted-foreground text-xs">Nobody assigned yet</p>
                                                ) : (
                                                    <Link
                                                        href={route('employees.index', { office: office.id })}
                                                        className="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex items-center gap-1 rounded-sm text-xs underline-offset-4 transition-colors hover:underline focus-visible:ring-2 focus-visible:outline-none"
                                                    >
                                                        <Users aria-hidden="true" className="size-3" />
                                                        <span data-numeric>{office.employeesCount}</span>
                                                        {office.employeesCount === 1 ? ' employee' : ' employees'}
                                                    </Link>
                                                )}
                                            </div>
                                        </div>

                                        {can.manage && (
                                            <div className="flex shrink-0 items-center gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => startRenaming(office)}
                                                    className="cursor-pointer"
                                                >
                                                    <Pencil aria-hidden="true" />
                                                    Rename
                                                </Button>
                                                {/*
                                                  An office people are still
                                                  assigned to cannot go: the
                                                  button says why instead of
                                                  disappearing or erroring.
                                                */}
                                                {office.employeesCount > 0 ? (
                                                    <Badge variant="muted" title="Move these employees to another office first">
                                                        In use
                                                    </Badge>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => setRemoving(office)}
                                                        className="text-destructive hover:text-destructive cursor-pointer"
                                                    >
                                                        <Trash2 aria-hidden="true" />
                                                        Remove
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}
                            </motion.li>
                        ))}
                    </ul>
                )}
            </section>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                processing={processing}
                tone="destructive"
                title="Remove this office?"
                confirmLabel="Remove office"
                onConfirm={confirmRemove}
                description={
                    <p>
                        <span className="text-foreground font-medium">{removing?.name}</span> will no longer be offered when adding an employee.
                        Nobody is assigned to it, so no records are affected.
                    </p>
                }
            />
        </AppLayout>
    );
}
