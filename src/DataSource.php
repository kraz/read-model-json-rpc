<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use ArrayIterator;
use InvalidArgumentException;
use Kraz\JsonRpcClient\JsonRpcClientInterface;
use Kraz\ReadModel\Pagination\InMemoryPaginator;
use Kraz\ReadModel\Pagination\PaginatorInterface;
use Kraz\ReadModel\Query\QueryExpression;
use Kraz\ReadModel\ReadDataProviderAccess;
use Kraz\ReadModel\ReadDataProviderComposition;
use Kraz\ReadModel\ReadDataProviderCompositionInterface;
use Kraz\ReadModel\ReadDataProviderInterface;
use Kraz\ReadModel\ReadDataProviderPayload;
use Kraz\ReadModel\ReadResponse;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Traversable;

use function array_filter;
use function array_reduce;
use function array_values;
use function class_exists;
use function count;
use function iterator_to_array;
use function parse_str;
use function sprintf;

/**
 * @phpstan-template-covariant T of array<string, mixed>|object
 * @implements ReadDataProviderInterface<T>
 */
class DataSource implements ReadDataProviderInterface
{
    /** @use ReadDataProviderAccess<T> */
    use ReadDataProviderAccess;
    /** @use ReadDataProviderComposition<T> */
    use ReadDataProviderComposition;

    /** @phpstan-var ReadDataProviderPayload<T>|null */
    private ReadDataProviderPayload|null $payload = null;
    /** @phpstan-var PaginatorInterface<T>|null */
    private PaginatorInterface|null $paginatorInstance = null;

    /** @phpstan-param JsonRpcClientInterface&JsonRpcReadClientInterface<T> $client */
    public function __construct(
        private readonly JsonRpcClientInterface&JsonRpcReadClientInterface $client,
        private readonly string $pageParamName = 'page',
        private readonly string $pageSizeParamName = 'pageSize',
        private readonly string $limitParamName = 'limit',
        private readonly string $offsetParamName = 'offset',
    ) {
    }

    /** @return ReadDataProviderPayload<T> */
    private function getPayload(): ReadDataProviderPayload
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        /** @phpstan-var ReadResponse<T> $result */
        $result = $this->client->read($this->getParams());

        /** @phpstan-var ReadDataProviderPayload<T> $payload */
        $payload       = new ReadDataProviderPayload($result);
        $this->payload = $payload;

        return $this->payload;
    }

    private function getWrappedQueryExpression(): QueryExpression|null
    {
        if (count($this->queryExpressions) === 0) {
            return null;
        }

        if (count($this->queryExpressions) === 1) {
            return clone $this->queryExpressions[0];
        }

        return array_reduce($this->queryExpressions, static fn (QueryExpression $qx, QueryExpression $item) => $qx->wrap($item), QueryExpression::create());
    }

    /** @phpstan-return array<string, mixed> */
    private function getParams(): array
    {
        $params = $this->getWrappedQueryExpression()?->toArray() ?? [];
        if (count($params) > 0) {
            $fieldMapping = $this->getOrCreateQueryExpressionProvider()->getFieldMapping();
            if (count($fieldMapping) > 0) {
                $params = QueryExpression::applyFieldMapping($params, $fieldMapping);
            }
        }

        if ($this->pagination !== null) {
            [$page, $itemsPerPage]            = $this->pagination;
            $params[$this->pageParamName]     = $page;
            $params[$this->pageSizeParamName] = $itemsPerPage;
        } elseif ($this->limit !== null) {
            [$limitValue, $offsetValue]    = $this->limit;
            $params[$this->limitParamName] = $limitValue;
            if ($offsetValue !== null && $offsetValue > 0) {
                $params[$this->offsetParamName] = $offsetValue;
            }
        }

        return $params;
    }

    #[Override]
    public function withQueryModifier(callable $modifier, bool $append = false): static
    {
        throw new LogicException('Query modifiers are not supported in the JsonRpc DataSource.');
    }

    /** @return array<int, T> */
    private function filteredItems(): array
    {
        $items = $this->getPayload()->getData();

        if (count($this->specifications) === 0) {
            return $items;
        }

        return array_values(array_filter($items, function (mixed $item): bool {
            foreach ($this->specifications as $specification) {
                /** @phpstan-var T $item */
                if (! $specification->isSatisfiedBy($item)) {
                    return false;
                }
            }

            return true;
        }));
    }

    #[Override]
    public function count(): int
    {
        $this->assertNoSpecifications();

        $paginator = $this->paginator();
        if ($paginator !== null) {
            return $paginator->count();
        }

        return count($this->filteredItems());
    }

    #[Override]
    public function totalCount(): int
    {
        $this->assertNoSpecifications();

        return $this->getPayload()->getTotalItems();
    }

    #[Override]
    public function isPaginated(): bool
    {
        if (count($this->specifications) > 0) {
            return false;
        }

        if ($this->pagination === null) {
            return false;
        }

        [$page, $itemsPerPage] = $this->pagination;

        return $page > 0 && $itemsPerPage > 0;
    }

    #[Override]
    public function isEmpty(): bool
    {
        $this->assertNoSpecifications();

        return $this->totalCount() === 0;
    }

    #[Override]
    public function getIterator(): Traversable
    {
        $specifications = $this->specifications;
        $hasSpecs       = count($specifications) > 0;

        if ($hasSpecs && $this->limit !== null) {
            [$limitValue, $offsetValue] = $this->limit;

            yield from $this->withoutSpecification()->withoutLimit()->specificationsIterator(
                $specifications,
                $limitValue,
                $offsetValue ?? 0,
            );

            return;
        }

        if ($hasSpecs) {
            $items = new ArrayIterator($this->filteredItems());
        } else {
            $paginator = $this->paginator();
            $items     = $paginator?->getIterator() ?? new ArrayIterator($this->filteredItems());
        }

        $itemNormalizer = $this->itemNormalizer;
        if ($itemNormalizer !== null) {
            foreach ($items as $item) {
                yield $itemNormalizer($item);
            }

            return;
        }

        yield from $items;
    }

    #[Override]
    public function data(): array
    {
        return iterator_to_array($this->getIterator());
    }

    #[Override]
    public function getResult(): array|ReadResponse
    {
        $this->assertNoSpecifications();

        if ($this->isValue()) {
            return $this->data();
        }

        $data = $this->data();

        return ReadResponse::create(
            $data,
            $this->isPaginated() ? ($this->paginator()?->getCurrentPage() ?? 1) : 1,
            $this->totalCount(),
        );
    }

    /** @return PaginatorInterface<T>|null */
    public function paginator(): PaginatorInterface|null
    {
        $this->assertNoSpecifications();

        if ($this->paginatorInstance !== null) {
            return $this->paginatorInstance;
        }

        if ($this->pagination === null) {
            return null;
        }

        [$page, $itemsPerPage] = $this->pagination;
        $payload               = $this->getPayload();
        $iterator              = count($this->specifications) > 0
            ? new ArrayIterator($this->filteredItems())
            : $payload->getIterator();

        $this->paginatorInstance = new InMemoryPaginator(
            $iterator,
            $payload->getTotalItems(),
            $payload->getCurrentPage() ?: $page,
            $itemsPerPage,
            0,
        );

        return $this->paginatorInstance;
    }

    private function assertNoSpecifications(): void
    {
        if (count($this->specifications) > 0) {
            throw new LogicException('Cannot use this method when specifications are set. Use getIterator() or data() instead.');
        }
    }

    #[Override]
    public function handleRequest(object $request, array $fieldsOperator = [], array $fieldsIgnoreCase = []): static
    {
        /** @phpstan-var static<T> $ds */
        $ds = static::applyRequestTo($this, $request, $fieldsOperator, $fieldsIgnoreCase);

        return $ds;
    }

    public function __clone()
    {
        $this->payload           = null;
        $this->paginatorInstance = null;
    }

    /**
     * @phpstan-param J $target
     * @phpstan-param array<string, string> $fieldsOperator
     * @phpstan-param array<string, bool> $fieldsIgnoreCase
     *
     * @phpstan-return J
     *
     * @phpstan-template J of ReadDataProviderCompositionInterface<object|array<string, mixed>>
     */
    public static function applyRequestTo(ReadDataProviderCompositionInterface $target, object $request, array $fieldsOperator = [], array $fieldsIgnoreCase = []): ReadDataProviderCompositionInterface
    {
        if (class_exists(SymfonyRequest::class) && $request instanceof SymfonyRequest) {
            if (! class_exists(Psr17Factory::class)) {
                throw new InvalidArgumentException('You need to install "nyholm/psr7" and "symfony/psr-http-message-bridge" in order to handle Symfony requests!');
            }

            $psr17Factory   = new Psr17Factory();
            $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
            $request        = $psrHttpFactory->createRequest($request);
        }

        if ($request instanceof RequestInterface) {
            parse_str($request->getUri()->getQuery(), $input);

            /**
             * @phpstan-var J $result
             * @phpstan-ignore argument.type
             */
            $result = $target->handleInput($input, $fieldsOperator, $fieldsIgnoreCase);

            return $result;
        }

        throw new RuntimeException(sprintf('Unsupported request type: %s', $request::class));
    }
}
