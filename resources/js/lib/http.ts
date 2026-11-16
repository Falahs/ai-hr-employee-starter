/**
 * JSON POST for the few calls that return data instead of a page (connection tests).
 * Laravel's CSRF check reads the XSRF-TOKEN cookie back from the X-XSRF-TOKEN header.
 */
export async function postJson<T>(url: string, body: unknown): Promise<{ ok: boolean; status: number; data: T }> {
    const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
        body: JSON.stringify(body),
    });
    const data = (await res.json().catch(() => ({}))) as T;
    return { ok: res.ok, status: res.status, data };
}
