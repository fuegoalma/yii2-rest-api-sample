// k6 load scenario for the read paths a client actually polls.
//
// Run against a *running* stack — it is not part of `make check`, because a
// number produced on a laptop that is also compiling something is not a number
// anybody should act on:
//
//   make load                       # defaults below
//   make load vus=50 duration=2m
//
// Thresholds are the point. A load test without them prints graphs; with them
// it either passes or tells you which promise it broke, which is the only form
// that belongs anywhere near CI if it is ever moved there.
import http from 'k6/http';
import { check, group } from 'k6';

const BASE = __ENV.BASE_URL || 'http://localhost:8084';

export const options = {
    vus: Number(__ENV.VUS || 10),
    duration: __ENV.DURATION || '30s',
    thresholds: {
        // Reads. The p95 is deliberately the published budget rather than
        // whatever the machine happens to do — a threshold set to current
        // behaviour ratchets silently and never fails.
        'http_req_duration{kind:read}': ['p(95)<300'],
        // Auth is bcrypt-bound by design (see AuthService::burnPasswordHashingTime),
        // so it gets its own budget; holding it to the read budget would either
        // fail honestly or push someone to weaken the hash.
        'http_req_duration{kind:auth}': ['p(95)<1500'],
        // Correctness under load is the part worth failing on: a listing that
        // starts 500ing at 50 VUs is not a latency problem.
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
    },
};

// One account for the whole run, created before the VUs start.
export function setup() {
    const email = `load-${Date.now()}@example.com`;
    const res = http.post(
        `${BASE}/auth/register`,
        JSON.stringify({ first_name: 'Load', last_name: 'Test', email, password: 'secret123' }),
        { headers: { 'Content-Type': 'application/json' }, tags: { kind: 'auth' } }
    );

    const token = res.json('data.access_token');
    if (!token) {
        throw new Error(`registration failed: ${res.status} ${res.body}`);
    }

    const album = http.post(
        `${BASE}/albums`,
        JSON.stringify({ title: 'Load test album' }),
        {
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
            tags: { kind: 'read' },
        }
    );

    return { token, albumId: album.json('data.id') };
}

export default function (data) {
    const params = {
        headers: { Authorization: `Bearer ${data.token}` },
        tags: { kind: 'read' },
    };

    group('reads', () => {
        const me = http.get(`${BASE}/users/me`, params);
        check(me, { 'me is 200': (r) => r.status === 200 });

        const albums = http.get(`${BASE}/albums/my`, params);
        check(albums, {
            'albums is 200': (r) => r.status === 200,
            'albums is enveloped': (r) => r.json('success') === true,
        });

        // the revalidation path: a polling client spends most of its requests
        // here, and this is what ADR 13 claims to make cheap on the wire
        const etag = albums.headers['Etag'];
        if (etag) {
            const again = http.get(`${BASE}/albums/my`, {
                headers: { ...params.headers, 'If-None-Match': etag },
                tags: { kind: 'read' },
            });
            check(again, { 'revalidation is 304': (r) => r.status === 304 });
        }

        const photos = http.get(`${BASE}/albums/${data.albumId}/photos`, params);
        check(photos, { 'photos is 200': (r) => r.status === 200 });
    });

    group('public', () => {
        const health = http.get(`${BASE}/health`, { tags: { kind: 'read' } });
        check(health, { 'health is ok': (r) => r.json('data.status') === 'ok' });
    });
}
