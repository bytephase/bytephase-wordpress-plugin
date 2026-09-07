<?php

declare(strict_types=1);

namespace BytePhase\Connector\Connectors;

defined('ABSPATH') || exit;

/**
 * A Connector extracts a Submission from one form source and hands it to the Dispatcher.
 * It never maps, validates, or transforms field names — that is BytePhase's job.
 */
interface Connector
{
    public function slug(): string;

    /**
     * Whether the underlying form builder is present on this site.
     */
    public function isAvailable(): bool;

    /**
     * Hook the builder's submit event.
     */
    public function register(): void;
}
