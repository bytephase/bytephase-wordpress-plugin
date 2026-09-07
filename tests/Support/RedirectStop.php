<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Support;

use RuntimeException;

/**
 * Thrown by the wp_safe_redirect() stub so handlers that redirect-and-exit can
 * be exercised without terminating the test process: the throw prevents the
 * `exit` that follows the redirect call from ever running.
 */
final class RedirectStop extends RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('redirect: ' . $url);
    }
}
