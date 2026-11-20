import http from 'k6/http';
import { check } from 'k6';

export const BASE = __ENV.BASE_URL || 'http://localhost:8080';
export const GAME = __ENV.GAME || 'ember';

export function login(email, password = 'password123') {
    const res = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email, password }), {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    });
    check(res, { 'login 200': (r) => r.status === 200 });
    return res.json('data.token');
}

export function auth(token, extra = {}) {
    return { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', ...extra } };
}
