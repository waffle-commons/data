<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\RateLimit;

use RuntimeException;
use Throwable;
use Waffle\Commons\Contracts\RateLimit\Exception\StateStorageExceptionInterface;

/**
 * The shared rate-limit state is unavailable ([NET-01]): Redis is unreachable, refused the script, or
 * the pool handed out another client. The limiter catches the contract interface to apply its failure
 * mode.
 */
final class StateStorageException extends RuntimeException implements StateStorageExceptionInterface
{
    public static function transport(Throwable $previous): self
    {
        return new self('The rate-limit storage is unreachable: ' . $previous->getMessage(), 0, $previous);
    }

    public static function unexpectedReply(): self
    {
        return new self('The rate-limit storage returned an unreadable reply.');
    }

    public static function unexpectedClient(): self
    {
        return new self('The Redis pool did not hand out an ext-redis client.');
    }
}
