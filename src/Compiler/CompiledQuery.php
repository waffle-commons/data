<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

/**
 * The relational output of compiling an SQR {@see \Waffle\Commons\Data\Query\Query}:
 * a parameterised SQL string and the positional bound parameters that fill its
 * `?` placeholders, in order.
 *
 * No value is ever interpolated into {@see self::$sql}; every literal lives in
 * {@see self::$parameters} and is bound by the driver, which is what makes a
 * compiled query injection-safe regardless of the values it carries.
 */
final readonly class CompiledQuery
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
