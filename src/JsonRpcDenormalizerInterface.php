<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

interface JsonRpcDenormalizerInterface
{
    /**
     * @template T of object
     *
     * @psalm-param class-string<T>|string $type
     */
    public function denormalize(mixed $data, string $type): mixed;
}
