<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Exception;

use Waffle\Commons\Contracts\Data\Exception\SecurityPathViolationExceptionInterface;

/**
 * Thrown when a document-store operation would target a path outside the
 * mandated isolation boundaries (RFC-022 §4.2, Firestore Rule 1).
 *
 * Carries no SQLSTATE (the violation is detected before any backend call), and
 * extends {@see DatabaseException} so it flows through the unified
 * persistence-failure strategy.
 */
final class SecurityPathViolationException extends DatabaseException implements
    SecurityPathViolationExceptionInterface {}
