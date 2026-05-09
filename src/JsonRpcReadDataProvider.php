<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use Kraz\ReadModel\DataSourceReadDataProvider;

/** @phpstan-template-covariant T of object|array<string, mixed> */
trait JsonRpcReadDataProvider
{
    /** @use DataSourceReadDataProvider<T> */
    use DataSourceReadDataProvider;

    /** @phpstan-return DataSource<T> */
    abstract protected function createDataSource(): DataSource;
}
