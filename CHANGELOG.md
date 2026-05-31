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
  with a `SQLDialect` enum (`MySQL` / `SQLite` / `MSSQL`) for identifier quoting
  and pagination grammar, producing a `CompiledQuery`; and `FirestoreCompiler`
  producing a `CompiledFirestoreQuery` with `FirestoreScope` path isolation
  (`/artifacts/{appId}/public/data/{collection}` or
  `/artifacts/{appId}/users/{userId}/{collection}`) — equality is pushed
  server-side, ranges/ordering flag `requiresInMemoryFilter`.
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
- 39 tests covering the pool lifecycle, the query AST, both compilers, the
  hydrator (incl. property-hook rejection), and the migration runner (table
  provisioning, ordered application, idempotent re-runs, transactional rollback).
- ≥95% line coverage; zero Mago baselines (`composer mago && composer tests`
  green: fmt + lint + analyze + guard + PHPUnit).

### Dependencies
- `php: ^8.5`, `ext-pdo: *`, `waffle-commons/contracts: self.version`.
