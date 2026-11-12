import { type CreditCell } from '@/types';

/**
 * What a credits input starts with: the figure on file, or an empty box when
 * there is none. Blank and "0" are different answers, so 0 stays "0".
 */
export function creditInputValue(cell: CreditCell | undefined): string {
    return cell?.credits === null || cell?.credits === undefined ? '' : String(cell.credits);
}

/** "7,5" typed on a comma-decimal keyboard is 7.5; spaces are dropped. */
export function normaliseFigure(text: string): string {
    return text.trim().replace(/^(\d+),(\d+)$/, '$1.$2');
}

/**
 * Whether a typed figure looks like one the server will take: blank, or
 * 0–999 in steps of 0.5. Only a hint for the person typing — the server's
 * rules are the ones that count, and its message is the one shown.
 */
export function looksLikeFigure(text: string): boolean {
    const value = normaliseFigure(text);

    if (value === '') {
        return true;
    }

    if (!/^\d{1,3}(\.\d+)?$/.test(value)) {
        return false;
    }

    return Number.isInteger(Number(value) * 2);
}

/** What a typed value is sent as: null for "no figure", the number text otherwise. */
export function figurePayload(text: string): string | null {
    const value = normaliseFigure(text);

    return value === '' ? null : value;
}
