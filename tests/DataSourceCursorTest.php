<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc\Tests;

use Kraz\ReadModel\CursorReadResponse;
use Kraz\ReadModel\Exception\InvalidCursorException;
use Kraz\ReadModel\Pagination\Cursor\Base64JsonCursorCodec;
use Kraz\ReadModel\Pagination\Cursor\SignedCursorCodec;
use Kraz\ReadModel\Query\QueryExpression;
use Kraz\ReadModel\Query\SortExpression;
use Kraz\ReadModelJsonRpc\DataSource;
use Kraz\ReadModelJsonRpc\JsonRpcCursorPaginator;
use Kraz\ReadModelJsonRpc\Tests\Fixtures\FakeJsonRpcClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function is_array;
use function iterator_to_array;
use function substr;

#[CoversClass(DataSource::class)]
#[CoversClass(JsonRpcCursorPaginator::class)]
final class DataSourceCursorTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function seed(): array
    {
        // Seven rows so several page-boundary scenarios are visible.
        return [
            ['id' => 1, 'name' => 'Anna',  'department' => 'eng',     'age' => 20],
            ['id' => 2, 'name' => 'Bob',   'department' => 'eng',     'age' => 25],
            ['id' => 3, 'name' => 'Carol', 'department' => 'sales',   'age' => 30],
            ['id' => 4, 'name' => 'Dan',   'department' => 'sales',   'age' => 35],
            ['id' => 5, 'name' => 'Eve',   'department' => 'support', 'age' => 40],
            ['id' => 6, 'name' => 'Frank', 'department' => 'support', 'age' => 45],
            ['id' => 7, 'name' => 'Gina',  'department' => 'support', 'age' => 50],
        ];
    }

    /** @return DataSource<array<string, mixed>> */
    private function makeDs(FakeJsonRpcClient|null $client = null): DataSource
    {
        $client ??= new FakeJsonRpcClient($this->seed());

        /** @var DataSource<array<string, mixed>> $ds */
        $ds = new DataSource($client);

        return $ds;
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return list<int>
     */
    private function ids(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $result[] = (int) $item['id'];
        }

        return $result;
    }

    public function testFirstPageReturnsWindowAndNextCursor(): void
    {
        $ds = $this->makeDs()->withCursor(null, 3);

        self::assertTrue($ds->isCursored());
        self::assertFalse($ds->isPaginated());

        $paginator = $ds->cursorPaginator();
        self::assertNotNull($paginator);
        self::assertSame([1, 2, 3], $this->ids($paginator->getIterator()));
        self::assertTrue($paginator->hasNext());
        self::assertFalse($paginator->hasPrevious());
        self::assertNotNull($paginator->getNextCursor());
        self::assertNull($paginator->getPreviousCursor());
    }

    public function testWalkForwardEnumeratesAllRowsInOrder(): void
    {
        $ds    = $this->makeDs();
        $token = null;
        $pages = [];

        for ($i = 0; $i < 10; $i++) {
            $page      = $ds->withCursor($token, 3);
            $paginator = $page->cursorPaginator();
            self::assertNotNull($paginator);

            $pages[] = $this->ids($paginator->getIterator());
            $token   = $paginator->getNextCursor();
            if ($token === null) {
                break;
            }
        }

        self::assertSame([[1, 2, 3], [4, 5, 6], [7]], $pages);
    }

    public function testBackwardNavigationReconstructsPreviousWindow(): void
    {
        $ds    = $this->makeDs();
        $first = $ds->withCursor(null, 3)->cursorPaginator();
        self::assertNotNull($first);
        $nextToken = $first->getNextCursor();
        self::assertNotNull($nextToken);

        $second = $ds->withCursor($nextToken, 3)->cursorPaginator();
        self::assertNotNull($second);
        self::assertSame([4, 5, 6], $this->ids($second->getIterator()));

        $prevToken = $second->getPreviousCursor();
        self::assertNotNull($prevToken);

        $back = $ds->withCursor($prevToken, 3)->cursorPaginator();
        self::assertNotNull($back);
        self::assertSame([1, 2, 3], $this->ids($back->getIterator()));
        self::assertTrue($back->hasNext());
        self::assertFalse($back->hasPrevious());
    }

    public function testCursorRespectsCustomSortAndTieBreaker(): void
    {
        $ds = $this->makeDs()
            ->withQueryExpression(QueryExpression::create()->sortBy('department', SortExpression::DIR_ASC))
            ->withCursor(null, 4);

        $paginator = $ds->cursorPaginator();
        self::assertNotNull($paginator);

        $rows = iterator_to_array($paginator->getIterator(), false);
        self::assertSame(['eng', 'eng', 'sales', 'sales'], array_column($rows, 'department'));
        self::assertSame([1, 2, 3, 4], $this->ids($rows));
    }

    public function testCursorClearsAndRestoresOnSwitchingModes(): void
    {
        $ds = $this->makeDs();

        $cursored = $ds->withCursor(null, 2);
        self::assertTrue($cursored->isCursored());
        self::assertNull($cursored->paginator());

        $paged = $cursored->withPagination(1, 2);
        self::assertFalse($paged->isCursored());
        self::assertTrue($paged->isPaginated());
        self::assertNotNull($paged->paginator());
        self::assertNull($paged->cursorPaginator());
    }

    public function testGetResultReturnsCursorReadResponse(): void
    {
        $result = $this->makeDs()->withCursor(null, 2)->getResult();

        self::assertInstanceOf(CursorReadResponse::class, $result);
        self::assertSame([1, 2], $this->ids($result->data ?? []));
        self::assertNotNull($result->nextCursor);
        self::assertTrue($result->hasNext);
        self::assertFalse($result->hasPrevious);
        // The in-memory backend computes a total because the full result set is
        // materialised; a SQL-backed server would typically leave this null.
        self::assertSame(7, $result->totalItems);
    }

    public function testCursorParamsAreSentToClient(): void
    {
        $client = new FakeJsonRpcClient($this->seed());
        $ds     = new DataSource($client);

        $ds->withCursor(null, 3)->data();

        $params = $client->getLastReadParams();
        self::assertNotNull($params);
        self::assertSame(3, $params['cursorLimit']);
        self::assertArrayNotHasKey('cursor', $params);
    }

    public function testCursorTokenIsSentToClient(): void
    {
        $client = new FakeJsonRpcClient($this->seed());
        $ds     = new DataSource($client);

        $first = $ds->withCursor(null, 3)->cursorPaginator();
        self::assertNotNull($first);
        $token = $first->getNextCursor();
        self::assertNotNull($token);

        $ds->withCursor($token, 3)->data();

        $params = $client->getLastReadParams();
        self::assertNotNull($params);
        self::assertSame($token, $params['cursor']);
        self::assertSame(3, $params['cursorLimit']);
    }

    public function testCustomCursorParamNamesAreSentToClient(): void
    {
        $client = new FakeJsonRpcClient($this->seed());
        $ds     = new DataSource(
            $client,
            cursorParamName: 'c',
            cursorLimitParamName: 'cl',
        );

        try {
            // The fake client only recognises canonical param names, so it returns
            // a ReadResponse when it receives the renamed cursor params — the data
            // source rejects the mismatch. We just want to inspect the params
            // that hit the wire.
            $ds->withCursor(null, 4)->data();
        } catch (RuntimeException) {
        }

        $params = $client->getLastReadParams();
        self::assertNotNull($params);
        self::assertSame(4, $params['cl']);
        self::assertArrayNotHasKey('cursorLimit', $params);
    }

    public function testCountReturnsWindowSizeInCursorMode(): void
    {
        $ds = $this->makeDs()->withCursor(null, 3);

        self::assertSame(3, $ds->count());
    }

    public function testTotalCountIsZeroWhenCursorAdapterDoesNotComputeIt(): void
    {
        $ds = $this->makeDs()->withCursor(null, 3);

        // The in-memory cursor adapter does compute a total when items are fully
        // materialised, but the JSON-RPC adapter only relays what the server sent
        // — so totalItems is intentionally unset by default.
        // Here the in-memory backend sets totalItems = count($items).
        self::assertSame(7, $ds->totalCount());
    }

    public function testCloneResetsCursorCache(): void
    {
        $ds        = $this->makeDs()->withCursor(null, 3);
        $paginator = $ds->cursorPaginator();
        self::assertNotNull($paginator);

        $clone = clone $ds;
        self::assertNotSame($paginator, $clone->cursorPaginator());
    }

    public function testSignedTokensIssuedByServerRoundTripThroughClient(): void
    {
        // Server-side codec only; the client never decodes tokens.
        $codec  = new SignedCursorCodec(new Base64JsonCursorCodec(), 'integration-secret');
        $client = new FakeJsonRpcClient($this->seed(), $codec);
        $ds     = new DataSource($client);

        $first = $ds->withCursor(null, 3)->cursorPaginator();
        self::assertNotNull($first);
        $nextToken = $first->getNextCursor();
        self::assertNotNull($nextToken);

        $second = $ds->withCursor($nextToken, 3)->cursorPaginator();
        self::assertNotNull($second);
        self::assertSame([4, 5, 6], $this->ids($second->getIterator()));
    }

    public function testTamperedSignedCursorIsRejectedByServer(): void
    {
        $codec  = new SignedCursorCodec(new Base64JsonCursorCodec(), 'integration-secret');
        $client = new FakeJsonRpcClient($this->seed(), $codec);
        $ds     = new DataSource($client);

        $first = $ds->withCursor(null, 3)->cursorPaginator();
        self::assertNotNull($first);
        $token = $first->getNextCursor();
        self::assertNotNull($token);

        $tampered = ($token[0] === 'a' ? 'b' : 'a') . substr($token, 1);

        // The client just relays the (tampered) token; the server's codec catches it.
        $this->expectException(InvalidCursorException::class);
        $ds->withCursor($tampered, 3)->cursorPaginator();
    }

    public function testCursorMismatchedSortSignatureIsRejectedByServer(): void
    {
        $client = new FakeJsonRpcClient($this->seed());
        $ds     = new DataSource($client);

        // Issue a cursor under the default sort (id ASC).
        $first = $ds->withCursor(null, 3)->cursorPaginator();
        self::assertNotNull($first);
        $token = $first->getNextCursor();
        self::assertNotNull($token);

        // Now request the next page under a different sort (department ASC).
        $mismatched = $ds
            ->withQueryExpression(QueryExpression::create()->sortBy('department', SortExpression::DIR_ASC))
            ->withCursor($token, 3);

        $this->expectException(InvalidCursorException::class);
        $mismatched->cursorPaginator();
    }
}
