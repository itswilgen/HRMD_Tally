import { Button } from '@/components/ui/button';
import { OctagonAlert, Trash2, X } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';

interface EmployeeSelectionBarProps {
    selectedCount: number;
    /** How many of the ticked rows have leave on file and will be kept. */
    keptCount: number;
    /**
     * Which list the ticks are on. 'delete' is the masterlist, where the action
     * is a recoverable soft delete; 'erase' is the deleted records, where it
     * destroys the row. The wording has to differ, because "Delete" on a list
     * of already deleted records would mean nothing.
     */
    mode?: 'delete' | 'erase';
    onClear: () => void;
    onDelete: () => void;
}

/**
 * What to do with the rows HR has ticked.
 *
 * It is sticky to the bottom of the table card rather than floating over the
 * page or pushing the filters down: the action stays reachable however far down
 * the list you have scrolled, and no row ever moves under the pointer while
 * someone is still ticking boxes.
 */
export function EmployeeSelectionBar({ selectedCount, keptCount, mode = 'delete', onClear, onDelete }: EmployeeSelectionBarProps) {
    const reduceMotion = useReducedMotion();
    const deletableCount = selectedCount - keptCount;
    const erasing = mode === 'erase';

    return (
        <AnimatePresence>
            {selectedCount > 0 && (
                <motion.div
                    initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 10 }}
                    transition={reduceMotion ? { duration: 0 } : { type: 'spring', stiffness: 380, damping: 30 }}
                    // Opaque, not translucent: rows scroll behind this bar, and
                    // UI_SPEC calls for flat surfaces, so the hairline top
                    // border does the separating instead of a blur or a shadow.
                    //
                    // z-8 sits above the table rows but below the two sticky
                    // headers (page title z-9, top bar z-10), so on a short or
                    // zoomed-in viewport this bar slides under them instead of
                    // over them.
                    className="bg-card sticky bottom-0 z-[8] flex flex-wrap items-center gap-x-4 gap-y-3 border-t px-4 py-3"
                >
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium" aria-live="polite">
                            <span data-numeric>{selectedCount}</span>{' '}
                            {erasing ? (selectedCount === 1 ? 'record' : 'records') : selectedCount === 1 ? 'employee' : 'employees'} selected
                        </p>
                        {keptCount > 0 && (
                            <p className="text-muted-foreground mt-0.5 text-xs">
                                <span data-numeric>{keptCount}</span> {keptCount === 1 ? 'has' : 'have'} leave on file and will be kept
                                {deletableCount > 0 && (
                                    <>
                                        {' · '}
                                        <span data-numeric>{deletableCount}</span> can be {erasing ? 'erased' : 'deleted'}
                                    </>
                                )}
                            </p>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="button" variant="ghost" onClick={onClear} className="cursor-pointer">
                            <X aria-hidden="true" />
                            Clear
                        </Button>
                        <Button type="button" variant="destructive" onClick={onDelete} disabled={deletableCount === 0} className="cursor-pointer">
                            {erasing ? <OctagonAlert aria-hidden="true" /> : <Trash2 aria-hidden="true" />}
                            {erasing ? 'Erase' : 'Delete'}
                            {deletableCount > 0 && <span data-numeric> {deletableCount}</span>}
                            {erasing && ' permanently'}
                        </Button>
                    </div>
                </motion.div>
            )}
        </AnimatePresence>
    );
}
