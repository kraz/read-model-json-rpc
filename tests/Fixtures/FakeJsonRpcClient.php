<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc\Tests\Fixtures;

use Kraz\JsonRpcClient\JsonRpcBatchResponse;
use Kraz\JsonRpcClient\JsonRpcClientInterface;
use Kraz\JsonRpcClient\JsonRpcResponse;
use Kraz\ReadModel\ReadResponse;
use Kraz\ReadModelJsonRpc\JsonRpcReadClientInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

use function array_slice;
use function count;
use function json_encode;
use function max;

/**
 * In-memory stub for JSON-RPC client. Slices seeded items based on
 * page/pageSize or limit/offset params sent by DataSource.
 *
 * @implements JsonRpcReadClientInterface<array<string, mixed>>
 */
final class FakeJsonRpcClient implements JsonRpcClientInterface, JsonRpcReadClientInterface
{
    /** @var array<string, mixed>|null */
    private array|null $lastReadParams = null;

    /** @param list<array<string, mixed>> $items */
    public function __construct(private readonly array $items = [])
    {
    }

    /** @return ReadResponse<array<string, mixed>> */
    public function read(array|null $params = null): ReadResponse
    {
        $this->lastReadParams = $params;

        $total = count($this->items);

        if (isset($params['page'], $params['pageSize'])) {
            $page     = (int) $params['page'];
            $pageSize = (int) $params['pageSize'];
            $offset   = ($page - 1) * $pageSize;
            $slice    = array_slice($this->items, $offset, $pageSize);

            return ReadResponse::create($slice, max(1, $page), $total);
        }

        if (isset($params['limit'])) {
            $limit  = (int) $params['limit'];
            $offset = isset($params['offset']) ? (int) $params['offset'] : 0;
            $slice  = array_slice($this->items, $offset, $limit);

            return ReadResponse::create($slice, 1, $total);
        }

        return ReadResponse::create($this->items, 1, $total);
    }

    /** @phpstan-param array<string, mixed> $params */
    public function call(string $method, array $params = [], int|null $id = null): JsonRpcResponse
    {
        $factory  = new Psr17Factory();
        $payload  = ['id' => $id, 'result' => null];
        $body     = $factory->createStream((string) json_encode($payload));
        $response = $factory->createResponse(200)->withBody($body);

        return new JsonRpcResponse($response);
    }

    /** @phpstan-param array<string, mixed> $params */
    public function notify(string $method, array $params = []): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory->createResponse(204);
    }

    /** @phpstan-param array<array-key, array{id?: int, method: string, params?: array<string, mixed>}> $requests */
    public function batch(array $requests): JsonRpcBatchResponse
    {
        $factory  = new Psr17Factory();
        $body     = $factory->createStream('[]');
        $response = $factory->createResponse(200)->withBody($body);

        return new JsonRpcBatchResponse($response, $factory, $factory);
    }

    /** @return array<string, mixed>|null */
    public function getLastReadParams(): array|null
    {
        return $this->lastReadParams;
    }
}
