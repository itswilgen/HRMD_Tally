import InputError from '@/components/input-error';
import { type RoleOption, type UserRole } from '@/types';
import { Check, Lock } from 'lucide-react';

interface RoleSelectorProps {
    roles: RoleOption[];
    value: UserRole | '';
    onChange: (role: UserRole) => void;
    error?: string;
    /** When set, the field is locked and this says why. */
    lockedReason?: string | null;
}

/**
 * Cards rather than a dropdown: there are only two roles, and what separates
 * them is the sentence underneath, which a closed select would hide.
 */
export function RoleSelector({ roles, value, onChange, error, lockedReason }: RoleSelectorProps) {
    const locked = typeof lockedReason === 'string' && lockedReason !== '';

    return (
        <fieldset disabled={locked} className="grid gap-1.5">
            <legend className="text-sm leading-none font-medium">Role</legend>
            {locked ? (
                <p className="text-muted-foreground flex items-start gap-1.5 text-xs">
                    <Lock aria-hidden="true" className="mt-px size-3 shrink-0" />
                    {lockedReason}
                </p>
            ) : (
                <p className="text-muted-foreground text-xs">What this person is allowed to do in the system.</p>
            )}

            <div className="mt-1 grid gap-2 sm:grid-cols-2">
                {roles.map((role) => (
                    <label
                        key={role.value}
                        className="has-[input:checked]:border-primary has-[input:checked]:bg-primary/5 has-[input:focus-visible]:ring-ring hover:bg-accent/40 has-[input:checked]:hover:bg-primary/5 relative flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors has-[input:disabled]:cursor-not-allowed has-[input:disabled]:opacity-60 has-[input:focus-visible]:ring-2 has-[input:focus-visible]:ring-offset-2"
                    >
                        <input
                            type="radio"
                            name="role"
                            value={role.value}
                            checked={value === role.value}
                            onChange={() => onChange(role.value)}
                            className="peer sr-only"
                        />
                        <span
                            aria-hidden="true"
                            className="border-input bg-background peer-checked:border-primary mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border"
                        >
                            {value === role.value && <Check className="text-primary size-3" strokeWidth={3} />}
                        </span>
                        <span className="space-y-0.5">
                            <span className="block text-sm font-medium">{role.label}</span>
                            <span className="text-muted-foreground block text-xs">{role.description}</span>
                        </span>
                    </label>
                ))}
            </div>

            <InputError message={error} />
        </fieldset>
    );
}
