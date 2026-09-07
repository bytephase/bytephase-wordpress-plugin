<?php

declare(strict_types=1);

namespace BytePhase\Connector\Settings;

defined('ABSPATH') || exit;

/**
 * Read/typed access to the connection settings (web address + store ID). Field mapping
 * is never stored here — it lives in BytePhase, keyed by form_id.
 */
final class Settings
{
    public const OPTION_BASE_URL = 'bytephase_connector_base_url';
    public const OPTION_TENANT = 'bytephase_connector_tenant';
    public const OPTION_AUTH_FAILED = 'bytephase_connector_auth_failed';

    public function baseUrl(): string
    {
        return (string) get_option(self::OPTION_BASE_URL, '');
    }

    public function tenantSlug(): string
    {
        return (string) get_option(self::OPTION_TENANT, '');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->tenantSlug() !== '';
    }

    public function submitUrl(): string
    {
        if (! $this->isConfigured()) {
            return '';
        }

        return sprintf(
            '%s/api/%s/integrations/submit',
            untrailingslashit($this->baseUrl()),
            rawurlencode($this->tenantSlug()),
        );
    }

    /**
     * The connection stores the API host; the dashboard lives on the store's own host —
     * "shop.api.bytephase.com" signs in at "shop.bytephase.com". A white-label domain has
     * no ".api." label and is returned unchanged.
     */
    public function dashboardUrl(): string
    {
        return (string) preg_replace('#^(https://[^/]+?)\.api\.#', '$1.', $this->baseUrl(), 1);
    }

    /**
     * The last request was rejected as unauthenticated (401) — the key is
     * invalid, expired, or revoked. Cleared by any authenticated response.
     */
    public function authFailed(): bool
    {
        return get_option(self::OPTION_AUTH_FAILED, false) !== false;
    }

    public function markAuthFailed(): void
    {
        update_option(self::OPTION_AUTH_FAILED, time(), false);
    }

    public function clearAuthFailed(): void
    {
        delete_option(self::OPTION_AUTH_FAILED);
    }
}
