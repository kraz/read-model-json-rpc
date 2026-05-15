<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use InvalidArgumentException;
use Kraz\JsonRpcClient\JsonRpcBatchResponse;
use Kraz\JsonRpcClient\JsonRpcClientInterface;
use Kraz\JsonRpcClient\JsonRpcResponse;
use Kraz\ReadModel\CursorReadResponse;
use Kraz\ReadModel\Query\QueryExpression;
use Kraz\ReadModel\Tools\CollectionUtils;
use Override;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_values;
use function count;
use function get_debug_type;
use function is_array;
use function is_object;
use function is_scalar;
use function json_encode;
use function reset;
use function sprintf;
use function str_ends_with;

/**
 * Basic behavior for working with JsonRpcClientInterface
 */
abstract class JsonRpcClientGateway implements JsonRpcClientInterface
{
    public function __construct(
        protected readonly JsonRpcClientInterface $api,
        protected readonly JsonRpcDenormalizerInterface $denormalizer,
    ) {
    }

    #[Override]
    public function call(string $method, array $params = [], int|null $id = null): JsonRpcResponse
    {
        return $this->api->call($method, $params, $id);
    }

    #[Override]
    public function notify(string $method, array $params = []): ResponseInterface
    {
        return $this->api->notify($method, $params);
    }

    #[Override]
    public function batch(array $requests): JsonRpcBatchResponse
    {
        return $this->api->batch($requests);
    }

    /**
     * @phpstan-param array<string, mixed> $params
     * @phpstan-param class-string<T> $responseClassName
     *
     * @phpstan-return ($responseClassName is class-string<T> ? T : array<string, mixed>|T[])
     *
     * @phpstan-template T of object
     */
    protected function handleRequest(string $method, array $params, string|null $responseClassName = null): object|array|null
    {
        $response = $this->api->call($method, $params);

        /** @var array<string, mixed>|null $result */
        $result = $response->getResult();

        if ($responseClassName !== null) {
            /** @var T|T[] $readResponse */
            $readResponse = $this->denormalizer->denormalize($result, $responseClassName);
            if (str_ends_with($responseClassName, '[]')) {
                if (! is_array($readResponse)) {
                    throw new InvalidArgumentException(sprintf('Expected an array. Got: %s', get_debug_type($readResponse)));
                }
            } else {
                if (! ($readResponse instanceof $responseClassName)) {
                    throw new InvalidArgumentException(sprintf('Expected an instance of %s. Got: %s', $responseClassName, get_debug_type($readResponse)));
                }
            }
        } else {
            $readResponse = $result;
        }

        return $readResponse;
    }

    /**
     * @phpstan-param array<string, mixed>|null $params
     * @phpstan-param class-string<T> $responseClassName
     *
     * @phpstan-return ($responseClassName is class-string<T> ? T : array<string, mixed>|null)
     *
     * @phpstan-template T of object
     */
    protected function handleRead(array|null $params = null, string|null $responseClassName = null): object|array|null
    {
        $params ??= ['page' => 1, 'pageSize' => 10];

        $response = $this->api->call('read', $params);

        /** @var array<string, mixed>|null $result */
        $result = $response->getResult();

        if ($responseClassName !== null) {
            $readResponse = $this->denormalizer->denormalize($result, $responseClassName);
            if (! ($readResponse instanceof $responseClassName)) {
                throw new InvalidArgumentException(sprintf('Expected an instance of %s. Got: %s', $responseClassName, get_debug_type($readResponse)));
            }
        } else {
            $readResponse = $result;
        }

        return $readResponse;
    }

    /**
     * Companion to {@see self::handleRead()} for cursor-paginated reads. The server
     * is expected to interpret cursor params (typically `cursor`/`cursorLimit`) and
     * respond with a serialized {@see CursorReadResponse}; this method just
     * denormalizes the result into the requested class.
     *
     * @phpstan-param array<string, mixed>|null $params
     * @phpstan-param class-string<T>           $responseClassName
     *
     * @phpstan-return T
     *
     * @phpstan-template T of CursorReadResponse<array<string, mixed>|object>
     */
    protected function handleReadCursor(array|null $params, string $responseClassName = CursorReadResponse::class): CursorReadResponse
    {
        $response = $this->api->call('read', $params ?? []);

        /** @var array<string, mixed>|null $result */
        $result = $response->getResult();

        $readResponse = $this->denormalizer->denormalize($result, $responseClassName);
        if (! ($readResponse instanceof $responseClassName)) {
            throw new InvalidArgumentException(sprintf('Expected an instance of %s. Got: %s', $responseClassName, get_debug_type($readResponse)));
        }

        return $readResponse;
    }

    /**
     * @phpstan-param list<int|string> $values
     * @phpstan-param list<int|string>|null $missingValues
     * @phpstan-param class-string<T>|null $itemClassName
     *
     * @phpstan-return ($itemClassName is class-string<T> ? T[] : list<array<string, mixed>>)
     *
     * @phpstan-template T of object
     */
    protected function handleReadMultipleValues(array $values, array|null &$missingValues = null, string|null $itemClassName = null, string|null $indexField = null, bool $strict = false): array
    {
        $params = ['values' => $values];

        $response = $this->api->call('read', $params);

        /** @var array<int, array<string, mixed>>|null $result */
        $result = $response->getResult();
        $result = is_array($result) && array_values($result) === $result ? $result : [];
        if (count($result) === 0) {
            return [];
        }

        if ($itemClassName !== null) {
            $readResponse = [];
            foreach ($result as $item) {
                $element = $this->denormalizer->denormalize($item, $itemClassName);
                if (! ($element instanceof $itemClassName)) {
                    throw new InvalidArgumentException(sprintf('Expected an instance of %s. Got: %s', $itemClassName, get_debug_type($element)));
                }

                $readResponse[] = $element;
            }
        } else {
            $readResponse = $result;
        }

        $missingValues = [];

        if ($indexField !== null) {
            $readResponse = CollectionUtils::sortByIndex($readResponse, $indexField, $values);
        }

        return $readResponse;
    }

    /**
     * @phpstan-param class-string<T>|null $responseClassName
     *
     * @phpstan-return ($responseClassName is class-string<T> ? T|null : array<string, mixed>|T[]|null)
     *
     * @phpstan-template T of object
     */
    protected function handleReadSingleValue(mixed $value, string|null $responseClassName = null): object|array|null
    {
        $params = ['value' => $value];

        $response = $this->api->call('read', $params);

        /** @var array<int, array<string, mixed>>|null $result */
        $result = $response->getResult();
        $result = is_array($result) && array_values($result) === $result ? reset($result) : null;
        if (! is_array($result) || count($result) === 0) {
            return null;
        }

        if ($responseClassName !== null) {
            /** @var T|T[] $readResponse */
            $readResponse = $this->denormalizer->denormalize($result, $responseClassName);
            if (str_ends_with($responseClassName, '[]')) {
                if (! is_array($readResponse)) {
                    throw new InvalidArgumentException(sprintf('Expected an array. Got: %s', get_debug_type($readResponse)));
                }
            } else {
                if (! ($readResponse instanceof $responseClassName)) {
                    throw new InvalidArgumentException(sprintf('Expected an instance of %s. Got: %s', $responseClassName, get_debug_type($readResponse)));
                }
            }
        } else {
            $readResponse = $result;
        }

        return $readResponse;
    }

    /**
     * @phpstan-param array<string, float|int|string|null> $criteria
     * @phpstan-param class-string<T>|null $responseClassName
     *
     * @phpstan-return ($responseClassName is class-string<T> ? T|null : array<string,mixed>|null)
     *
     * @phpstan-template T of object
     */
    protected function handleReadSingleValueByCriteria(array $criteria, string|null $responseClassName = null): object|array|null
    {
        if (count($criteria) === 0) {
            return null;
        }

        $query = QueryExpression::create();
        $expr  = $query->expr();
        foreach ($criteria as $field => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('Expected a scalar or null. Got: %s', get_debug_type($value)));
            }

            $query = $query->andWhere($value === null ? $expr->isNull($field) : $expr->equalTo($field, $value));
        }

        /** @var array<string, mixed> $params */
        $params             = $query->toArray();
        $params['page']     = 1;
        $params['pageSize'] = 2;
        /** @var T|array<string,mixed>|null $readResponse */
        $readResponse = $this->handleRead($params, $responseClassName);
        /** @var array<string, mixed> $data */
        $data = is_object($readResponse) ? ($readResponse->data ?? []) : ($readResponse['data'] ?? []);
        if (count($data) > 1) {
            $json = @json_encode($criteria);

            throw new RuntimeException(sprintf('There are more than one record found for the given criteria "%s"', $json === false ? '' : $json));
        }

        return $readResponse;
    }
}
