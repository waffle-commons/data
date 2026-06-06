<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

/**
 * The relational output of compiling a write mutation (INSERT / UPDATE / DELETE):
 * a parameterised SQL string and the positional bound parameters that fill its
 * `?` placeholders, in order.
 *
 * As with {@see CompiledQuery}, no value is ever interpolated into
 * {@see self::$sql}; every literal lives in {@see self::$parameters} and is bound
 * by the driver, keeping the mutation injection-safe regardless of its values.
 */
final readonly class CompiledWrite
{
    /** @var list<int|float|string|bool|null> */
    public array $parameters;

    /**
     * @param list<int|float|string|bool|null> $parameters
     */
    public function __construct(
        public string $sql,
        array $parameters,
    ) {
        $this->parameters = $parameters;
    }
}
