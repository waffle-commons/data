<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Exception;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface;
use Waffle\Commons\Contracts\Data\Exception\SecurityPathViolationExceptionInterface;
use Waffle\Commons\Contracts\Data\Exception\UnauthenticatedAccessExceptionInterface;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Exception\SecurityPathViolationException;
use Waffle\Commons\Data\Exception\UnauthenticatedAccessException;
use Waffle\Commons\Data\Exception\ValidationException;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(DatabaseException::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(SecurityPathViolationException::class)]
#[CoversClass(UnauthenticatedAccessException::class)]
final class ExceptionsTest extends AbstractTestCase
{
    public function testDatabaseExceptionExposesSqlState(): void
    {
        $exception = new DatabaseException('boom', 'HY000');

        self::assertInstanceOf(DatabaseExceptionInterface::class, $exception);
        self::assertSame('boom', $exception->getMessage());
        self::assertSame('HY000', $exception->getSqlState());
    }

    public function testDatabaseExceptionSqlStateDefaultsToNull(): void
    {
        self::assertNull(new DatabaseException('boom')->getSqlState());
    }

    /**
     * @throws PDOException When the in-memory connection cannot be opened.
     */
    public function testFromThrowableLiftsSqlStateFromPdoFailure(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $pdo->query('SELECT * FROM missing_table');
            self::fail('Expected a PDOException for the missing table.');
        } catch (PDOException $error) {
            $wrapped = DatabaseException::fromThrowable($error);

            self::assertNotNull($wrapped->getSqlState());
            self::assertSame($error, $wrapped->getPrevious());
            self::assertSame($error->getMessage(), $wrapped->getMessage());
        }
    }

    public function testFromThrowableWithNonPdoErrorHasNoSqlState(): void
    {
        $origin = new RuntimeException('driver said no');

        $wrapped = DatabaseException::fromThrowable($origin, 'wrapped');

        self::assertNull($wrapped->getSqlState());
        self::assertSame('wrapped', $wrapped->getMessage());
        self::assertSame($origin, $wrapped->getPrevious());
    }

    public function testValidationExceptionExposesField(): void
    {
        $exception = new ValidationException('bad', 'email');

        self::assertInstanceOf(ValidationExceptionInterface::class, $exception);
        self::assertSame('email', $exception->getField());
    }

    public function testValidationExceptionFieldDefaultsToNull(): void
    {
        self::assertNull(new ValidationException('bad')->getField());
    }

    public function testSecurityPathViolationIsADatabaseException(): void
    {
        $exception = new SecurityPathViolationException('root collection forbidden');

        self::assertInstanceOf(SecurityPathViolationExceptionInterface::class, $exception);
        self::assertInstanceOf(DatabaseExceptionInterface::class, $exception);
        self::assertNull($exception->getSqlState());
    }

    public function testUnauthenticatedAccessIsADatabaseException(): void
    {
        $exception = new UnauthenticatedAccessException('auth required');

        self::assertInstanceOf(UnauthenticatedAccessExceptionInterface::class, $exception);
        self::assertInstanceOf(DatabaseExceptionInterface::class, $exception);
        self::assertNull($exception->getSqlState());
    }
}
