import { cn } from '@/lib/utils';
import { motion, useReducedMotion } from 'motion/react';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

interface LetterTabsProps {
    /** Initial letters that actually have employees behind them. */
    available: string[];
    /** The selected letter, or '' for the whole list. */
    value: string;
    onChange: (letter: string) => void;
}

/**
 * The A-Z index down the side of a paper ledger, laid flat.
 *
 * HR looks people up by surname, and twenty-six taps is faster than typing for
 * someone working from a stack of forms. A letter nobody's surname starts with
 * is disabled rather than hidden: every letter keeps its place, so where to
 * reach is learnable, and no letter is ever scrolled out of sight. The row
 * wraps on a phone rather than scrolling sideways for the same reason.
 */
export function LetterTabs({ available, value, onChange }: LetterTabsProps) {
    const reduceMotion = useReducedMotion();
    const inUse = new Set(available);

    return (
        <div
            className="border-border/50 bg-muted/20 flex flex-wrap items-center gap-1 rounded-xl border p-1"
            role="group"
            aria-label="Filter by first letter of surname"
        >
            <Tab label="All" title="Show every employee" selected={value === ''} onSelect={() => onChange('')} reduceMotion={reduceMotion} wide />

            {ALPHABET.map((letter) => {
                const enabled = inUse.has(letter);

                return (
                    <Tab
                        key={letter}
                        label={letter}
                        title={enabled ? `Surnames starting with ${letter}` : `No surname starts with ${letter}`}
                        selected={value === letter}
                        disabled={!enabled}
                        onSelect={() => onChange(letter)}
                        reduceMotion={reduceMotion}
                    />
                );
            })}
        </div>
    );
}

interface TabProps {
    label: string;
    title: string;
    selected: boolean;
    disabled?: boolean;
    wide?: boolean;
    reduceMotion: boolean | null;
    onSelect: () => void;
}

function Tab({ label, title, selected, disabled = false, wide = false, reduceMotion, onSelect }: TabProps) {
    return (
        <button
            type="button"
            title={title}
            aria-pressed={selected}
            disabled={disabled}
            onClick={onSelect}
            className={cn(
                // Finger-sized on a phone, where this is tapped, and tightened
                // on a pointer screen, where it is clicked and a 40px grid of
                // letters would shout louder than the names beside it.
                'focus-visible:ring-ring relative grid h-10 place-items-center rounded-md text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:outline-none sm:h-8',
                wide ? 'px-3 sm:px-2.5' : 'w-10 sm:w-8',
                selected ? 'text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                disabled && 'text-muted-foreground/35 pointer-events-none',
            )}
        >
            {/*
              One shared element slides between letters instead of twenty-six
              separate highlights fading in and out, so the eye can follow where
              the selection went.
            */}
            {selected && (
                <motion.span
                    layoutId="letter-tab-selected"
                    aria-hidden="true"
                    className="bg-primary absolute inset-0 rounded-lg shadow-xs"
                    transition={reduceMotion ? { duration: 0 } : { type: 'spring', stiffness: 380, damping: 30 }}
                />
            )}
            <span className="relative">{label}</span>
        </button>
    );
}
