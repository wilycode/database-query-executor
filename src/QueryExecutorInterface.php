<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutor;

use WilyCode\Database\Driver\Exception\DatabaseException;
use WilyCode\Database\Query\Statement\QueryInterface;

interface QueryExecutorInterface
{
    /** @throws DatabaseException */
    public function execute(QueryInterface $query): int;

    /**
     * @return array<string, mixed>
     * @throws DatabaseException
     */
    public function fetch(QueryInterface $query): array;

    /**
     * @return list<array<string, mixed>>
     * @throws DatabaseException
     */
    public function fetchAll(QueryInterface $query): array;

    /** @throws DatabaseException */
    public function fetchColumn(QueryInterface $query, int $columnIndex = 0): mixed;

    /** @throws DatabaseException */
    public function getLastInsertId(): string;

    /**
     * Guarantees that the callback runs inside a transaction.
     * An active transaction is joined rather than nested.
     *
     * @template T
     * @param callable(self):T $callback
     * @return T
     * @throws DatabaseException
     * @throws \Throwable
     */
    public function transaction(callable $callback): mixed;

    public function inTransaction(): bool;
}
