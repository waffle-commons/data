<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class AbstractTestCase extends BaseTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
