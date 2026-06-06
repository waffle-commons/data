<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

/**
 * The two key-access operations a {@see KeyValueCompiler} can emit. The backing
 * value is the canonical verb shared by Redis (`GET` / `MGET`) and, conceptually,
 * a DynamoDB `GetItem` / `BatchGetItem`.
 */
enum KeyValueOperation: string
{
    /** Single-key lookup, compiled from a key-equality predicate. */
    case Get = 'GET';

    /** Multi-key lookup, compiled from a key-membership (IN) predicate. */
    case MGet = 'MGET';
}
