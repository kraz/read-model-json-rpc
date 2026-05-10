<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc\Tests;

use InvalidArgumentException;
use Kraz\ReadModel\Query\QueryExpression;
use Kraz\ReadModelJsonRpc\DataSource;
use Kraz\ReadModelJsonRpc\DataSourceBuilder;
use Kraz\ReadModelJsonRpc\Tests\Fixtures\FakeJsonRpcClient;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_column;

#[CoversClass(DataSourceBuilder::class)]
final class DataSourceBuilderTest extends TestCase
{
    private function makeBuilder(): DataSourceBuilder
    {
        return new DataSourceBuilder();
    }

    private function makeClient(): FakeJsonRpcClient
    {
        return new FakeJsonRpcClient();
    }

    // -------------------------------------------------------------------------
    // Immutability
    // -------------------------------------------------------------------------

    public function testWithDataReturnsNewInstance(): void
    {
        $builder = $this->makeBuilder();
        $clone   = $builder->withData($this->makeClient());

        self::assertNotSame($builder, $clone);
    }

    public function testOriginalBuilderIsNotModifiedByWithData(): void
    {
        $builder = $this->makeBuilder();
        $builder->withData($this->makeClient());

        $this->expectException(InvalidArgumentException::class);
        $builder->create();
    }

    // -------------------------------------------------------------------------
    // create() validation
    // -------------------------------------------------------------------------

    public function testCreateThrowsWhenNoDataIsAssigned(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no data assigned');

        $this->makeBuilder()->create();
    }

    public function testCreateThrowsForUnsupportedDataType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported datasource data');

        /** @phpstan-ignore argument.type */
        $this->makeBuilder()->create(new stdClass());
    }

    public function testCreateWithClientPassedDirectlyReturnsDataSource(): void
    {
        $client     = $this->makeClient();
        $dataSource = $this->makeBuilder()->create($client);

        self::assertInstanceOf(DataSource::class, $dataSource);
    }

    public function testCreateWithDataViaWithDataReturnsDataSource(): void
    {
        $client     = $this->makeClient();
        $dataSource = $this->makeBuilder()->withData($client)->create();

        self::assertInstanceOf(DataSource::class, $dataSource);
    }

    public function testCreatePassesDirectDataOverWithData(): void
    {
        $storedClient = $this->makeClient();
        $directClient = $this->makeClient();

        // create() with explicit client overrides the stored one
        $dataSource = $this->makeBuilder()->withData($storedClient)->create($directClient);

        self::assertInstanceOf(DataSource::class, $dataSource);
    }

    // -------------------------------------------------------------------------
    // Root identifier forwarded to DataSource
    // -------------------------------------------------------------------------

    public function testWithRootIdentifierIsAppliedToDataSource(): void
    {
        $client = new FakeJsonRpcClient([
            ['id' => 'A001', 'name' => 'Alpha'],
            ['id' => 'A002', 'name' => 'Beta'],
        ]);

        $ds = $this->makeBuilder()
            ->withRootIdentifier('id')
            ->create($client);

        $qe = QueryExpression::create()->withValues(['A002', 'A001']);
        $ds = $ds->withQueryExpression($qe);

        self::assertSame(['A002', 'A001'], array_column($ds->data(), 'id'));
    }

    // -------------------------------------------------------------------------
    // handleRequest throws
    // -------------------------------------------------------------------------

    public function testHandleRequestThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);

        $this->makeBuilder()->handleRequest(new stdClass());
    }
}
