<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use Kraz\ReadModel\ReadResponse;

/** @phpstan-template-covariant T of object|array<string, mixed> */
interface JsonRpcReadClientInterface
{
    /**
     * @phpstan-param array<string, mixed>|null $params
     *
     * @phpstan-return ReadResponse<covariant T>
     */
    public function read(array|null $params = null): ReadResponse;
}
