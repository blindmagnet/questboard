# Load tests (k6)

Start the stack with demo data (`make up`), then:

```bash
k6 run -e BASE_URL=http://localhost:8080 load/leaderboard.js   # SLO: p95 < 80 ms
k6 run -e BASE_URL=http://localhost:8080 load/complete.js      # SLO: p95 < 300 ms
```

The default API limit is 120 requests/minute per user; for load tests start the stack with a higher limit,
e.g. `QB_RATE_LIMIT_API=100000` in `.env`.

Both scripts encode the SLOs as k6 `thresholds`, so a run fails when an SLO is missed.
Record your results (hardware, p50/p95/p99, error rate) in the main README.
