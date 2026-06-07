<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Exception;

use PDOException;
use RuntimeException;
use Throwable;
use Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface;

use function is_string;

/**
 * Concrete wrapper for every data-layer failure: a native `PDOException`, a
 * dropped-socket network loss, or a payload encoding error.
 *
 * Per the persistence design, data errors are RECOVERABLE at the call site
 * (retry, fail over, surface a 503), so this extends `RuntimeException` rather
 * than `LogicException`. The original backend error is always preserved as the
 * exception `previous` so the full driver stack trace survives.
 */
class DatabaseException extends RuntimeException implements DatabaseExceptionInterface
{
    public function __construct(
        string $message,
        private readonly ?string $sqlState = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Wrap any backend throwable, lifting the ANSI SQLSTATE from a PDO failure
     * when present. A non-relational error simply carries a null SQLSTATE.
     */
    public static function fromThrowable(Throwable $error, ?string $message = null): self
    {
        return new self($message ?? $error->getMessage(), self::sqlStateOf($error), $error);
    }

    #[\Override]
    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * PDO reports the SQLSTATE as the first element of `errorInfo`
     * (`[SQLSTATE, driverCode, driverMessage]`); `getCode()` is unreliable for
     * this because PDO stores a non-numeric string there in violation of the
     * `int` contract. Every other throwable carries no SQLSTATE.
     */
    private static function sqlStateOf(Throwable $error): ?string
    {
        if (!$error instanceof PDOException) {
            return null;
        }

        // PDOException::$errorInfo is `[SQLSTATE, driverCode, driverMessage]`;
        // typing the element keeps it out of `mixed` without an isset() guard.
        /** @var array{0?: string|null, 1?: int|null, 2?: string|null} $info */
        $info = $error->errorInfo;

        return is_string($info[0] ?? null) ? $info[0] : null;
    }
}
