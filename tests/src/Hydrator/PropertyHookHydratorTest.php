<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Hydrator;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Exception\ValidationException;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\HookedEmailDto;
use WaffleTests\Commons\Data\Fixture\HookThrowsPlainDto;
use WaffleTests\Commons\Data\Fixture\NoConstructorDto;
use WaffleTests\Commons\Data\Fixture\OptionalObjectDto;
use WaffleTests\Commons\Data\Fixture\ScalarsDto;
use WaffleTests\Commons\Data\Fixture\UnionFieldDto;

#[CoversClass(PropertyHookHydrator::class)]
#[CoversClass(ValidationException::class)]
final class PropertyHookHydratorTest extends AbstractTestCase
{
    public function testHydratesAllScalarTypes(): void
    {
        $hydrator = new PropertyHookHydrator(ScalarsDto::class);

        $dto = $hydrator->hydrate(['i' => 7, 'f' => 1.5, 's' => 'hi', 'b' => true]);

        self::assertSame(7, $dto->i);
        self::assertSame(1.5, $dto->f);
        self::assertSame('hi', $dto->s);
        self::assertTrue($dto->b);
    }

    public function testIntegerIsWidenedToFloat(): void
    {
        $hydrator = new PropertyHookHydrator(ScalarsDto::class);

        $dto = $hydrator->hydrate(['i' => 1, 'f' => 7, 's' => 'x', 'b' => false]);

        self::assertSame(7.0, $dto->f);
    }

    public function testUnionTypedFieldIsPassedThrough(): void
    {
        $hydrator = new PropertyHookHydrator(UnionFieldDto::class);

        self::assertSame('abc', $hydrator->hydrate(['ref' => 'abc'])->ref);
        self::assertSame(42, $hydrator->hydrate(['ref' => 42])->ref);
    }

    public function testNullableFieldAcceptsExplicitNull(): void
    {
        $hydrator = new PropertyHookHydrator(HookedEmailDto::class);

        $dto = $hydrator->hydrate(['id' => 1, 'email' => 'a@b.co', 'score' => null]);

        self::assertNull($dto->score);
    }

    public function testOptionalFieldMayBeOmitted(): void
    {
        $hydrator = new PropertyHookHydrator(HookedEmailDto::class);

        $dto = $hydrator->hydrate(['id' => 2, 'email' => 'c@d.co']);

        self::assertSame(2, $dto->id);
        self::assertSame('c@d.co', $dto->email);
        self::assertNull($dto->score);
    }

    public function testOmittedNullableObjectDefaultsToNull(): void
    {
        $hydrator = new PropertyHookHydrator(OptionalObjectDto::class);

        self::assertNull($hydrator->hydrate([])->when);
    }

    public function testExplicitNullObjectIsAccepted(): void
    {
        $hydrator = new PropertyHookHydrator(OptionalObjectDto::class);

        self::assertNull($hydrator->hydrate(['when' => null])->when);
    }

    public function testMissingRequiredFieldThrows(): void
    {
        $hydrator = new PropertyHookHydrator(ScalarsDto::class);

        try {
            $hydrator->hydrate(['f' => 1.0, 's' => 'x', 'b' => true]);
            self::fail('Expected a validation failure for the missing field.');
        } catch (ValidationExceptionInterface $exception) {
            self::assertSame('i', $exception->getField());
            self::assertStringContainsString('Missing required field', $exception->getMessage());
        }
    }

    public function testNullIntoNonNullableThrows(): void
    {
        $hydrator = new PropertyHookHydrator(ScalarsDto::class);

        try {
            $hydrator->hydrate(['i' => null, 'f' => 1.0, 's' => 'x', 'b' => true]);
            self::fail('Expected a validation failure for the null value.');
        } catch (ValidationExceptionInterface $exception) {
            self::assertSame('i', $exception->getField());
            self::assertStringContainsString('must not be null', $exception->getMessage());
        }
    }

    public function testWrongIntegerTypeThrows(): void
    {
        $this->assertRejectedField(ScalarsDto::class, ['i' => 'nope', 'f' => 1.0, 's' => 'x', 'b' => true], 'i');
    }

    public function testWrongFloatTypeThrows(): void
    {
        $this->assertRejectedField(ScalarsDto::class, ['i' => 1, 'f' => 'nope', 's' => 'x', 'b' => true], 'f');
    }

    public function testWrongStringTypeThrows(): void
    {
        $this->assertRejectedField(ScalarsDto::class, ['i' => 1, 'f' => 1.0, 's' => 99, 'b' => true], 's');
    }

    public function testWrongBoolTypeThrows(): void
    {
        $this->assertRejectedField(ScalarsDto::class, ['i' => 1, 'f' => 1.0, 's' => 'x', 'b' => 'yes'], 'b');
    }

    public function testPropertyHookRejectionSurfacesAsValidationException(): void
    {
        $hydrator = new PropertyHookHydrator(HookedEmailDto::class);

        try {
            $hydrator->hydrate(['id' => 1, 'email' => 'no-at-sign']);
            self::fail('Expected the email hook to reject the value.');
        } catch (ValidationExceptionInterface $exception) {
            self::assertSame('email', $exception->getField());
            self::assertSame('Invalid email address.', $exception->getMessage());
        }
    }

    public function testNonValidationHookFailureIsNormalised(): void
    {
        $hydrator = new PropertyHookHydrator(HookThrowsPlainDto::class);

        try {
            $hydrator->hydrate(['value' => -1]);
            self::fail('Expected the hydrator to normalise the hook failure.');
        } catch (ValidationException $exception) {
            $previous = $exception->getPrevious();
            self::assertNull($exception->getField());
            self::assertNotNull($previous);
            self::assertInstanceOf(InvalidArgumentException::class, $previous);
        }
    }

    public function testScalarHandedToObjectParameterIsRejected(): void
    {
        $hydrator = new PropertyHookHydrator(OptionalObjectDto::class);

        $this->expectException(ValidationException::class);

        $hydrator->hydrate(['when' => 'not-a-date']);
    }

    public function testTargetWithoutConstructorIsRejected(): void
    {
        $hydrator = new PropertyHookHydrator(NoConstructorDto::class);

        $this->expectException(ValidationException::class);

        $hydrator->hydrate([]);
    }

    public function testNonExistentTargetIsRejected(): void
    {
        /** @var class-string $missing */
        $missing = 'WaffleTests\\Commons\\Data\\Fixture\\DoesNotExist';
        $hydrator = new PropertyHookHydrator($missing);

        $this->expectException(ValidationException::class);

        $hydrator->hydrate([]);
    }

    /**
     * @param class-string                              $target
     * @param array<string, int|float|string|bool|null> $row
     */
    private function assertRejectedField(string $target, array $row, string $expectedField): void
    {
        try {
            new PropertyHookHydrator($target)->hydrate($row);
            self::fail('Expected a validation failure for field ' . $expectedField . '.');
        } catch (ValidationExceptionInterface $exception) {
            self::assertSame($expectedField, $exception->getField());
            self::assertStringContainsString('must be of type', $exception->getMessage());
        }
    }
}
