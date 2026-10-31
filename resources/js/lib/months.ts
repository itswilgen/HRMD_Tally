/**
 * The twelve columns of the sheet, in the paper form's abbreviations.
 *
 * One list, used by the table header, the month cells and the Add Leave
 * preview, so a date reads the same everywhere on the screen.
 */
export const MONTH_LABELS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'] as const;

/** 1–12 to its column label. */
export function monthLabel(month: number): string {
    return MONTH_LABELS[month - 1];
}
