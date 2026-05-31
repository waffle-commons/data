<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use Waffle\Commons\Data\Query\Comparison;
use Waffle\Commons\Data\Query\Operator;
use Waffle\Commons\Data\Query\Query;

/**
 * Compiles an SQR {@see Query} into a {@see CompiledFirestoreQuery} bound to an
 * isolated {@see FirestoreScope}.
 *
 * Firestore is a document store, not a relational engine. Following the
 * persistence design, this compiler keeps the *server-side* query trivial — only
 * equality filters are pushed down — and defers everything Firestore handles
 * poorly (range operators, set membership, ordering) to in-memory processing.
 * Whenever such a clause is present, {@see CompiledFirestoreQuery::$requiresInMemoryFilter}
 * is raised so the repository knows to fetch-simple-then-filter.
 *
 * The query's own `from()` source is ignored: the collection is fixed by the
 * caller-supplied scope, which is the mechanism that prevents root-collection or
 * cross-tenant access.
 */
final class FirestoreCompiler
{
    public function compile(Query $query, FirestoreScope $scope): CompiledFirestoreQuery
    {
        $filters = [];
        $requiresInMemory = false;

        foreach ($query->criteria as $comparison) {
            if ($this->isServerSafe($comparison)) {
                $filters[] = $this->serverFilter($comparison);

                continue;
            }

            // Range / set / pattern predicates are applied in memory.
            $requiresInMemory = true;
        }

        // Firestore compound ordering is intentionally avoided; any ordering is
        // an in-memory concern.
        $orderings = $this->orderings($query);
        if ($orderings !== []) {
            $requiresInMemory = true;
        }

        return new CompiledFirestoreQuery($scope->path, $filters, $orderings, $query->limit, $requiresInMemory);
    }

    /**
     * A predicate is server-safe only when it is a single-value equality test.
     */
    private function isServerSafe(Comparison $comparison): bool
    {
        return $comparison->operator === Operator::Equal;
    }

    /**
     * @return array{field: string, op: string, value: int|float|string|bool|null}
     */
    private function serverFilter(Comparison $comparison): array
    {
        // Equality carries exactly one value (Criteria::eq); fall back to null
        // for a hand-built Comparison so a missing index can never surface.
        return [
            'field' => $comparison->field,
            'op' => 'EQUAL',
            'value' => $comparison->values[0] ?? null,
        ];
    }

    /**
     * @return list<array{field: string, direction: string}>
     */
    private function orderings(Query $query): array
    {
        $orderings = [];
        foreach ($query->orderings as $order) {
            $orderings[] = ['field' => $order->field, 'direction' => $order->direction->value];
        }

        return $orderings;
    }
}
