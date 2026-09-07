<?php

declare(strict_types=1);

namespace BytePhase\Connector\Connectors;

defined('ABSPATH') || exit;

/**
 * Collects Connectors (built-ins plus any added via the bytephase_register_connectors
 * filter) and boots the ones whose builder is active.
 */
final class ConnectorRegistry
{
    /** @var Connector[] */
    private array $connectors;

    public function __construct(Connector ...$connectors)
    {
        $this->connectors = $connectors;
    }

    public function boot(): void
    {
        /** @var Connector[] $connectors */
        $connectors = apply_filters('bytephase_register_connectors', $this->connectors);

        foreach ($connectors as $connector) {
            if ($connector instanceof Connector && $connector->isAvailable()) {
                $connector->register();
            }
        }
    }
}
