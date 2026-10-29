<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutor;

use WilyCode\Database\Driver\DatabaseInterface;
use WilyCode\Database\Driver\PlatformProviderInterface;
use WilyCode\Database\Query\Binding\BinderInterface;
use WilyCode\Database\Query\Dialect\DialectInterface;
use WilyCode\Database\Query\Dialect\MySqlDialect;
use WilyCode\Database\Query\Dialect\PostgreSqlDialect;
use WilyCode\Database\Query\Dialect\SqliteDialect;
use WilyCode\Database\Query\Statement\QueryInterface;

final readonly class QueryExecutor implements QueryExecutorInterface
{
    public function __construct(
        private DatabaseInterface $database,
        private BinderInterface $binder,
        private ?DialectInterface $dialect = null,
    ) {}

    private function resolveDialect(): DialectInterface
    {
        if ($this->dialect !== null) {
            return $this->dialect;
        }
        if (!$this->database instanceof PlatformProviderInterface) {
            throw new \LogicException('The driver must implement PlatformProviderInterface or receive an explicit dialect.');
        }
        return match ($this->database->getPlatformName()) {
            'mysql' => new MySqlDialect(),
            'pgsql' => new PostgreSqlDialect(),
            'sqlite' => new SqliteDialect(),
            default => throw new \LogicException('Unsupported database platform: ' . $this->database->getPlatformName()),
        };
    }

    public function execute(QueryInterface $query): int
    {
        $bound = $this->binder->bind($query->compile($this->resolveDialect()));

        return $this->database->execute($bound->sql, $bound->params, $bound->types);
    }

    public function fetch(QueryInterface $query): array
    {
        $bound = $this->binder->bind($query->compile($this->resolveDialect()));

        return $this->database->fetch($bound->sql, $bound->params, $bound->types);
    }

    public function fetchAll(QueryInterface $query): array
    {
        $bound = $this->binder->bind($query->compile($this->resolveDialect()));

        return $this->database->fetchAll($bound->sql, $bound->params, $bound->types);
    }

    public function fetchColumn(QueryInterface $query, int $columnIndex = 0): mixed
    {
        if ($columnIndex < 0) {
            throw new \InvalidArgumentException('Column index cannot be negative.');
        }

        $bound = $this->binder->bind($query->compile($this->resolveDialect()));

        return $this->database->fetchColumn(
            $bound->sql,
            $bound->params,
            $columnIndex,
            $bound->types,
        );
    }

    public function getLastInsertId(): string
    {
        return $this->database->getLastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->database->inTransaction()) {
            return $callback($this);
        }

        return $this->database->transaction(
            fn (DatabaseInterface $_): mixed => $callback($this),
        );
    }

    public function inTransaction(): bool
    {
        return $this->database->inTransaction();
    }
}
