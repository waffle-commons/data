<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Throwable;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanKind;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanStatus;
use Waffle\Commons\Contracts\Telemetry\NullSpanContext;
use Waffle\Commons\Contracts\Telemetry\SpanContextInterface;
use Waffle\Commons\Contracts\Telemetry\SpanInterface;

/**
 * Recording {@see SpanInterface} double: captures attributes, the recorded
 * exception, the terminal status, and how many times it was ended so the data
 * repository tracing can be asserted without a real SDK.
 */
final class RecordingSpan implements SpanInterface
{
    /** @var array<string, string|int|float|bool> */
    public array $attributes = [];

    public ?Throwable $exception = null;

    public ?SpanStatus $status = null;

    public int $ended = 0;

    public function __construct(
        public readonly string $name,
        public readonly SpanKind $kind,
    ) {}

    #[\Override]
    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->attributes[$key] = $value;
    }

    #[\Override]
    public function recordException(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    #[\Override]
    public function setStatus(SpanStatus $status): void
    {
        $this->status = $status;
    }

    #[\Override]
    public function context(): SpanContextInterface
    {
        return new NullSpanContext();
    }

    #[\Override]
    public function end(): void
    {
        ++$this->ended;
    }
}
