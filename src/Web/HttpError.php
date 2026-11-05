<?php

declare(strict_types=1);

namespace Csv2Excel\Web;

use RuntimeException;

/** An error whose message is safe to show to the user. */
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
