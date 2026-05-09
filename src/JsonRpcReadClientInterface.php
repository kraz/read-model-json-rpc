<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use Kraz\ReadModel\ReadResponse;

/**
 * @template-covariant T
 */
interface JsonRpcReadClientInterface
{
    /**
     * @param array<string, mixed>|null $params
     *
     * @return ReadResponse<covariant T>
     */
    public function read(?array $params = null): ReadResponse;
}
