<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        $container = Mockery::getContainer();

        if ($container !== null) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }

        Monkey\tearDown();
        parent::tearDown();
    }
}
