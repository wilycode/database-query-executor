<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutor\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WilyCode\Database\Driver\DatabaseInterface;
use WilyCode\Database\Query\Binding\BinderInterface;
use WilyCode\Database\Query\Binding\BoundQuery;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\QueryExecutor\QueryExecutor;

final class QueryExecutorEdgeCasesTest extends TestCase
{
    public function testFetchColumnDefaultsToFirstColumn(): void
    {
        [$query, $binder] = $this->boundQuery('SELECT id FROM users');
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('fetchColumn')
            ->with('SELECT id FROM users', [], 0, [])->willReturn(5);

        self::assertSame(5, (new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->fetchColumn($query));
    }

    public function testNegativeColumnIndexIsRejectedBeforeCompiling(): void
    {
        $query = $this->createMock(QueryInterface::class);
        $query->expects(self::never())->method('compile');
        $binder = $this->createMock(BinderInterface::class);
        $binder->expects(self::never())->method('bind');
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::never())->method('fetchColumn');

        $this->expectException(InvalidArgumentException::class);
        (new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->fetchColumn($query, -1);
    }

    public function testFetchReturnsEmptyArrayForNoRow(): void
    {
        [$query, $binder] = $this->boundQuery('SELECT id FROM users');
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('fetch')->willReturn([]);
        self::assertSame([], (new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->fetch($query));
    }

    public function testFetchAllReturnsEmptyArrayForNoRows(): void
    {
        [$query, $binder] = $this->boundQuery('SELECT id FROM users');
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('fetchAll')->willReturn([]);
        self::assertSame([], (new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->fetchAll($query));
    }

    public function testFetchColumnCanReturnNull(): void
    {
        [$query, $binder] = $this->boundQuery('SELECT id FROM users');
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('fetchColumn')->willReturn(null);
        self::assertNull((new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->fetchColumn($query));
    }

    public function testBindExceptionIsNotSwallowed(): void
    {
        $compiled = new CompiledQuery('SELECT 1');
        $query = $this->createMock(QueryInterface::class);
        $query->method('compile')->willReturn($compiled);
        $binder = $this->createMock(BinderInterface::class);
        $binder->method('bind')->willThrowException(new RuntimeException('Invalid binding'));
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::never())->method('execute');

        $this->expectExceptionMessage('Invalid binding');
        (new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->execute($query);
    }

    public function testDriverExceptionIsNotSwallowed(): void
    {
        [$query, $binder] = $this->boundQuery('SELECT id FROM users');
        $database = $this->createMock(DatabaseInterface::class);
        $database->method('fetch')->willThrowException(new RuntimeException('Driver failed'));

        $this->expectExceptionMessage('Driver failed');
        (new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->fetch($query);
    }

    public function testInTransactionCanBeFalse(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('inTransaction')->willReturn(false);
        $binder = $this->createMock(BinderInterface::class);
        self::assertFalse((new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->inTransaction());
    }

    public function testJoinedTransactionPropagatesExceptionsWithoutOpeningNewTransaction(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->method('inTransaction')->willReturn(true);
        $database->expects(self::never())->method('transaction');
        $executor = new QueryExecutor($database, $this->createMock(BinderInterface::class));

        $this->expectExceptionMessage('Callback failed');
        $executor->transaction(static function (): never {
            throw new RuntimeException('Callback failed');
        });
    }

    public function testNewTransactionPropagatesCallbackFailure(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->method('inTransaction')->willReturn(false);
        $database->expects(self::once())->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback($database));
        $executor = new QueryExecutor($database, $this->createMock(BinderInterface::class));

        $this->expectExceptionMessage('Callback failed');
        $executor->transaction(static function (): never {
            throw new RuntimeException('Callback failed');
        });
    }

    public function testBinderIsNotUsedWhenRetrievingLastInsertId(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('getLastInsertId')->willReturn('100');
        $binder = $this->createMock(BinderInterface::class);
        $binder->expects(self::never())->method('bind');

        self::assertSame('100', (new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\MySqlDialect()))->getLastInsertId());
    }

    /** @return array{QueryInterface, BinderInterface} */
    private function boundQuery(string $sql): array
    {
        $compiled = new CompiledQuery($sql);
        $query = $this->createMock(QueryInterface::class);
        $query->expects(self::once())->method('compile')->willReturn($compiled);
        $binder = $this->createMock(BinderInterface::class);
        $binder->expects(self::once())->method('bind')->with($compiled)->willReturn(new BoundQuery($sql));
        return [$query, $binder];
    }
}
