<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Exception;

use RuntimeException;
use Throwable;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;

/**
 * Thrown by the hydrator when a row or document cannot be mapped onto an
 * immutable DTO: a required field is missing, a value has the wrong type, or a
 * target Property Hook rejects the value during construction.
 *
 * It implements the framework's {@see ValidationExceptionInterface} marker so a
 * poisoned record surfaces as an RFC 7807 `422` through the same renderer that
 * handles inbound `#[Dto]` validation — corrupt persisted data is treated with
 * the same rigour as corrupt request input.
 */
class ValidationException extends RuntimeException implements ValidationExceptionInterface
{
    public function __construct(
        string $message,
        private readonly ?string $field = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    #[\Override]
    public function getField(): ?string
    {
        return $this->field;
    }
}
