// SLO: POST /complete p95 < 300ms, never double-awarded under retries.
// k6 run -e BASE_URL=http://localhost:8080 load/complete.js
import http from 'k6/http';
import { check } from 'k6';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';
import { BASE, GAME, login, auth } from './common.js';

export const options = {
    scenarios: {
        write: { executor: 'constant-arrival-rate', rate: 50, timeUnit: '1s', duration: '1m', preAllocatedVUs: 30, maxVUs: 100 },
    },
    thresholds: {
        'http_req_duration{name:complete}': ['p(95)<300'],
        'checks{kind:replay}': ['rate==1'],
    },
};

export function setup() {
    const tokens = [];
    for (let i = 1; i <= 10; i++) tokens.push(login(`player${i}@${GAME}.test`));
    // Repeatable demo quests (the seeder makes quests 1, 3, 5 repeatable with a 60s cooldown).
    const quests = http.get(`${BASE}/api/v1/games/${GAME}/quests?limit=100`, auth(tokens[0])).json('data')
        .filter((q) => q.is_repeatable).map((q) => q.id);
    return { tokens, quests };
}

export default function ({ tokens, quests }) {
    const token = tokens[Math.floor(Math.random() * tokens.length)];
    const quest = quests[Math.floor(Math.random() * quests.length)];
    const key = uuidv4();
    const body = JSON.stringify({ score: 500, duration_ms: 30000 });
    const url = `${BASE}/api/v1/games/${GAME}/quests/${quest}/complete`;

    const first = http.post(url, body, { ...auth(token, { 'Idempotency-Key': key }), tags: { name: 'complete' } });
    // 201 accepted, 202 flagged, 429 cooldown (expected under load with a 60s cooldown)
    check(first, { 'handled': (r) => [201, 202, 429].includes(r.status) });

    if (first.status === 201) {
        const replay = http.post(url, body, { ...auth(token, { 'Idempotency-Key': key }), tags: { name: 'replay' } });
        check(replay, { 'replay identical': (r) => r.body === first.body }, { kind: 'replay' });
    }
}
