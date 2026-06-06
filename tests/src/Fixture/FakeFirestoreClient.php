<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Data\Driver\Firestore\FirestoreClientInterface;

/**
 * In-memory Firestore transport: persists documents per path so a save → read
 * round-trip behaves like a live store, and records the filters/limit each
 * `queryCollection` received so tests can prove only equality reached the
 * driver (Rule 2). Auto-assigns sequential ids on a null-id `setDocument`.
 */
final class FakeFirestoreClient implements FirestoreClientInterface
{
    /** @var array<string, array<string, array<string, int|float|string|bool|null>>> path → id → row */
    private array $documents = [];

    /** @var list<array{path: string, filters: list<array{field: string, op: string, value: int|float|string|bool|null}>, limit: int|null}> */
    public array $queries = [];

    private int $autoId = 0;

    #[\Override]
    public function getDocument(string $path, string $id): ?array
    {
        return $this->documents[$path][$id] ?? null;
    }

    #[\Override]
    public function queryCollection(string $path, array $filters, ?int $limit): array
    {
        $this->queries[] = ['path' => $path, 'filters' => $filters, 'limit' => $limit];

        $rows = array_values($this->documents[$path] ?? []);

        // Honour only the equality filters the driver is allowed to apply.
        foreach ($filters as $filter) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => ($row[$filter['field']] ?? null) === $filter['value'],
            ));
        }

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    #[\Override]
    public function setDocument(string $path, ?string $id, array $row): string
    {
        $id ??= 'auto-' . ++$this->autoId;
        $this->documents[$path][$id] = $row;

        return $id;
    }

    #[\Override]
    public function deleteDocument(string $path, string $id): void
    {
        unset($this->documents[$path][$id]);
    }

    /**
     * The most recent query the repository pushed to the driver — for proving
     * Rule 2 (only equality + bounded limit ever reach the server).
     *
     * @return array{path: string, filters: list<array{field: string, op: string, value: int|float|string|bool|null}>, limit: int|null}
     */
    public function lastQuery(): array
    {
        $last = $this->queries[count($this->queries) - 1] ?? null;
        if ($last === null) {
            throw new \RuntimeException('No query has reached the Firestore driver yet.');
        }

        return $last;
    }
}
