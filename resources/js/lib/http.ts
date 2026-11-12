/**
 * Small JSON client for the few non-Inertia endpoints (duplicate checks and
 * the AI summary flow). Sends Laravel's XSRF token and supports aborting
 * stale requests.
 */
export class HttpError extends Error {
    constructor(
        public status: number,
        public code: string,
        message: string,
        public details: Record<string, unknown> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string | null {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1] ?? '') : null;
}

export async function fetchJson<T>(
    url: string,
    options: {
        method?: string;
        body?: unknown;
        signal?: AbortSignal;
        headers?: Record<string, string>;
    } = {},
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
    };
    const token = xsrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }
    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        method: options.method ?? (options.body ? 'POST' : 'GET'),
        headers,
        body:
            options.body !== undefined
                ? JSON.stringify(options.body)
                : undefined,
        credentials: 'same-origin',
        signal: options.signal,
    });

    const text = await response.text();
    let data: unknown = null;
    try {
        data = text ? JSON.parse(text) : null;
    } catch {
        data = null;
    }

    if (!response.ok) {
        const payload = (data ?? {}) as {
            error?: {
                code?: string;
                message?: string;
                details?: Record<string, unknown>;
            };
            message?: string;
            errors?: Record<string, string[]>;
        };
        const message =
            payload.error?.message ??
            payload.message ??
            (response.status === 419
                ? 'Your session expired. Reload the page.'
                : `Request failed (${response.status}).`);
        throw new HttpError(
            response.status,
            payload.error?.code ?? `http_${response.status}`,
            message,
            payload.error?.details ??
                (payload.errors ? { fields: payload.errors } : {}),
        );
    }

    return data as T;
}

export function newIdempotencyKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export async function sha256Hex(file: Blob): Promise<string | null> {
    if (typeof crypto === 'undefined' || !crypto.subtle) {
        return null;
    }
    const digest = await crypto.subtle.digest(
        'SHA-256',
        await file.arrayBuffer(),
    );

    return Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}
