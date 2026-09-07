<?php

declare(strict_types=1);

namespace BytePhase\Connector\Settings;

defined('ABSPATH') || exit;

/**
 * API-key storage. Plaintext in an autoload=no option (encryption with WP salts buys
 * little — see docs/ARCHITECTURE.md §5). Never redisplayed after save, never logged.
 */
final class Credentials
{
    private const OPTION = 'bytephase_connector_api_key';

    public function apiKey(): string
    {
        return (string) get_option(self::OPTION, '');
    }

    public function hasKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function save(string $key): void
    {
        $key = trim($key);

        if ($key === '') {
            return;
        }

        update_option(self::OPTION, $key, false);
    }

    public function forget(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * A masked hint for the UI — never the real key.
     */
    public function masked(): string
    {
        $key = $this->apiKey();

        return $key === '' ? '' : str_repeat('•', 8) . substr($key, -4);
    }
}
