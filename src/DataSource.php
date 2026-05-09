<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

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
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * @template T of array<string, mixed>|object
 *
 * @implements ReadDataProviderInterface<T>
 */
class DataSource implements ReadDataProviderInterface
{
    /** @use ReadDataProviderAccess<T> */
    use ReadDataProviderAccess;
    /** @use ReadDataProviderComposition<T> */
    use ReadDataProviderComposition;

    /**
     * @var ReadDataProviderPayload<T>|null
     */
    private ?ReadDataProviderPayload $payload = null;
    private ?PaginatorInterface $paginatorInstance = null;

    /**
     * @param JsonRpcClientInterface&JsonRpcReadClientInterface<T> $client
     */
    public function __construct(
        private readonly JsonRpcClientInterface&JsonRpcReadClientInterface $client,
        private readonly string $identifierField = 'id',
        private readonly string $pageParamName = 'page',
        private readonly string $pageSizeParamName = 'pageSize',
        private readonly string $limitParamName = 'limit',
        private readonly string $offsetParamName = 'offset',
    ) {
    }

    /**
     * @return ReadDataProviderPayload<T>
     */
    private function getPayload(): ReadDataProviderPayload
    {
        if (null !== $this->payload) {
            return $this->payload;
        }

        $result = $this->client->read($this->getParams());

        /** @psalm-suppress ArgumentTypeCoercion */
        /** @var ReadDataProviderPayload<T> $payload */
        $payload = new ReadDataProviderPayload($result);
        $this->payload = $payload;

        return $this->payload;
    }

    private function getWrappedQueryExpression(): ?QueryExpression
    {
        if (0 === \count($this->queryExpressions)) {
            return null;
        }

        if (1 === \count($this->queryExpressions)) {
            return clone $this->queryExpressions[0];
        }

        return array_reduce($this->queryExpressions, static fn (QueryExpression $qx, QueryExpression $item) => $qx->wrap($item), QueryExpression::create());
    }

    private function getParams(): array
    {
        $params = $this->getWrappedQueryExpression()?->toArray() ?? [];
        if (\count($params) > 0) {
            $fieldMapping = $this->getOrCreateQueryExpressionProvider()->getFieldMapping();
            if (\count($fieldMapping) > 0) {
                $params = QueryExpression::applyFieldMapping($params, $fieldMapping);
            }
        }
        if ($this->isPaginated()) {
            [$page, $itemsPerPage] = $this->pagination;
            $params[$this->pageParamName] = $page;
            $params[$this->pageSizeParamName] = $itemsPerPage;
        } elseif (null !== $this->limit) {
            [$limitValue, $offsetValue] = $this->limit;
            $params[$this->limitParamName] = $limitValue;
            if (null !== $offsetValue && $offsetValue > 0) {
                $params[$this->offsetParamName] = $offsetValue;
            }
        }

        return $params;
    }

    #[\Override]
    public function withQueryModifier(callable $modifier, bool $append = false): static
    {
        throw new \LogicException('Query modifiers are not supported in the JsonRpc DataSource.');
    }

    /**
     * @return array<int, T>
     */
    private function filteredItems(): array
    {
        $items = $this->getPayload()->getData();

        if (0 === \count($this->specifications)) {
            return $items;
        }

        return array_values(array_filter($items, function (mixed $item): bool {
            foreach ($this->specifications as $specification) {
                /** @var T $item */
                if (!$specification->isSatisfiedBy($item)) {
                    return false;
                }
            }

            return true;
        }));
    }

    #[\Override]
    public function count(): int
    {
        $this->assertNoSpecifications();

        if (null !== $paginator = $this->paginator()) {
            return $paginator->count();
        }

        return \count($this->filteredItems());
    }

    #[\Override]
    public function totalCount(): int
    {
        $this->assertNoSpecifications();

        return $this->getPayload()->getTotalItems();
    }

    #[\Override]
    public function isPaginated(): bool
    {
        if (\count($this->specifications) > 0) {
            return false;
        }

        if (null === $this->pagination) {
            return false;
        }

        [$page, $itemsPerPage] = $this->pagination;

        return $page > 0 && $itemsPerPage > 0;
    }

    #[\Override]
    public function isEmpty(): bool
    {
        $this->assertNoSpecifications();

        return 0 === $this->totalCount();
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        $specifications = $this->specifications;
        $hasSpecs = \count($specifications) > 0;

        if ($hasSpecs && null !== $this->limit) {
            [$limitValue, $offsetValue] = $this->limit;
            yield from $this->withoutSpecification()->withoutLimit()->specificationsIterator(
                $specifications,
                $limitValue,
                $offsetValue ?? 0,
            );

            return;
        }

        if ($hasSpecs) {
            $items = new \ArrayIterator($this->filteredItems());
        } elseif (null !== $paginator = $this->paginator()) {
            $items = $paginator->getIterator();
        } else {
            $items = new \ArrayIterator($this->filteredItems());
        }

        $itemNormalizer = $this->itemNormalizer;
        if (null !== $itemNormalizer) {
            foreach ($items as $item) {
                yield $itemNormalizer($item);
            }

            return;
        }

        yield from $items;
    }

    #[\Override]
    public function data(): array
    {
        return iterator_to_array($this->getIterator());
    }

    #[\Override]
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

    /**
     * @return PaginatorInterface<T>|null
     */
    public function paginator(): ?PaginatorInterface
    {
        $this->assertNoSpecifications();

        if (!$this->isPaginated() || $this->isValue()) {
            return null;
        }

        if (null === $this->paginatorInstance) {
            [$page, $itemsPerPage] = $this->pagination;
            $payload = $this->getPayload();
            $iterator = \count($this->specifications) > 0
                ? new \ArrayIterator($this->filteredItems())
                : $payload->getIterator();
            $this->paginatorInstance = new InMemoryPaginator(
                $iterator,
                $payload->getTotalItems(),
                $payload->getCurrentPage() ?: $page,
                $itemsPerPage,
                0
            );
        }

        return $this->paginatorInstance;
    }

    private function assertNoSpecifications(): void
    {
        if (\count($this->specifications) > 0) {
            throw new \LogicException('Cannot use this method when specifications are set. Use getIterator() or data() instead.');
        }
    }

    #[\Override]
    public function handleRequest(object $request, array $fieldsOperator = [], array $fieldsIgnoreCase = []): static
    {
        /** @phpstan-var static<T> $ds */
        $ds = static::applyRequestTo($this, $request, $fieldsOperator, $fieldsIgnoreCase);

        return $ds;
    }

    public function __clone()
    {
        $this->payload = null;
        $this->paginatorInstance = null;
    }

    /**
     * @phpstan-param ReadDataProviderCompositionInterface $target
     * @phpstan-param array<string, string> $fieldsOperator
     * @phpstan-param array<string, bool> $fieldsIgnoreCase
     *
     * @phpstan-return ReadDataProviderCompositionInterface
     *
     * @phpstan-template J of ReadDataProviderCompositionInterface<object|array<string, mixed>>
     */
    public static function applyRequestTo(ReadDataProviderCompositionInterface $target, object $request, array $fieldsOperator = [], array $fieldsIgnoreCase = []): ReadDataProviderCompositionInterface
    {
        if (class_exists(SymfonyRequest::class) && $request instanceof SymfonyRequest) {
            if (!class_exists(Psr17Factory::class)) {
                throw new \InvalidArgumentException('You need to install "nyholm/psr7" and "symfony/psr-http-message-bridge" in order to handle Symfony requests!');
            }

            $psr17Factory = new Psr17Factory();
            $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
            $request = $psrHttpFactory->createRequest($request);
        }

        if ($request instanceof RequestInterface) {
            parse_str($request->getUri()->getQuery(), $input);

            /**
             * @phpstan-var ReadDataProviderCompositionInterface $result
             *
             * @phpstan-ignore argument.type
             */
            $result = $target->handleInput($input, $fieldsOperator, $fieldsIgnoreCase);

            return $result;
        }

        throw new \RuntimeException(\sprintf('Unsupported request type: %s', $request::class));
    }
}
