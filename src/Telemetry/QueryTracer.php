<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Telemetry;

use Throwable;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanKind;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanStatus;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Contracts\Telemetry\SpanInterface;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;

/**
 * Opens and closes the `waffle.db.query` CLIENT span that lets each repository
 * call appear natively in distributed traces (OBS-01) — no optional decorator
 * required. Defaults to the contract no-op tracer (zero cost when telemetry is
 * off), holds no state, and is safe to share across FrankenPHP resident-worker
 * requests.
 *
 * A repository wraps the operation itself ({@see self::open()} → try / catch
 * {@see self::fail()} / finally `end()`), so the throwing call stays inside the
 * method body and the strict `check-throws` analysis is satisfied without any
 * undocumented closure boundary.
 */
final readonly class QueryTracer
{
    public function __construct(
        private TracerInterface $tracer = new NullTracer(),
        private string $system = 'sql',
    ) {}

    /**
     * Start a query span tagged with the backend system and the logical
     * operation (`find`, `findOne`, `stream`, `save`, `delete`, `findById`).
     */
    public function open(string $operation): SpanInterface
    {
        $span = $this->tracer->startSpan('waffle.db.query', SpanKind::Client);
        $span->setAttribute('db.system', $this->system);
        $span->setAttribute('db.operation', $operation);

        return $span;
    }

    /**
     * Record the failure on the span and re-throw the original error untouched.
     *
     * @throws Throwable Always.
     */
    public function fail(SpanInterface $span, Throwable $error): never
    {
        $span->recordException($error);
        $span->setStatus(SpanStatus::Error);

        throw $error;
    }
}
