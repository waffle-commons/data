<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Query;

/**
 * Comparison operators understood by the Semantic Query Representation (SQR).
 *
 * The backing value is the canonical SQL token; non-relational compilers (such
 * as Firestore) translate the case into their own dialect rather than reusing
 * this string verbatim.
 */
enum Operator: string
{
    case Equal = '=';
    case NotEqual = '<>';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case In = 'IN';
    case NotIn = 'NOT IN';
    case Like = 'LIKE';

    /**
     * Whether the operator compares a field against a *set* of values (IN /
     * NOT IN) rather than a single scalar. Compilers use this to decide how many
     * bound parameters a predicate expands to.
     */
    public function isSetOperator(): bool
    {
        return $this === self::In || $this === self::NotIn;
    }
}
