<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Exception;

/**
 * Raised when a warmup artifact cannot be compiled or published (unwritable
 * cache directory, failed atomic rename, …).
 *
 * Extends {@see DatabaseException} so callers treat warmup failures like any
 * other recoverable data-layer error; it carries no SQLSTATE — the failure is
 * file-system level, not relational.
 */
final class WarmupException extends DatabaseException {}
