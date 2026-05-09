<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use Kraz\JsonRpcClient\JsonRpcClientInterface;
use Kraz\ReadModel\ReadDataProviderBuilder;
use Kraz\ReadModel\ReadDataProviderBuilderInterface;
use Kraz\ReadModel\ReadDataProviderCompositionInterface;

/**
 * @phpstan-template-covariant T of object|array<string, mixed> = array<string, mixed>
 *
 * @implements ReadDataProviderCompositionInterface<T>
 * @implements ReadDataProviderBuilderInterface<T>
 */
class DataSourceBuilder implements ReadDataProviderCompositionInterface, ReadDataProviderBuilderInterface
{
    /** @use ReadDataProviderBuilder<T> */
    use ReadDataProviderBuilder;

    /**
     * @var JsonRpcClientInterface&JsonRpcReadClientInterface<T>
     */
    private JsonRpcClientInterface&JsonRpcReadClientInterface $data;

    /**
     * @phpstan-param JsonRpcClientInterface&JsonRpcReadClientInterface<J> $data
     *
     * @phpstan-return static<J>
     *
     * @phpstan-template J of object|array<string, mixed> = array<string, mixed>
     */
    public function withData(JsonRpcClientInterface&JsonRpcReadClientInterface $data): static
    {
        /** @phpstan-var static<J> $clone */
        $clone = clone $this;
        $clone->data = $data;

        return $clone;
    }

    /**
     * @phpstan-param (JsonRpcClientInterface&JsonRpcReadClientInterface<J>)|null $data
     *
     * @return ($data is null ? DataSource<object|array<string, mixed>> : DataSource<J>)
     *
     * @phpstan-template J of object|array<string, mixed> = array<string, mixed>
     */
    public function create(mixed $data = null, string $identifierField = 'id', string $pageParamName = 'page', string $pageSizeParamName = 'pageSize'): DataSource
    {
        $data ??= $this->data;
        if (null === $data) {
            throw new \InvalidArgumentException('The data source has no data assigned! Expected a value other than null.');
        }
        if (!$data instanceof JsonRpcClientInterface || !$data instanceof JsonRpcReadClientInterface) {
            throw new \InvalidArgumentException('Unsupported datasource data!');
        }

        /** @phpstan-var DataSource<J> $dataSource */
        $dataSource = new DataSource($data, $identifierField, $pageParamName, $pageSizeParamName);

        return $this->apply($dataSource);
    }

    #[\Override]
    public function handleRequest(object $request, array $fieldsOperator = [], array $fieldsIgnoreCase = []): static
    {
        throw new \LogicException('Unsupported operation. The data source builder can not handle requests.');
    }
}
