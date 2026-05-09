<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use Kraz\JsonRpcClient\JsonRpcBatchResponse;
use Kraz\JsonRpcClient\JsonRpcClientInterface;
use Kraz\JsonRpcClient\JsonRpcResponse;
use Kraz\ReadModel\Query\QueryExpression;
use Kraz\ReadModel\Tools\CollectionUtils;
use Psr\Http\Message\ResponseInterface;
use Webmozart\Assert\Assert;

abstract class JsonRpcClientGateway implements JsonRpcClientInterface
{
    public function __construct(
        protected readonly JsonRpcClientInterface $api,
        protected readonly JsonRpcDenormalizerInterface $denormalizer,
    ) {
    }

    public function call(string $method, array $params = [], ?int $id = null): JsonRpcResponse
    {
        return $this->api->call($method, $params, $id);
    }

    public function notify(string $method, array $params = []): ResponseInterface
    {
        return $this->api->notify($method, $params);
    }

    public function batch(array $requests): JsonRpcBatchResponse
    {
        return $this->api->batch($requests);
    }

    /**
     * @template T of object
     *
     * @psalm-param array<string, mixed> $params
     * @psalm-param class-string<T> $responseClassName
     *
     * @psalm-return ($responseClassName is class-string<T> ? T : array<string, mixed>|T[])
     */
    protected function handleRequest(string $method, array $params, ?string $responseClassName = null): object|array|null
    {
        $response = $this->api->call($method, $params);

        /** @var array<string, mixed>|null $result */
        $result = $response->getResult();

        if (null !== $responseClassName) {
            /** @var T|T[] $readResponse */
            $readResponse = $this->denormalizer->denormalize($result, $responseClassName);
            if (str_ends_with($responseClassName, '[]')) {
                Assert::isArray($readResponse);
            } else {
                Assert::isInstanceOf($readResponse, $responseClassName);
            }
        } else {
            $readResponse = $result;
        }

        return $readResponse;
    }

    /**
     * @template T of object
     *
     * @psalm-param array<string, mixed>|null $params
     * @psalm-param class-string<T> $responseClassName
     *
     * @psalm-return ($responseClassName is class-string<T> ? T : array<string, mixed>|null)
     */
    protected function handleRead(?array $params = null, ?string $responseClassName = null): object|array|null
    {
        $params ??= ['page' => 1, 'pageSize' => 10];

        $response = $this->api->call('read', $params);

        /** @var array<string, mixed>|null $result */
        $result = $response->getResult();

        if (null !== $responseClassName) {
            /** @var T $readResponse */
            $readResponse = $this->denormalizer->denormalize($result, $responseClassName);
            Assert::isInstanceOf($readResponse, $responseClassName);
        } else {
            $readResponse = $result;
        }

        return $readResponse;
    }

    /**
     * @template T of object
     *
     * @psalm-param scalar[] $values
     * @psalm-param class-string<T>|null $itemClassName
     *
     * @psalm-suppress MixedReturnTypeCoercion
     *
     * @psalm-return ($itemClassName is class-string<T> ? T[] : list<array<string, mixed>>)
     */
    protected function handleReadMultipleValues(array $values, ?array &$missingValues = null, ?string $itemClassName = null, ?string $indexField = null, bool $strict = false): array
    {
        $params = ['values' => $values];

        $response = $this->api->call('read', $params);

        /** @var array<int, array<string, mixed>>|null $result */
        $result = $response->getResult();
        $result = \is_array($result) && array_values($result) === $result ? $result : [];
        if (0 === \count($result)) {
            return [];
        }

        if (null !== $itemClassName) {
            $readResponse = [];
            foreach ($result as $item) {
                /** @var T $element */
                $element = $this->denormalizer->denormalize($item, $itemClassName);
                Assert::isInstanceOf($element, $itemClassName);
                $readResponse[] = $element;
            }
        } else {
            $readResponse = $result;
        }

        $missingValues = [];

        if (null !== $indexField) {
            $readResponse = CollectionUtils::sortByIndex($readResponse, $indexField, $values);
        }

        return $readResponse;
    }

    /**
     * @template T of object
     *
     * @psalm-param class-string<T>|null $responseClassName
     *
     * @psalm-return ($responseClassName is class-string<T> ? T|null : array<string, mixed>|T[]|null)
     */
    protected function handleReadSingleValue(mixed $value, ?string $responseClassName = null): object|array|null
    {
        $params = ['value' => $value];

        $response = $this->api->call('read', $params);

        /** @var array<int, array<string, mixed>>|null $result */
        $result = $response->getResult();
        $result = \is_array($result) && array_values($result) === $result ? reset($result) : null;
        if (!\is_array($result) || 0 === \count($result)) {
            return null;
        }

        if (null !== $responseClassName) {
            /** @var T|T[] $readResponse */
            $readResponse = $this->denormalizer->denormalize($result, $responseClassName);
            if (str_ends_with($responseClassName, '[]')) {
                Assert::isArray($readResponse);
            } else {
                Assert::isInstanceOf($readResponse, $responseClassName);
            }
        } else {
            $readResponse = $result;
        }

        return $readResponse;
    }

    /**
     * @template T of object
     *
     * @psalm-param array<string, float|int|string|null> $criteria
     * @psalm-param class-string<T>|null $responseClassName
     *
     * @psalm-return ($responseClassName is class-string<T> ? T|null : array<string,mixed>|null)
     */
    protected function handleReadSingleValueByCriteria(array $criteria, ?string $responseClassName = null): object|array|null
    {
        if (0 === \count($criteria)) {
            return null;
        }

        $query = QueryExpression::create();
        $expr = $query->expr();
        foreach ($criteria as $field => $value) {
            Assert::nullOrScalar($value);
            $query = $query->andWhere(null === $value ? $expr->isNull($field) : $expr->equalTo($field, $value));
        }

        /** @var array<string, mixed> $params */
        $params = $query->toArray();
        $params['page'] = 1;
        $params['pageSize'] = 2;
        /** @var T|array<string,mixed>|null $readResponse */
        $readResponse = $this->handleRead($params, $responseClassName);
        /** @var array<string, mixed> $data */
        $data = \is_object($readResponse) ? ($readResponse->data ?? []) : ($readResponse['data'] ?? []);
        if (\count($data) > 1) {
            $json = @json_encode($criteria);
            throw new \RuntimeException(\sprintf('There are more than one record found for the given criteria "%s"', false === $json ? '' : $json));
        }

        return $readResponse;
    }
}
