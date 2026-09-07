<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Settings;

use Brain\Monkey\Functions;
use BytePhase\Connector\Settings\Settings;
use BytePhase\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsTest extends TestCase
{
    #[DataProvider('dashboardHosts')]
    public function test_the_dashboard_link_drops_the_api_label(string $baseUrl, string $expected): void
    {
        $this->settings($baseUrl, '3');

        $this->assertSame($expected, (new Settings())->dashboardUrl());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dashboardHosts(): array
    {
        return [
            'store subdomain' => ['https://shop.api.bytephase.com', 'https://shop.bytephase.com'],
            'another environment' => ['https://shop.api.example.com', 'https://shop.example.com'],
            'white-label domain is untouched' => ['https://forms.acme.com', 'https://forms.acme.com'],
            'nothing configured' => ['', ''],
        ];
    }

    public function test_the_submit_url_is_built_from_the_address_and_the_store_id(): void
    {
        $this->settings('https://shop.api.bytephase.com', '3');

        $this->assertSame(
            'https://shop.api.bytephase.com/api/3/integrations/submit',
            (new Settings())->submitUrl(),
        );
    }

    public function test_there_is_no_submit_url_without_a_store_id(): void
    {
        $this->settings('https://shop.api.bytephase.com', '');

        $this->assertSame('', (new Settings())->submitUrl());
    }

    private function settings(string $baseUrl, string $tenant): void
    {
        Functions\when('get_option')->alias(static fn (string $key, $default = '') => match ($key) {
            Settings::OPTION_BASE_URL => $baseUrl,
            Settings::OPTION_TENANT => $tenant,
            default => $default,
        });
        Functions\when('untrailingslashit')->alias(static fn ($value) => rtrim((string) $value, '/'));
    }
}
