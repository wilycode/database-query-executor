<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\Driver\{DatabaseInterface, PlatformProviderInterface};
use WilyCode\Database\Query\Binding\{BinderInterface, BoundQuery};
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Dialect\{MySqlDialect, PostgreSqlDialect};
use WilyCode\Database\Query\Query;
use WilyCode\Database\QueryExecutor\QueryExecutor;

final class DialectResolutionTest extends TestCase
{
    protected function tearDown(): void { Query::dialectDefault(null); }

    public function testActualPlatformWinsOverProcessDefaultForEveryOperation(): void
    {
        foreach (['mysql' => '`id`', 'pgsql' => '"id"', 'sqlite' => '"id"'] as $platform => $alias) {
            foreach (['execute', 'fetch', 'fetchAll', 'fetchColumn'] as $method) {
                $database = $this->createMockForIntersectionOfInterfaces([DatabaseInterface::class, PlatformProviderInterface::class]);
                $database->method('getPlatformName')->willReturn($platform);
                $binder = $this->createMock(BinderInterface::class);
                $binder->expects(self::once())->method('bind')->willReturnCallback(function (CompiledQuery $q) use ($alias): BoundQuery {
                    self::assertSame('SELECT users.id AS ' . $alias . ' FROM users', $q->sql);
                    return new BoundQuery($q->sql);
                });
                Query::dialectDefault(new PostgreSqlDialect());
                (new QueryExecutor($database, $binder))->$method(Query::select('users')->column('id', 'id'));
            }
        }
    }
    public function testManualDialectBypassesDetection(): void
    {
        $database = $this->createMockForIntersectionOfInterfaces([DatabaseInterface::class, PlatformProviderInterface::class]);
        $database->expects(self::never())->method('getPlatformName');
        $binder = $this->createMock(BinderInterface::class);
        $binder->expects(self::once())->method('bind')->willReturnCallback(function (CompiledQuery $q): BoundQuery {
            self::assertStringContainsString('AS `id`', $q->sql);
            return new BoundQuery($q->sql);
        });
        (new QueryExecutor($database, $binder, new MySqlDialect()))->fetch(Query::select('users')->column('id', 'id'));
    }
    public function testUnknownPlatformNeverFallsBackToMysql(): void
    {
        $database = $this->createMockForIntersectionOfInterfaces([DatabaseInterface::class, PlatformProviderInterface::class]);
        $database->method('getPlatformName')->willReturn('oci');
        $binder = $this->createMock(BinderInterface::class);
        $binder->expects(self::never())->method('bind');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported database platform: oci');
        (new QueryExecutor($database, $binder))->fetch(Query::select('users'));
    }
    public function testDriverWithoutPlatformRequiresExplicitDialect(): void
    {
        $binder = $this->createMock(BinderInterface::class);
        $binder->expects(self::never())->method('bind');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PlatformProviderInterface');
        (new QueryExecutor($this->createMock(DatabaseInterface::class), $binder))->fetch(Query::select('users'));
    }
}
