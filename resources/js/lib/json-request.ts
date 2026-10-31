/**
 * The one question this app asks outside a page visit: "what would saving this
 * leave range actually do?".
 *
 * It is a POST because it carries a payload and must not be cached, and it
 * returns JSON rather than an Inertia page because the answer belongs to the
 * form the encoder is still filling in, not to a new screen.
 */
export class JsonRequestError extends Error {
    constructor(public readonly status: number) {
        super(`Request failed with status ${status}`);
        this.name = 'JsonRequestError';
    }
}

export async function postJson<T>(url: string, body: Record<string, unknown>, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new JsonRequestError(response.status);
    }

    return (await response.json()) as T;
}

/**
 * Laravel sets the XSRF-TOKEN cookie on every response and accepts it back in
 * this header, which is how a plain fetch keeps CSRF protection instead of
 * routing around it.
 */
function csrfHeaders(): Record<string, string> {
    const prefix = 'XSRF-TOKEN=';
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith(prefix));

    return cookie === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(prefix.length)) };
}
