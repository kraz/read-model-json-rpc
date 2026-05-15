<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use ArrayIterator;
use Kraz\ReadModel\CursorReadResponse;
use Kraz\ReadModel\Pagination\Cursor\CursorPaginatorInterface;
use Kraz\ReadModel\Pagination\Cursor\Direction;
use LogicException;
use Override;
use ReturnTypeWillChange;
use Traversable;

use function count;

/**
 * Thin wrapper exposing a server-issued {@see CursorReadResponse} via
 * {@see CursorPaginatorInterface}.
 *
 * The JSON-RPC client never computes keyset state itself — the server owns the
 * cursor codec, the keyset query and the next/previous tokens. This adapter
 * exists solely to satisfy the paginator interface; everything it returns is
 * read straight from the response. Direction is reported as {@see Direction::FORWARD}
 * because the response always delivers items in their natural sort order and
 * does not carry the navigation direction over the wire.
 *
 * @phpstan-template-covariant T of object|array<string, mixed>
 * @phpstan-implements CursorPaginatorInterface<T>
 */
final class JsonRpcCursorPaginator implements CursorPaginatorInterface
{
    /**
     * @phpstan-param CursorReadResponse<T> $response
     * @phpstan-param int<1, max>           $limit
     */
    public function __construct(
        private readonly CursorReadResponse $response,
        private readonly int $limit,
    ) {
        if ($limit < 1) {
            throw new LogicException('Cursor limit must be a positive integer.');
        }
    }

    #[Override]
    public function getLimit(): int
    {
        return $this->limit;
    }

    #[Override]
    public function getDirection(): Direction
    {
        return Direction::FORWARD;
    }

    #[Override]
    public function hasNext(): bool
    {
        return $this->response->hasNext;
    }

    #[Override]
    public function hasPrevious(): bool
    {
        return $this->response->hasPrevious;
    }

    #[Override]
    public function getNextCursor(): string|null
    {
        return $this->response->nextCursor;
    }

    #[Override]
    public function getPreviousCursor(): string|null
    {
        return $this->response->previousCursor;
    }

    #[Override]
    public function getTotalItems(): int|null
    {
        return $this->response->totalItems;
    }

    /** @return Traversable<array-key, T> */
    #[ReturnTypeWillChange]
    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->response->data ?? []);
    }

    #[Override]
    public function count(): int
    {
        return count($this->response->data ?? []);
    }
}
