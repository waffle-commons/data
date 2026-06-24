<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Connection;

use PDO;
use PDOException;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\PdoConnectionInterface;

use function spl_object_id;

/**
 * Request-scoped lease wrapping a pooled {@see PDO} handle.
 *
 * The {@see PDOConnectionPool} mints one of these per {@see PDOConnectionPool::acquire()}
 * so the backend-neutral pool contract can stay free of any `PDO` type while
 * relational repositories still get fully typed access via {@see self::pdo()}.
 *
 * The wrapper is a thin, immutable view over the underlying handle: its identity
 * ({@see self::id()}) is the underlying PDO's `spl_object_id`, so two leases of the
 * same physical connection compare equal and the pool can never double-pool it.
 */
final readonly class PdoConnection implements PdoConnectionInterface
{
    /**
     * @param PDO    $pdo       The pooled, exception-error-mode handle.
     * @param string $pingQuery Liveness probe used by {@see self::isAlive()}.
     */
    public function __construct(
        private PDO $pdo,
        private string $pingQuery = 'SELECT 1',
    ) {}

    #[\Override]
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    #[\Override]
    public function kind(): ConnectionKind
    {
        return ConnectionKind::Pdo;
    }

    #[\Override]
    public function id(): int
    {
        return spl_object_id($this->pdo);
    }

    #[\Override]
    public function isAlive(): bool
    {
        try {
            $statement = $this->pdo->query($this->pingQuery);
            if ($statement !== false) {
                // Free the probe's result buffer immediately.
                $statement->closeCursor();
            }

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
