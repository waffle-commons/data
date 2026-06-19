<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use RuntimeException;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanKind;
use Waffle\Commons\Contracts\Telemetry\SpanContextInterface;
use Waffle\Commons\Contracts\Telemetry\SpanInterface;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;

/**
 * Recording {@see TracerInterface} double: every {@see self::startSpan()} keeps
 * the {@see RecordingSpan} it hands out so a test can assert which query spans a
 * repository opened and how they ended.
 */
final class RecordingTracer implements TracerInterface
{
    /** @var list<RecordingSpan> */
    public array $spans = [];

    private ?RecordingSpan $lastSpan = null;

    #[\Override]
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        ?SpanContextInterface $parent = null,
    ): SpanInterface {
        $span = new RecordingSpan($name, $kind);
        $this->spans[] = $span;
        $this->lastSpan = $span;

        return $span;
    }

    #[\Override]
    public function currentContext(): ?SpanContextInterface
    {
        return null;
    }

    /** The most recently opened span. */
    public function last(): RecordingSpan
    {
        if ($this->lastSpan === null) {
            throw new RuntimeException('No span has been opened.');
        }

        return $this->lastSpan;
    }
}
