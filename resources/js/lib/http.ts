/** The XSRF cookie Laravel sets, sent back as a header on a fetch() that Inertia does not make. */
export function xsrf(): string {
    const hit = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return hit ? decodeURIComponent(hit.split('=')[1]) : '';
}

/** POST JSON to the panel and read JSON back (a live preview, say). */
export function postJson(url: string, body: unknown): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
    });
}
