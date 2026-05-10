<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc\Tests\Fixtures;

use Kraz\JsonRpcClient\JsonRpcClientInterface;
use Kraz\ReadModel\ReadDataProviderInterface;
use Kraz\ReadModelJsonRpc\DataSource;
use Kraz\ReadModelJsonRpc\DataSourceBuilder;
use Kraz\ReadModelJsonRpc\JsonRpcReadClientInterface;
use Kraz\ReadModelJsonRpc\JsonRpcReadDataProvider;

/**
 * Mirrors the production read model pattern from T28/AssortmentsReadModel.
 * Uses JsonRpcReadDataProvider, defines FIELD_* constants, and wires the API
 * through createDataSource().
 *
 * @implements ReadDataProviderInterface<array<string, mixed>>
 */
final class AssortmentsReadModelFixture implements ReadDataProviderInterface
{
    /** @use JsonRpcReadDataProvider<array<string, mixed>> */
    use JsonRpcReadDataProvider;

    public const string FIELD_ID   = 'id';
    public const string FIELD_CODE = 'code';
    public const string FIELD_NAME = 'name';
    public const string FIELD_EAN  = 'ean';

    /** @param JsonRpcClientInterface&JsonRpcReadClientInterface<array<string, mixed>> $api */
    public function __construct(
        private readonly JsonRpcClientInterface&JsonRpcReadClientInterface $api,
    ) {
    }

    /** @return DataSource<array<string, mixed>> */
    protected function createDataSource(): DataSource
    {
        return (new DataSourceBuilder())
            ->withRootIdentifier(self::FIELD_ID)
            ->create($this->api);
    }
}
