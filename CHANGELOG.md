# Changelog — waffle-commons/data

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [Unreleased] — targeting `0.1.0-beta3`

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
