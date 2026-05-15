<?php

declare(strict_types=1);

namespace Kraz\ReadModelJsonRpc;

use Kraz\ReadModel\CursorReadResponse;
use Kraz\ReadModel\ReadResponse;

/** @phpstan-template-covariant T of object|array<string, mixed> */
interface JsonRpcReadClientInterface
{
    /**
     * Cursor-paginated responses are signalled by the presence of cursor params
     * (typically `cursorLimit`) on the wire; implementations are expected to
     * return a {@see CursorReadResponse} in that case and a {@see ReadResponse}
     * otherwise.
     *
     * @phpstan-param array<string, mixed>|null $params
     *
     * @phpstan-return ReadResponse<covariant T>|CursorReadResponse<covariant T>
     */
    public function read(array|null $params = null): ReadResponse|CursorReadResponse;
}
