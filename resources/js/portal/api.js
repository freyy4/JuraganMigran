export const firstError = (payload, fallback = 'Permintaan belum berhasil. Silakan coba lagi.') => Object.values(payload?.errors || {}).flat()[0] || payload?.message || fallback;

export async function request(url, { method = 'GET', body, signal } = {}) {
    const response = await fetch(url, {
        method, credentials: 'same-origin', cache: 'no-store', signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            ...(method !== 'GET' ? { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Content-Type': 'application/json' } : {}) },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const payload = await response.json().catch(() => ({}));
    if (response.status === 403 && payload.code === 'REAUTH_REQUIRED') {
        const next = new URL(payload.next, window.location.origin);
        if (next.origin === window.location.origin && next.pathname === '/auth/confirm') window.location.assign(next.href);
    }
    if (!response.ok || response.redirected) {
        const error = new Error(response.status === 419 ? 'Sesi formulir kedaluwarsa. Muat ulang halaman sebelum mengirim kembali.' : response.status === 429 ? 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.' : firstError(payload));
        error.status = response.status;
        throw error;
    }
    return payload;
}

export const actionStatuses = ['missing', 'needs_correction', 'rejected', 'expired'];
export const uuid = () => crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
export const dated = value => {
    if (!value) return 'Belum tersedia';
    const date = new Date(value.includes('T') || /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : value.replace(' ', 'T') + 'Z');
    return Number.isNaN(date.valueOf()) ? 'Belum tersedia' : new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(date) + ' WIB';
};
export const portalLink = value => typeof value === 'string' && (value === '/portal' || /^\/portal\/(?![\/\\])/.test(value)) && !value.includes('\\') ? value : null;
