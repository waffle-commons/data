<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Telemetry;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanKind;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanStatus;
use Waffle\Commons\Data\Telemetry\QueryTracer;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\RecordingTracer;

#[CoversClass(QueryTracer::class)]
final class QueryTracerTest extends AbstractTestCase
{
    public function testOpenStartsAClientQuerySpanTaggedWithSystemAndOperation(): void
    {
        $tracer = new RecordingTracer();

        new QueryTracer($tracer, 'sql')->open('find');

        $span = $tracer->last();
        self::assertSame('waffle.db.query', $span->name);
        self::assertSame(SpanKind::Client, $span->kind);
        self::assertSame('sql', $span->attributes['db.system'] ?? null);
        self::assertSame('find', $span->attributes['db.operation'] ?? null);
    }

    public function testFailRecordsTheExceptionSetsErrorStatusAndRethrows(): void
    {
        $tracer = new RecordingTracer();
        $queryTracer = new QueryTracer($tracer, 'mongodb');
        $span = $queryTracer->open('save');
        $error = new RuntimeException('boom');

        try {
            // fail() is declared `never`: it always re-throws the original error.
            $queryTracer->fail($span, $error);
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }

        $recorded = $tracer->last();
        self::assertSame($error, $recorded->exception);
        self::assertSame(SpanStatus::Error, $recorded->status);
    }
}
