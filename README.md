[![Discord](https://img.shields.io/discord/755288001592033391?logo=discord)](https://discord.gg/eKgywnfXr2)
[![PHP Version Require](http://poser.pugx.org/waffle-commons/data/require/php)](https://packagist.org/packages/waffle-commons/data)
[![PHP CI](https://github.com/waffle-commons/data/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/data/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/data/graph/badge.svg)](https://codecov.io/gh/waffle-commons/data)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/data/v)](https://packagist.org/packages/waffle-commons/data)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/data/v/unstable)](https://packagist.org/packages/waffle-commons/data)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/data.svg)](https://packagist.org/packages/waffle-commons/data)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/data)](https://github.com/waffle-commons/data/blob/main/LICENSE.md)

Waffle Data Component
=====================

> **Release:** `0.1.0-beta3` &nbsp;|&nbsp; [`CHANGELOG.md`](./CHANGELOG.md)

The data & persistence layer for the Waffle Framework (RFC-022). Built for FrankenPHP resident-worker mode: a warm connection pool, a backend-agnostic query AST, parameterized SQL / Firestore compilers, a property-hook hydrator, and a stateless SQL migration runner. **No stateful ORM, no identity map, no change tracking** — a row becomes an immutable value object and nothing more.

## 📦 Installation

```bash
composer require waffle-commons/data
```

Requires PHP 8.5+ and `ext-pdo`. Depends only on `waffle-commons/contracts` (plus PSR + PHP core).

## 🧱 Surface

| Class | Role |
| :--- | :--- |
| `Waffle\Commons\Data\Connection\PDOConnectionPool` | `final` pool of reusable PDO connections (`ConnectionPoolInterface` + `ResettableInterface`). Ping-before-dispense (`SELECT 1`), transparent reconnect, per-connection statement cache, `reset()` rolls back dangling transactions between requests. |
| `Waffle\Commons\Data\Query\Criteria` | Static factory for predicates: `eq`/`neq`/`gt`/`gte`/`lt`/`lte`/`like`/`in`/`notIn`. |
| `Waffle\Commons\Data\Query\Query` | Immutable, copy-on-write query AST (`select` / `from` / `where` / `orderBy` / `limit` / `offset`). Pure representation — knows nothing about any backend. |
| `Waffle\Commons\Data\Query\Operator` / `Direction` | `enum` operators (`=`, `<>`, `>`, …, `IN`, `LIKE`) and sort directions (`ASC` / `DESC`). |
| `Waffle\Commons\Data\Compiler\SQLCompiler` / `SQLWriteCompiler` | Compile a `Query` (reads) or a mapped entity row (`INSERT`/`UPDATE`/`DELETE`) into parameterized statements (`?` placeholders, injection-safe) for a chosen `SQLDialect`. |
| `Waffle\Commons\Data\Compiler\SQLDialect` | `enum` `MySQL` / `MariaDB` / `SQLite` / `MSSQL` / `PostgreSQL` / `Oracle` — identifier quoting + pagination grammar. |
| `Waffle\Commons\Data\Compiler\FirestoreCompiler` | Compiles a `Query` into a `CompiledFirestoreQuery` with path isolation; only equality is pushed server-side, ranges/ordering flag `requiresInMemoryFilter`. |
| `Waffle\Commons\Data\Compiler\FirestoreScope` | `public(appId, collection)` / `private(appId, userId, collection)` path scoping. |
| `Waffle\Commons\Data\Compiler\{Mongo,KeyValue,Cassandra,GraphQL}Compiler` | Per-backend SQR compilers: MongoDB filter documents, key-value `GET`/`MGET` plans, parameterised CQL, GraphQL query/mutation documents. |
| `Waffle\Commons\Data\Repository\…` | Seven stateless `WritableRepositoryInterface` repositories — `SQLRepository`, `FirestoreRepository` (three auth guardrails), `MongoRepository`, `CassandraRepository`, `KeyValueRepository`, `GraphQLRepository`, `JsonFileRepository` — full CRUD through pure `DataMapperInterface` mappers. |
| `Waffle\Commons\Data\Driver\…` | Live drivers: `FirestoreRestClient`, `MongoDriverSession`, `RedisKeyValueClient`, `GraphQLExecutor` (+ the injectable CQL port). Every backend failure is rethrown as a sanitized `DatabaseException`. |
| `Waffle\Commons\Data\Evaluation\InMemoryEvaluator` | Stateless fetch-then-filter evaluation (range/set/sort/offset) for backends with restricted server-side querying. |
| `Waffle\Commons\Data\Storage\JsonFileStore` | Atomic flat-file JSON store (read-modify-write under `LOCK_EX`). |
| `Waffle\Commons\Data\Hydrator\PropertyHookHydrator` | Maps a raw row onto an immutable DTO via its constructor; corrupt data is rejected by the DTO's PHP 8.5 `set` hooks as a `ValidationExceptionInterface`. |
| `Waffle\Commons\Data\Migration\MigrationRunner` | `MigrationRunnerInterface` — applies versioned `*.sql` files in order, tracked in `waffle_migrations`; each migration runs in its own transaction. |
| `Waffle\Commons\Data\Warmup\QueryWarmer` | `DataWarmerInterface` — pre-compiles named SQR trees into an atomic `<?php return […]` artifact primed into OPcache (`bin/waffle data:warmup`, Beta-3). |
| `Waffle\Commons\Data\Exception\DatabaseException` | `DatabaseExceptionInterface` — wraps any backend failure, lifts the ANSI `SQLSTATE` from a `PDOException`. |
| `Waffle\Commons\Data\Exception\ValidationException` | `ValidationExceptionInterface` — a field-aware hydration/validation failure (surfaces as RFC 7807 `422`). |

## 🚀 Quick start

### Connection pool

```php
use Waffle\Commons\Data\Connection\PDOConnectionPool;

$pool = new PDOConnectionPool(
    factory: static fn (): \PDO => new \PDO($dsn, $user, $pass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]),
    maxConnections: 8,        // hard ceiling on simultaneously borrowed handles
    pingQuery: 'SELECT 1',    // liveness probe before dispensing
);

$connection = $pool->acquire();   // a healthy PDO, reconnected transparently if the socket died
// ... use $connection ...
$pool->release($connection);      // return it to the idle set
$pool->reset();                   // end-of-request: roll back stragglers, clear statement cache
```

### Build a query, compile it for a backend

```php
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Direction;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Compiler\SQLDialect;

$query = Query::select('id', 'email')
    ->from('users')
    ->where(Criteria::eq('status', 'active'), Criteria::in('role', ['admin', 'editor']))
    ->orderBy('email', Direction::Ascending)
    ->limit(20);

$compiled = new SQLCompiler(SQLDialect::MySQL)->compile($query);
$compiled->sql;        // 'SELECT `id`, `email` FROM `users` WHERE `status` = ? AND `role` IN (?, ?) ORDER BY `email` ASC LIMIT 20'
$compiled->parameters; // ['active', 'admin', 'editor']
```

The same `Query` compiles to a Firestore payload via `FirestoreCompiler` with a `FirestoreScope` (public vs per-user path isolation).

### Hydrate an immutable DTO

```php
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;

$hydrator = new PropertyHookHydrator(UserDto::class);
$user = $hydrator->hydrate(['id' => '42', 'email' => 'ada@example.com']); // UserDto
// A malformed value is rejected by UserDto's `set` hook as a ValidationExceptionInterface.
```

### Run migrations (from the CLI)

`MigrationRunner` is wired into the console as `bin/waffle db:migrate` (see the [`console`](https://github.com/waffle-commons/console) component). Programmatically:

```php
use Waffle\Commons\Data\Migration\MigrationRunner;

$runner = new MigrationRunner(pool: $pool, config: $config);
$applied = $runner->run(static fn (string $version) => printf("applied %s\n", $version));
// $applied: list of versions applied this run ([] when already up to date)
```

## 🐘 PHP 8.5 features used

- `final readonly class` for every value object (`CompiledQuery`, `CompiledFirestoreQuery`, `Comparison`, `Order`, `FirestoreScope`).
- **Property Hooks** — DTOs validate inside their own `set` hooks; the hydrator never sees invalid state. (A hooked DTO is `final class` + `public private(set)`, since hooked properties cannot be `readonly`.)
- **Asymmetric visibility** (`public private(set)`) on the immutable `Query` builder.
- First-class callable syntax (`$this->method(...)`) in the compilers; `enum` with methods (`Operator::isSetOperator()`); `never`-returning rejection helpers; typed class constants.

## 🧭 Architectural boundary (`mago guard`)

Production code under `Waffle\Commons\Data` may depend **only** on `Waffle\Commons\Data\**`, `Waffle\Commons\Contracts\**`, `Psr\**`, and `@global` + `Psl\**`. A forbidden `use` fails the build, not a reviewer — enforced by `vendor/bin/mago guard` (bundled into `composer mago`, zero baselines). Interfaces must be named `*Interface`, `Exception\**` classes must end in `*Exception`, and any `Enum\**` namespace may hold only `enum` declarations.

Contract-first, component-agnostic by construction: the data layer composes with the rest of the framework through `waffle-commons/contracts`, never directly through another component.

## 🧪 Testing

```bash
docker exec -w /waffle-commons/data waffle-dev composer tests
```

The full gate (`composer mago && composer tests`) runs format, lint, analyze, guard, and PHPUnit (≥95% line coverage) with zero baselines.

## 📄 License

MIT — see [LICENSE.md](./LICENSE.md).
