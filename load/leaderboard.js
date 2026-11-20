// SLO: GET /leaderboard p95 < 80ms
// k6 run -e BASE_URL=http://localhost:8080 load/leaderboard.js
import http from 'k6/http';
import { check } from 'k6';
import { BASE, GAME, login, auth } from './common.js';

export const options = {
    scenarios: {
        read: { executor: 'constant-arrival-rate', rate: 200, timeUnit: '1s', duration: '1m', preAllocatedVUs: 50, maxVUs: 200 },
    },
    thresholds: {
        'http_req_duration{name:leaderboard}': ['p(95)<80'],
        http_req_failed: ['rate<0.01'],
    },
};

export function setup() {
    const tokens = [];
    for (let i = 1; i <= 10; i++) tokens.push(login(`player${i}@${GAME}.test`));
    return { tokens };
}

export default function ({ tokens }) {
    const token = tokens[Math.floor(Math.random() * tokens.length)];
    const scope = ['all', 'weekly', 'season'][Math.floor(Math.random() * 3)];
    const res = http.get(`${BASE}/api/v1/games/${GAME}/leaderboard?scope=${scope}&limit=25`, { ...auth(token), tags: { name: 'leaderboard' } });
    check(res, { '200': (r) => r.status === 200 });
}
