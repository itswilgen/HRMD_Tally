/**
 * Whether a nav destination is the page you are on. A section stays lit while
 * you are inside it, so "User accounts" stays marked on its create and edit
 * screens, and query strings (filters, page number) are ignored.
 */
export function isActiveUrl(currentUrl: string, itemUrl: string): boolean {
    const current = currentUrl.split('?')[0].replace(/\/+$/, '') || '/';
    const target = itemUrl.split('?')[0].replace(/\/+$/, '') || '/';

    return current === target || current.startsWith(`${target}/`);
}
