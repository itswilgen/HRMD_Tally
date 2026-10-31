import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { type EmployeeRecord } from '@/types';
import { Check, ChevronDown } from 'lucide-react';
import { type KeyboardEvent as ReactKeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';

interface EmployeeComboboxProps {
    id: string;
    /** The employees on the sheet in front of the encoder. */
    employees: EmployeeRecord[];
    value: number | null;
    onChange: (employeeId: number | null) => void;
    invalid?: boolean;
}

/**
 * Pick a person by typing their surname.
 *
 * Hand-built rather than pulled in as a dependency, because it needs to be
 * exactly one thing: a text box that filters a short list and can be driven
 * entirely from the keyboard, which is how this form is used — a stack of
 * paper leave forms, one surname at a time.
 */
export function EmployeeCombobox({ id, employees, value, onChange, invalid = false }: EmployeeComboboxProps) {
    const selected = employees.find((employee) => employee.id === value) ?? null;

    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const container = useRef<HTMLDivElement>(null);

    const matches = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return employees;
        }

        return employees.filter(
            (employee) =>
                employee.listName.toLowerCase().includes(needle) ||
                employee.positionTitle.toLowerCase().includes(needle) ||
                employee.employeeNo.toLowerCase().includes(needle),
        );
    }, [employees, query]);

    // Clicking anywhere else is a dismissal, not a selection: the field keeps
    // whoever was already chosen.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: MouseEvent) => {
            if (container.current !== null && !container.current.contains(event.target as Node)) {
                setOpen(false);
                setQuery('');
            }
        };

        document.addEventListener('mousedown', onPointerDown);

        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    const choose = (employee: EmployeeRecord) => {
        onChange(employee.id);
        setQuery('');
        setOpen(false);
    };

    const onKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (!open) {
                setOpen(true);
                setActive(0);

                return;
            }

            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActive((current) => Math.min(Math.max(current + step, 0), matches.length - 1));

            return;
        }

        if (event.key === 'Enter' && open) {
            const choice = matches[active];

            if (choice !== undefined) {
                event.preventDefault();
                choose(choice);
            }

            return;
        }

        if (event.key === 'Escape' && open) {
            event.preventDefault();
            setOpen(false);
            setQuery('');
        }
    };

    const listId = `${id}-options`;

    return (
        <div ref={container} className="relative">
            <Input
                id={id}
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={open && matches[active] !== undefined ? `${id}-option-${matches[active].id}` : undefined}
                aria-invalid={invalid}
                autoComplete="off"
                placeholder="Type a surname"
                value={open ? query : (selected?.listName ?? '')}
                // Opened by a click, by typing or by ArrowDown — not by focus
                // alone. The panel autofocuses this field, and a list that
                // unfurled over the dates every time it opened would hide the
                // shape of the form before the encoder had read it.
                onClick={() => {
                    setOpen(true);
                    setActive(0);
                }}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setOpen(true);
                    setActive(0);
                }}
                onKeyDown={onKeyDown}
                className="h-11 pr-9"
            />

            <ChevronDown aria-hidden="true" className="text-muted-foreground pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2" />

            {open && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-label="Employees on this sheet"
                    className="bg-popover absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md border p-1 shadow-md"
                >
                    {matches.length === 0 && (
                        <li className="text-muted-foreground px-2 py-2 text-xs">
                            Nobody on this page matches. Search for them in the sheet first, then use the + in their row.
                        </li>
                    )}

                    {matches.map((employee, index) => (
                        <li key={employee.id} id={`${id}-option-${employee.id}`} role="option" aria-selected={employee.id === value}>
                            <button
                                type="button"
                                // Pointer down, not click: the input's blur
                                // would otherwise close the list first.
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    choose(employee);
                                }}
                                onMouseEnter={() => setActive(index)}
                                className={cn(
                                    'flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
                                    index === active && 'bg-accent text-accent-foreground',
                                )}
                            >
                                <Check className={cn('size-3.5 shrink-0', employee.id === value ? 'opacity-100' : 'opacity-0')} aria-hidden="true" />
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">{employee.listName}</span>
                                    <span className="text-muted-foreground block truncate text-xs">{employee.positionTitle}</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
