<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\Driver\DatabaseInterface;
use WilyCode\Database\Query\Binding\BinderInterface;
use WilyCode\Database\Query\Binding\BoundQuery;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\QueryExecutor\QueryExecutor;

final class QueryExecutorTest extends TestCase
{
    public function testExecuteBindsQueryAndReturnsAffectedRows(): void
    {
        [$query, $binder] = $this->queryAndBinder(
            new CompiledQuery('UPDATE users SET active = :active', [':active' => true], []),
            new BoundQuery('UPDATE users SET active = ?', [1], ['i']),
        );

        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())
            ->method('execute')
            ->with('UPDATE users SET active = ?', [1], ['i'])
            ->willReturn(3);

        self::assertSame(3, new QueryExecutor($database, $binder)->execute($query));
    }

    public function testReadMethodsDelegateBoundQuery(): void
    {
        [$query, $binder] = $this->queryAndBinder(
            new CompiledQuery('SELECT id, name FROM users WHERE id = :id', [':id' => 7], []),
            new BoundQuery('SELECT id, name FROM users WHERE id = ?', [7], ['i']),
        );

        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())
            ->method('fetch')
            ->with('SELECT id, name FROM users WHERE id = ?', [7], ['i'])
            ->willReturn(['id' => 7, 'name' => 'Ada']);

        self::assertSame(
            ['id' => 7, 'name' => 'Ada'],
            new QueryExecutor($database, $binder)->fetch($query),
        );
    }

    public function testFetchAllDelegatesBoundQuery(): void
    {
        [$query, $binder] = $this->queryAndBinder(
            new CompiledQuery('SELECT id FROM users', [], []),
            new BoundQuery('SELECT id FROM users'),
        );

        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())
            ->method('fetchAll')
            ->with('SELECT id FROM users', [], [])
            ->willReturn([['id' => 1], ['id' => 2]]);

        self::assertSame(
            [['id' => 1], ['id' => 2]],
            new QueryExecutor($database, $binder)->fetchAll($query),
        );
    }

    public function testFetchColumnPassesColumnIndex(): void
    {
        [$query, $binder] = $this->queryAndBinder(
            new CompiledQuery('SELECT id, name FROM users', [], []),
            new BoundQuery('SELECT id, name FROM users'),
        );

        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())
            ->method('fetchColumn')
            ->with('SELECT id, name FROM users', [], 1, [])
            ->willReturn('Ada');

        self::assertSame(
            'Ada',
            new QueryExecutor($database, $binder)->fetchColumn($query, 1),
        );
    }

    public function testLastInsertIdDelegatesToDriver(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('getLastInsertId')->willReturn('42');

        self::assertSame(
            '42',
            new QueryExecutor($database, $this->createStub(BinderInterface::class))->getLastInsertId(),
        );
    }

    public function testTransactionStartsAtDriverAndPassesExecutor(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('inTransaction')->willReturn(false);
        $database->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback($database));

        $executor = new QueryExecutor($database, $this->createStub(BinderInterface::class));
        $received = null;

        $result = $executor->transaction(function ($transaction) use (&$received): string {
            $received = $transaction;
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame($executor, $received);
    }

    public function testTransactionJoinsAnActiveTransaction(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('inTransaction')->willReturn(true);
        $database->expects(self::never())->method('transaction');

        $executor = new QueryExecutor($database, $this->createStub(BinderInterface::class));

        self::assertSame('joined', $executor->transaction(
            static fn ($transaction): string => $transaction === $executor ? 'joined' : 'invalid',
        ));
    }

    public function testInTransactionDelegatesToDriver(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('inTransaction')->willReturn(true);

        self::assertTrue(
            new QueryExecutor($database, $this->createStub(BinderInterface::class))->inTransaction(),
        );
    }

    /** @return array{QueryInterface, BinderInterface} */
    private function queryAndBinder(CompiledQuery $compiled, BoundQuery $bound): array
    {
        $query = $this->createMock(QueryInterface::class);
        $query->expects(self::once())->method('compile')->willReturn($compiled);

        $binder = $this->createMock(BinderInterface::class);
        $binder->expects(self::once())->method('bind')->with($compiled)->willReturn($bound);

        return [$query, $binder];
    }
}
