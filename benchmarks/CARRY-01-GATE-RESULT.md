# CARRY-01 — Pool Ping Freshness Window — Benchmark Gate Result

**Status:** ✅ Gate **passed** → the freshness window ships (library default 0, skeleton default 500 ms).
**Date:** 2026-10-22 · **PHP:** 8.5.12 · **FrankenPHP:** 1.13.2 · **Rig:** `bench/` Engine A (the skeleton's
prod image) with `bench-postgres` 17.
**Host:** Intel Core i7-9750H (6 cores, 12 threads), 16 GiB, macOS 15, Docker Desktop 29.9.2 (12 vCPU,
13.6 GiB VM). One laptop: the figures compare two settings on the same rig and are not capacity numbers
(`AGENTS.md` §7).

## The gate

`Roadmap_Beta7` `[CARRY-01]`: the beta6 benchmark traced Engine A's early saturation knee on `dbread`
to the pool's ping-before-dispense, a liveness `SELECT 1` before every lease. The item makes the probe
skippable for a recently verified connection and keeps the fail-safe for stale ones. The
`benchmark-gate` skill states the condition: proceed only if the knee moves **and** stale connections
are still caught.

## The change under test

- `PDOConnectionPool(pingFreshnessMs: W)`: a connection proven alive — connected, or answered the
  probe — less than W ms ago is dispensed without the probe; an older one is probed, and replaced if
  dead. W = 0 is the previous behaviour. Only a proof counts: a connection coming back from a lease is
  not proven alive, because the pool never sees the caller's errors.
- `ConnectionPoolHealthCheck` asks the leased connection `isAlive()`, so `/readyz` no longer depends on
  the lease's probe.
- The skeleton passes `waffle.database.ping_freshness_ms` (`DB_PING_FRESHNESS_MS`, default 500).

## Why the probe costs so much on PostgreSQL

pdo_pgsql runs `PDO::query()` as a named prepared statement, so the probe is a Parse and an Execute:
two round trips, two server transactions (`execute pdo_stmt_00000001: SELECT 1` in the server log).
A pooled read is the probe plus the read's own Parse and Execute; a `POST` under
`TransactionIsolationMiddleware` is the probe plus BEGIN … COMMIT.

## Thresholds (fixed before the comparison runs)

1. **Mechanism:** database transactions per request on Engine A drop from 4 to 2 on `GET /read/demo`
   and from 3 to 1 on `POST /greet` (W = 500 against W = 0).
2. **The knee moves:** `dbread` constant-arrival ladder, 2 minutes per step, W = 0 and W = 500 on the
   same image, interleaved, two repeats each. The knee is the highest step where at least 99 % of the
   target requests were sent and the median is at most 10 ms. Proceed requires the knee to rise by at
   least one 100 rps step in both repeats.
3. **Stale connections are caught** (`bench/scripts/check-pool-freshness.sh`): after every app
   connection is killed and the window has passed, 200 reads all succeed; under back-to-back reads, no
   read sent later than W + 250 ms after the kill fails; with W = 0, at most the read in flight fails.

**Method amendment, made before any comparison run:** the plan placed the ladder at 200–600 rps, where
beta6 found the knee. The first baseline on today's rig showed Engine A holding 600 rps (median 3.3 ms,
p99 22 ms), so the knee has moved since beta6 (FrankenPHP 1.13.2, PHP 8.5.12, the beta7 code). An
exploratory run on the unchanged code (600–1800 rps, 300 rps steps) located it, and the comparison
ladder was centred there with the same 100 rps steps and the same criterion.

## Results

### 1. Mechanism — met

`check-pool-freshness.sh`, 200 requests each, Engine A alone with `bench-postgres` (runs `carry01` and
`carry01-worstcase`; their counts agree to within 0.03):

| | W = 0 | W = 500 |
|---|---:|---:|
| transactions per `GET /read/demo` | 4.21 | 2.12 |
| transactions per `POST /greet` | 3.15 | 1.07–1.10 |

What sits above the whole numbers is the counter's background: the reading itself and autovacuum.

### 2. The knee — met

`run-bench.sh engine-a constant dbread`, 2 minutes per step, interleaved W = 0, 500, 0, 500 (runs
`carry01-w0-1`, `carry01-w500-1`, `carry01-w0-2`, `carry01-w500-2`; each run's host record holds its
`DB_PING_FRESHNESS_MS`). Median latency, with the share of target requests sent where it fell below
99 %:

| rate (rps) | W = 0 #1 | W = 500 #1 | W = 0 #2 | W = 500 #2 |
|---:|---:|---:|---:|---:|
| 1100 | 2.9 ms | 2.2 ms (99.5 %) | 2.9 ms (98.7 %) | 1.7 ms |
| 1200 | 8.7 ms | 2.1 ms | 2.8 ms | 1.9 ms |
| 1300 | 619 ms | 2.3 ms | 12.2 ms | 1.9 ms |
| 1400 | 1 985 ms (88.8 %) | 2.3 ms | 302 ms | 2.2 ms |
| 1500 | 2 223 ms (87.8 %) | 2.7 ms | 1 993 ms (94.8 %) | 2.4 ms |
| 1600 | 2 473 ms (80.3 %) | 266 ms (91.3 %) | 2 330 ms (85.1 %) | 3.9 ms |
| **knee** | **1200** | **1500** | **1200** | **≥ 1600** |

The knee rose by 300 rps in the first repeat and by at least 400 rps in the second, whose top step
still held. W = 0 placed it at 1200 rps both times, as the unchanged code did in the exploratory run
(`carry01-oldcode-2`: 600, 900 and 1200 rps held at a 2.2–3.5 ms median; 1500 collapsed to 2 381 ms
with 84 % sent). No request failed at any step.

Tails: p99 is not flat at W = 500 (143 ms at 1400 rps in the second repeat); the criterion reads the
median. The first step of `carry01-w500-1` and of `carry01-w0-2` (1100 rps) shows a burst nothing in
this record explains (p99 0.9 s and 1.5 s, 99.5 % and 98.7 % sent). It hit one run of each setting,
so it does not bias the comparison; by the criterion, `carry01-w0-2` fails at 1100 rps and holds at
1200.

### 3. Stale connections — met

`check-pool-freshness.sh`, every app connection killed with `pg_terminate_backend`:

| | W = 0 | W = 500 |
|---|---|---|
| killed while idle, reads after the window | 0 / 200 failed | 0 / 200 failed |
| killed right after a probe, back-to-back reads | 0 / 578 failed | 127 / 850 failed, the last sent 286 ms after the kill |

The second row is what the window costs. A connection the database drops right after it was proven
alive fails every lease until its proof is W old; then the probe runs, fails, and the pool reconnects.
The kill command itself took about 200 ms, so the last failure came about 500 ms after the probe.
With W = 0 the same kill costs nothing. The first `carry01` run killed the connection when its proof
was already about W old, so it showed no failure at W = 500 either; the script now places the kill
right after a probe, and `carry01-worstcase` is the row above.

## Verdict

✅ **Proceed.** All three thresholds hold. `PDOConnectionPool` keeps `pingFreshnessMs = 0` as its
default, so the library behaves as before unless configured; the skeleton ships 500 ms (HikariCP's
`aliveBypassWindowMs` default), with the trade-off documented next to the setting.

## Invalidated series (kept, not used)

- `carry01-baseline-oldcode-1` (unchanged code, 200–600 rps): steps 400 and 500 show multi-second p99
  (6.9 s and 4.2 s) with only 81 % and 89 % of the target requests sent, while 600 rps held (p99 22 ms).
  They coincide with nine Mago checks the editor hook ran in `waffle-dev` during those minutes, on the
  same host.

## Data

Raw k6 summaries, RSS samples and host records: `bench/results/engine-a-constant-dbread-carry01-*`
(gitignored, one set per run id), and the `check-pool-freshness.sh` reports
`bench/results/pool-freshness-engine-a-w{0,500}-carry01{,-worstcase}.txt`. Archive them before they
are cleaned.
