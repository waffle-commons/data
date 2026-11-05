# Changelog — waffle-commons/data

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta7] — 2026-11

**Theme: shared limiter and breaker state, reversible migrations and the pool's ping freshness window.**

### Added
- `Health\ConnectionPoolHealthCheck` (OPS-01, RFC-023 §3.3) — the `/readyz` probe for a connection pool: it leases one connection, asks it `isAlive()` and gives it back, so it costs one or two round trips and never opens a connection of its own. It asks the connection itself because a lease inside the pool's ping freshness window skips its ping. It serves the relational pool (`db.pool`) and the Redis pool alike; a pool that cannot lease, or a connection that fails or throws on `isAlive()`, is reported down with the reason, never thrown.
- **Ping freshness window (CARRY-01):** `PDOConnectionPool`'s new `pingFreshnessMs` argument (default `0`, today's behaviour) dispenses a connection without the `SELECT 1` probe when it was proven alive — connected, or answered the probe — less than that many milliseconds ago; an older one is probed and, if dead, replaced as before. Under PostgreSQL the probe is two round trips (pdo_pgsql prepares it), half of a pooled read's database work. The trade-off: when the database drops its connections, requests can fail for up to the window after a connection was last proven alive. Only a proof counts — a connection coming back from a lease is not proven alive, since the pool never sees the caller's errors.
- `RateLimit\RedisLimiterStateStorage` (NET-01, RFC-024 §5.1) — the shared token buckets behind `LimiterStateStorageInterface`: one Lua script refills and takes atomically on the Redis server clock, through the framework's Redis pool. Measured on 2026-10-17: 8 concurrent processes, 200 calls on a bucket of 50 → exactly 50 admitted. Failures surface as `RateLimit\StateStorageException` (implements `StateStorageExceptionInterface`).
- `CircuitBreaker\RedisCircuitStateStorage` (NET-03, RFC-024 §3.2) — the shared circuit state behind `CircuitStateStorageInterface`: `acquire()` and `record()` are each one Lua script on the Redis server clock (closed → open at the failure-rate threshold over a window, open → half-open after the cool-down with a bounded number of trial calls, a trial success closes, a trial failure reopens). Same pool and same `StateStorageException` as the rate limiter. Trials that record no outcome within a cool-down give their slots back (`trial_at`), so a worker that died mid-call, or a call whose outcome says nothing about the upstream, never holds the circuit half-open, refusing every call, until the hash expires.
- **Reversible migrations (OPS-03, RFC-014):** `MigrationRunner::rollback()` reverts the most recent versions (highest first) through their `V.down.sql` scripts, each in its own transaction, and checks every target before it reverts any; `status()` reports each version as applied, pending or missing, with whether it is reversible and its ledger instant. `V.down.sql` files are never applied as versions.
- `MigrationRunner` refuses a script with no SQL statement, only `--` / `/* */` comments, blank lines or semicolons, before it opens a transaction. PostgreSQL and SQLite run such a script as a no-op, so a file scaffolded by `make:migration` and left unedited would have been logged as applied, or its version as reverted. `rollback()` reads every targeted down script first, so an empty one stops it before anything is reverted.
- `Migration\MigrationLedger` — the `waffle_migrations` SQL per dialect: SQL Server guards the `CREATE TABLE` with `OBJECT_ID` and uses `DATETIME2` (its `TIMESTAMP` is a row-version), Oracle runs a PL/SQL block that ignores only ORA-00955. The runner reads the dialect from the PDO driver name, or takes one as its third argument.

### Fixed
- `MigrationRunner` failed every DDL migration on MySQL and MariaDB: their DDL commits implicitly, so PHP 8's `commit()` threw "There is no active transaction" after the script had run and its ledger row had been written. The runner now commits only while a transaction is still open. Proven live: the migrate → status → rollback round trip runs on PostgreSQL, MySQL 8.4 and MariaDB 11.4 (`MigrationRoundTripTest`), SQLite in the unit suite.

## [0.1.0-beta6] — 2026-09

**Theme: identifier-quoting hardening.**

### Fixed
- SQL/CQL identifiers are validated against a strict allow-list and escaped consistently; `CassandraRepository::save()`/`delete()` now route table, column and identity names through the same `quoteIdentifier()` helper the read path already used (Beta6 audit FIX-01).

### Documentation
- The README now links into the central Diátaxis documentation tree (DOC-02).

## [0.1.0-beta5] — 2026-07-08

**Theme: native query tracing, pooling & defense-in-depth.**

### Added
- **Native DB query telemetry (OBS-01)** — `Telemetry\QueryTracer` opens and closes a
  `waffle.db.query` CLIENT span (tagged `db.system` + `db.operation`) around every
  repository operation, so each backend call appears natively in distributed traces
  with no optional decorator. Tracing is opt-in via a new immutable `withTracer(TracerInterface)`
  wither on all seven repositories; the contract `NullTracer` is the default, keeping
  the hot path zero-cost when telemetry is off. The repository wraps the call itself
  (`open()` → try / `fail()` (records the exception, re-throws untouched) / `finally end()`),
  so the throwing call stays inside the method body and the strict `check-throws` analysis
  passes without an undocumented closure boundary.
- **`Connection\RedisConnectionPool` (DBAL-01)** — the Redis counterpart to
  `PDOConnectionPool` (`RedisConnectionPoolInterface` + `ResettableInterface`): a bounded,
  worker-safe pool of reusable Redis client handles with **heal-on-lease** (each idle client
  is `PING`-probed before it is dispensed and a dead socket is discarded and transparently
  replaced), an issued-handle ledger that rejects a foreign lease so another pool's client is
  never pooled (DBAL-03), and `reset()` that returns borrowed handles to the idle set and runs
  an optional per-handle reset hook (`DISCARD`/`UNWATCH`) so an open `MULTI`/`WATCH` never bleeds
  into the next request. The client is typed as `object` throughout — `ext-redis` is never
  assumed; the concrete client, its ping, and its reset are injected as closures by the wiring.
- **`Connection\PdoConnection` lease + relational request scope (DBAL-01)** — `acquire()` now
  mints a request-scoped `PdoConnectionInterface` lease wrapping the pooled `PDO`, so the
  backend-neutral pool contract stays free of any `PDO` type while relational repositories get
  fully typed access via `pdo()`. `PDOConnectionPool` gains `beginRequestScope()` /
  `endRequestScope()` to pin one connection for a whole write request (connection affinity), so
  every repository `acquire()` during the scope returns the SAME handle and its writes run inside
  the same transaction.
- **`Middleware\TransactionIsolationMiddleware` (DBAL-02)** — wraps every write request
  (POST/PUT/PATCH/DELETE by default, configurable) in a single transaction borrowed from the
  relational pool on a pinned connection: it commits when the downstream handler returns normally
  and rolls back on ANY uncaught throwable, then re-throws, so a half-applied write or a leaked
  lock can never bleed from one worker iteration into the next. Rollback is quiet — a connection
  already severed mid-flight is left for the pool's `reset()` to reap so the original failure
  surfaces. Stateless: the only transactional state lives on the pooled connection.

### Security
- **HARDEN-03 — SQL identifier allow-list** — `Compiler\SQLDialect` now validates every identifier
  segment against an allow-list (`/^[^\x00-\x1F\x7F]+$/`) ALONGSIDE dialect quote-escaping,
  rejecting empty segments and NUL/control characters (which quoting cannot make safe —
  truncation / statement-splitting / log injection) with an `InvalidArgumentException`; printable
  quote characters are still permitted and neutralised by the existing quote-doubling.
- **HARDEN-03 — bounded `LIKE` regex** — `Evaluation\InMemoryEvaluator` collapses runs of the
  multi-character `%` wildcard before translating a `LIKE` pattern to a regex, so a crafted
  `"%%%%…"` pattern cannot expand to `".*.*.*…"` and trigger catastrophic backtracking.

### Changed
- **DX-01** — `Storage\JsonFileStore` derives its atomic temp-file suffix from
  `bin2hex(random_bytes(6))` instead of `uniqid()`, giving an unpredictable per-writer name; a
  missing entropy source surfaces as the store's own `DatabaseException`.
- Repositories now depend on the relational pool through the typed `RelationalConnectionPoolInterface`
  lease seam (`pdo()`) rather than acquiring a raw `PDO` from `ConnectionPoolInterface`.

### Dependencies
- Added `psr/http-server-handler: ^1.0` and `psr/http-server-middleware: ^1.0` (PSR-15) for
  `TransactionIsolationMiddleware`.

## [0.1.0-beta4] — 2026-06-13

**Theme: worker-mode diagnostics.**

### Added
- Optional dev-only `?ConnectionTrackerInterface` hook in `Connection\PDOConnectionPool` — reports pooled PDO connections (`ConnectionKind::Pdo`) to the orphaned-connection tracer; `null` in production (zero-cost no-op) (DIAG-03).

### Changed
- Worker-safety migration to igor-php 0.7 (`#[WorkerSafe]`).

## [0.1.0-beta3] — 2026-06-07

**Theme: data & persistence — the worker-safe, ORM-free data layer (RFC-022).**

Initial release of the `waffle-commons/data` component. Depends only on
`waffle-commons/contracts` (+ `ext-pdo`, PSR, PHP core).

### Added
- **Connection** — `PDOConnectionPool` (`final`), implementing
  `ConnectionPoolInterface` + `ResettableInterface`. Keeps a bounded set of PDO
  connections warm across FrankenPHP worker requests: ping-before-dispense
  (`SELECT 1`) with transparent reconnect, a per-connection prepared-statement
  cache, and `reset()` that rolls back dangling transactions, returns borrowed
  handles to the idle set, and clears the statement cache between requests.
- **Query (SQR AST)** — `Query` (immutable, copy-on-write builder:
  `select` / `from` / `where` / `orderBy` / `limit` / `offset`), the `Criteria`
  static predicate factory (`eq`/`neq`/`gt`/`gte`/`lt`/`lte`/`like`/`in`/`notIn`),
  the `Comparison` / `Order` value objects, and the `Operator` / `Direction`
  enums. Pure representation state — compiler-agnostic.
- **Compilers** — `SQLCompiler` (parameterized `?` placeholders, injection-safe)
  with a `SQLDialect` enum (`MySQL` / `MariaDB` / `SQLite` / `MSSQL` /
  `PostgreSQL` / `Oracle`) for identifier quoting and pagination grammar,
  producing a `CompiledQuery`; `SQLWriteCompiler` for `INSERT`/`UPDATE`/`DELETE`
  compilation; `FirestoreCompiler` producing a `CompiledFirestoreQuery` with
  `FirestoreScope` path isolation
  (`/artifacts/{appId}/public/data/{collection}` or
  `/artifacts/{appId}/users/{userId}/{collection}`) — equality is pushed
  server-side, ranges/ordering flag `requiresInMemoryFilter`; plus dedicated
  compilers for MongoDB filter documents, Cassandra CQL, key-value `GET`/`MGET`
  command plans and GraphQL query/mutation documents.
- **Repositories (7 backends)** — `SQLRepository`, `FirestoreRepository` (the
  three RFC-022 guardrails: strict path factories, in-memory evaluation of
  complex predicates, `assertAuthenticated()` on every read AND write),
  `MongoRepository`, `CassandraRepository`, `KeyValueRepository`,
  `GraphQLRepository` and `JsonFileRepository` — all implementing the full
  `WritableRepositoryInterface` CRUD surface (`find` / `findOne` / `stream` /
  `findById` / `save` / `delete`) through `DataMapperInterface` mappers.
- **Evaluation** — stateless `InMemoryEvaluator` (fetch-then-filter for range,
  set, sort, offset semantics on backends with restricted server-side querying).
- **Drivers** — `FirestoreRestClient` (REST transport), `MongoDriverSession`,
  `RedisKeyValueClient`, `GraphQLExecutor` and the `CqlSessionInterface` seam —
  every backend failure is rethrown as a `DatabaseException` with an explicit
  sanitized message, so credentials, DSNs and driver internals never reach a
  rendered RFC 7807 body (the raw error survives only as `previous`).
- **Storage** — `JsonFileStore` atomic flat-file store (read-modify-write under
  `LOCK_EX`).
- **Warmup** — `QueryWarmer` (`Contracts\Data\Warmup\DataWarmerInterface`):
  pre-compiles a registry of named SQR trees into a single `<?php return […]`
  artifact, replaced atomically and primed into OPcache shared memory — the
  engine behind the `waffle data:warmup` console command. `WarmupException`
  covers unwritable artifact paths.
- **Hydrator** — `PropertyHookHydrator`: maps a raw row onto an immutable DTO via
  its constructor, with a pre-construction scalar type check (lossless int→float
  widening) followed by the DTO's own PHP 8.5 `set` hooks. Any failure is
  normalised to a `ValidationExceptionInterface`, so corrupt persisted data can
  never crash the worker.
- **Migration** — `MigrationRunner` implementing the new
  `MigrationRunnerInterface` (contracts): provisions a `waffle_migrations`
  tracking table, discovers `*.sql` files in version order, applies each pending
  migration in its own transaction (rollback + abort on failure), records applied
  versions, and skips already-applied ones. Surfaced as `bin/waffle db:migrate`
  by the `console` component.
- **Exceptions** — `DatabaseException` (`DatabaseExceptionInterface`, lifts the
  ANSI `SQLSTATE` from a `PDOException`) and `ValidationException`
  (`ValidationExceptionInterface`, field-aware → RFC 7807 `422`).

### Engine notes
- Per-migration transactions are fully atomic on engines with transactional DDL
  (SQLite, PostgreSQL). On MySQL, DDL statements (`CREATE`/`ALTER TABLE`) trigger
  an implicit commit, so a failed DDL step cannot be rolled back — keep one schema
  change per migration file on MySQL.

### Tests
- 350+ tests covering the pool lifecycle (incl. the four `reset()` contracts),
  the query AST, every compiler dialect and backend, the Firestore guardrails,
  CRUD round-trips on all seven repositories, the hydrator (incl. property-hook
  rejection), driver exception wrapping (incl. the credential leak-containment
  negative test), the warmup artifact lifecycle, and the migration runner
  (table provisioning, ordered application, idempotent re-runs, transactional
  rollback).
- ≥95% line coverage; zero Mago baselines (`composer mago && composer tests`
  green: fmt + lint + analyze + guard + PHPUnit).

### Dependencies
- `php: ^8.5`, `ext-pdo: *`, `waffle-commons/contracts: self.version`.
