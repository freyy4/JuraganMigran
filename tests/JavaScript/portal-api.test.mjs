import test from 'node:test';
import assert from 'node:assert/strict';
import { actionStatuses, dated, portalLink, request } from '../../resources/js/portal/api.js';

test('document actions include correction and exclude future requirements', () => {
    assert.ok(actionStatuses.includes('needs_correction'));
    assert.ok(!actionStatuses.includes('not_required_yet'));
});

test('notification links cannot send a CPMI to another origin or script URL', () => {
    for (const value of ['//evil.test/portal', '/portal//evil.test', 'javascript:alert(1)', 'https://evil.test/portal', '/portal/\\evil.test', null]) assert.equal(portalLink(value), null);
    assert.equal(portalLink('/portal/documents'), '/portal/documents');
});

test('schedule timestamps use Jakarta time', () => {
    assert.match(dated('2026-10-12 01:00:00'), /08[.:]00/);
    assert.equal(dated(null), 'Belum tersedia');
});

test('mutations use same-origin session and CSRF token with safe server validation errors', async () => {
    globalThis.document = { querySelector: () => ({ content: 'test-csrf' }) };
    const originalFetch = globalThis.fetch;
    try {
        globalThis.fetch = async (url, options) => {
            assert.equal(options.credentials, 'same-origin');
            assert.equal(options.cache, 'no-store');
            assert.equal(options.headers['X-CSRF-TOKEN'], 'test-csrf');
            assert.equal(options.headers.Accept, 'application/json');
            assert.deepEqual(JSON.parse(options.body), { description: 'Koreksi data' });
            return { ok: false, status: 422, json: async () => ({ errors: { description: ['Jelaskan kebutuhan Anda.'] } }) };
        };
        await assert.rejects(request('/portal/requests', { method: 'POST', body: { description: 'Koreksi data' } }), { message: 'Jelaskan kebutuhan Anda.', status: 422 });
    } finally { globalThis.fetch = originalFetch; }
});

test('expired sessions are explicit errors rather than a false successful HTML redirect', async () => {
    const originalFetch = globalThis.fetch;
    try {
        globalThis.fetch = async () => ({ ok: false, status: 401, json: async () => ({ message: 'Sesi Anda berakhir.' }) });
        await assert.rejects(request('/api/portal/me/summary'), { message: 'Sesi Anda berakhir.', status: 401 });
    } finally { globalThis.fetch = originalFetch; }
});
