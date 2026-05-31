<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Hydrator;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Exception\ValidationException;

use function array_key_exists;
use function array_values;
use function assert;
use function get_debug_type;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Maps a raw backend row onto an immutable, `final readonly` DTO by invoking its
 * constructor with native named arguments.
 *
 * There is deliberately no identity map, no change tracking and no proxying: a
 * row becomes a value object and nothing more. Integrity is enforced in two
 * layers:
 *
 *  1. **Pre-construction type check** — each column is validated against the
 *     matching constructor parameter's declared scalar type (with safe int→float
 *     widening) *before* construction, so a native `\TypeError` can never escape
 *     the hydrator.
 *  2. **Property Hooks** — once the scalars line up, the DTO's own PHP 8.5
 *     `set` hooks run inside the constructor and reject semantically invalid
 *     values, throwing a {@see ValidationExceptionInterface}.
 *
 * Either layer failing yields a {@see ValidationException} (which *is* a
 * {@see ValidationExceptionInterface}), so a poisoned record is rejected exactly
 * like poisoned request input — surfacing as an RFC 7807 `422`.
 *
 * @template T of object
 */
final class PropertyHookHydrator
{
    /**
     * @param class-string<T> $target
     */
    public function __construct(
        private readonly string $target,
    ) {}

    /**
     * Hydrate a single row into one instance of the target DTO.
     *
     * @param array<string, int|float|string|bool|null> $row
     *
     * @return T
     *
     * @throws ValidationExceptionInterface When the row is missing a required
     *         field, a value has the wrong type, or a Property Hook rejects the
     *         value during construction.
     */
    public function hydrate(array $row): object
    {
        $arguments = $this->mapArguments($row);

        try {
            $instance = new $this->target(...$arguments);
        } catch (Throwable $failure) {
            // A Property Hook (or pre-check) already produced a precise,
            // field-aware validation failure: propagate it unchanged.
            if ($failure instanceof ValidationExceptionInterface) {
                throw $failure;
            }

            // Any other constructor failure (a hook throwing a non-validation
            // error, an unexpected state) is normalised to a validation failure
            // so corrupt persisted data never crashes the worker.
            throw new ValidationException(
                sprintf('Unable to hydrate "%s" from the given row.', $this->target),
                null,
                $failure,
            );
        }

        // `new` on a `class-string<T>` widens to `object`; re-narrow to the
        // declared target type for the caller.
        assert($instance instanceof $this->target);

        return $instance;
    }

    /**
     * @param array<string, int|float|string|bool|null> $row
     *
     * @return array<string, int|float|string|bool|null>
     *
     * @throws ValidationException When a required field is absent or mistyped.
     */
    private function mapArguments(array $row): array
    {
        $arguments = [];
        foreach ($this->constructorParameters() as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $row)) {
                // A parameter with a default value may be omitted; anything else
                // is required and its absence is a precise, field-aware failure.
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }

                throw new ValidationException(sprintf('Missing required field "%s".', $name), $name);
            }

            // The key provably exists (guarded above); `?? null` only ever yields
            // the real value — including a genuine null — and satisfies the strict
            // index-existence analyzer without altering behaviour.
            $arguments[$name] = $this->coerce($parameter, $row[$name] ?? null);
        }

        return $arguments;
    }

    /**
     * @return list<ReflectionParameter>
     *
     * @throws ValidationException When the target has no usable constructor.
     */
    private function constructorParameters(): array
    {
        try {
            $reflection = new ReflectionClass($this->target);
        } catch (ReflectionException $error) {
            throw new ValidationException(sprintf('Target class "%s" does not exist.', $this->target), null, $error);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            throw new ValidationException(sprintf('Target class "%s" has no constructor.', $this->target));
        }

        return array_values($constructor->getParameters());
    }

    /**
     * Validate a single value against its parameter's declared scalar type,
     * applying only the one widening PHP itself performs losslessly (int→float).
     *
     * @throws ValidationException When the value cannot satisfy the declared type.
     */
    private function coerce(
        ReflectionParameter $parameter,
        int|float|string|bool|null $value,
    ): int|float|string|bool|null {
        $name = $parameter->getName();
        $type = $parameter->getType();

        // Untyped or union/intersection types: defer entirely to the DTO hook.
        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        if ($value === null) {
            return $this->coerceNull($type, $name);
        }

        return match ($type->getName()) {
            'int' => is_int($value) ? $value : $this->reject($name, 'int', $value),
            'float' => $this->coerceFloat($value, $name),
            'string' => is_string($value) ? $value : $this->reject($name, 'string', $value),
            'bool' => is_bool($value) ? $value : $this->reject($name, 'bool', $value),
            // Non-scalar / class-typed parameter: leave the value untouched and
            // let the DTO's hook (or PHP's own type system) have the final say.
            default => $value,
        };
    }

    /**
     * @throws ValidationException When null is not permitted for the parameter.
     */
    private function coerceNull(ReflectionNamedType $type, string $name): null
    {
        if ($type->allowsNull()) {
            return null;
        }

        throw new ValidationException(sprintf('Field "%s" must not be null.', $name), $name);
    }

    /**
     * @throws ValidationException When the value is neither float nor int.
     */
    private function coerceFloat(int|float|string|bool $value, string $name): float
    {
        if (is_float($value)) {
            return $value;
        }

        // Lossless widening is the only implicit conversion we allow.
        if (is_int($value)) {
            return (float) $value;
        }

        // reject() always throws (return type `never`); no `return` keyword, which
        // would otherwise be flagged as returning a `never` expression.
        $this->reject($name, 'float', $value);
    }

    /**
     * @throws ValidationException Always; the `never` return type lets call sites
     *         use this in a match arm or expression position.
     *
     * @return never
     */
    private function reject(string $name, string $expected, int|float|string|bool $value): never
    {
        throw new ValidationException(
            sprintf('Field "%s" must be of type %s, %s given.', $name, $expected, get_debug_type($value)),
            $name,
        );
    }
}
