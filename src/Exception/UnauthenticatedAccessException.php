<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Exception;

use Waffle\Commons\Contracts\Data\Exception\UnauthenticatedAccessExceptionInterface;

/**
 * Thrown when a guarded backend operation is attempted without an authenticated
 * identity (RFC-022 §4.2, Firestore Rule 3).
 *
 * Carries no SQLSTATE (the guard fires before any backend call), and extends
 * {@see DatabaseException} so it flows through the unified persistence-failure
 * strategy.
 */
final class UnauthenticatedAccessException extends DatabaseException implements
    UnauthenticatedAccessExceptionInterface {}
