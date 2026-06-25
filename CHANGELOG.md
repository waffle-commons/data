# Changelog — waffle-commons/data

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta5] — 2026-06-26

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
