<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc\Tests\Fixtures;

use Kraz\JsonRpcClient\JsonRpcBatchResponse;
use Kraz\JsonRpcClient\JsonRpcClientInterface;
use Kraz\JsonRpcClient\JsonRpcResponse;
use Kraz\ReadModel\CursorReadResponse;
use Kraz\ReadModel\DataSource as InMemoryDataSource;
use Kraz\ReadModel\Pagination\Cursor\CursorCodecInterface;
use Kraz\ReadModel\Query\QueryExpression;
use Kraz\ReadModel\ReadResponse;
use Kraz\ReadModelJsonRpc\JsonRpcReadClientInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_slice;
use function count;
use function json_encode;
use function max;

/**
 * In-memory stub for JSON-RPC client. Slices seeded items based on
 * page/pageSize or limit/offset params sent by DataSource. Cursor params are
 * delegated to the in-memory {@see InMemoryDataSource} so the fake exercises
 * the real keyset pagination paths instead of re-implementing them.
 *
 * @implements JsonRpcReadClientInterface<array<string, mixed>>
 */
final class FakeJsonRpcClient implements JsonRpcClientInterface, JsonRpcReadClientInterface
{
    /** @var array<string, mixed>|null */
    private array|null $lastReadParams = null;

    /** @param list<array<string, mixed>> $items */
    public function __construct(
        private readonly array $items = [],
        private readonly CursorCodecInterface|null $cursorCodec = null,
    ) {
    }

    /** @return ReadResponse<array<string, mixed>>|CursorReadResponse<array<string, mixed>> */
    public function read(array|null $params = null): ReadResponse|CursorReadResponse
    {
        $this->lastReadParams = $params;

        if (isset($params['cursor']) || isset($params['cursorLimit'])) {
            return $this->readCursor($params);
        }

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

    /**
     * @param array<string, mixed> $params
     *
     * @return CursorReadResponse<array<string, mixed>>
     */
    private function readCursor(array $params): CursorReadResponse
    {
        /** @var InMemoryDataSource<array<string, mixed>> $ds */
        $ds = new InMemoryDataSource($this->items, cursorCodec: $this->cursorCodec);

        $qe = QueryExpression::create($params);
        if (! $qe->isEmpty()) {
            $ds = $ds->withQueryExpression($qe);
        }

        $cursor      = isset($params['cursor']) ? (string) $params['cursor'] : null;
        $cursorLimit = max(0, (int) ($params['cursorLimit'] ?? 0));
        $ds          = $ds->withCursor($cursor, $cursorLimit);

        /** @var CursorReadResponse<array<string, mixed>>|mixed $result */
        $result = $ds->getResult();
        if (! ($result instanceof CursorReadResponse)) {
            throw new RuntimeException('Expected a CursorReadResponse from the in-memory data source.');
        }

        return $result;
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
