/**
 * The one question this app asks outside a page visit: "what would saving this
 * leave range actually do?".
 *
 * It is a POST because it carries a payload and must not be cached, and it
 * returns JSON rather than an Inertia page because the answer belongs to the
 * form the encoder is still filling in, not to a new screen.
 */
export class JsonRequestError extends Error {
    constructor(
        public readonly status: number,
        /** Laravel's validation messages by field, when the status is 422. */
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(`Request failed with status ${status}`);
        this.name = 'JsonRequestError';
    }
}

/**
 * The same kind of question, asked about a file: "what would importing this
 * masterlist add?". Sent as multipart form data, which is the only way a file
 * travels in a fetch body.
 */
export async function postFile<T>(url: string, fields: Record<string, Blob | string>, signal?: AbortSignal): Promise<T> {
    const body = new FormData();

    for (const [name, value] of Object.entries(fields)) {
        body.append(name, value);
    }

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        // No Content-Type: the browser sets it, with the boundary FormData needs.
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
        body,
    });

    if (!response.ok) {
        const json: unknown = response.status === 422 ? await response.json().catch(() => null) : null;
        const errors = typeof json === 'object' && json !== null && 'errors' in json ? (json.errors as Record<string, string[]>) : {};

        throw new JsonRequestError(response.status, errors);
    }

    return (await response.json()) as T;
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
