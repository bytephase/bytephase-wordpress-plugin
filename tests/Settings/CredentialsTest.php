<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Settings;

use Brain\Monkey\Functions;
use BytePhase\Connector\Settings\Credentials;
use BytePhase\Connector\Tests\TestCase;

final class CredentialsTest extends TestCase
{
    public function test_the_mask_reveals_only_the_last_four_characters(): void
    {
        Functions\when('get_option')->justReturn('bp_live_secret_abcd1234');

        $masked = (new Credentials())->masked();

        $this->assertSame('••••••••1234', $masked);
        $this->assertStringNotContainsString('secret', $masked);
    }

    public function test_the_mask_is_empty_without_a_key(): void
    {
        Functions\when('get_option')->justReturn('');

        $this->assertSame('', (new Credentials())->masked());
        $this->assertFalse((new Credentials())->hasKey());
    }

    public function test_saving_trims_and_stores_without_autoload(): void
    {
        Functions\expect('update_option')
            ->once()
            ->with('bytephase_connector_api_key', 'bp_key_123', false);

        (new Credentials())->save("  bp_key_123  \n");
    }

    public function test_an_empty_key_is_never_saved(): void
    {
        Functions\expect('update_option')->never();

        (new Credentials())->save('   ');
    }

    public function test_forget_deletes_the_stored_key(): void
    {
        Functions\expect('delete_option')
            ->once()
            ->with('bytephase_connector_api_key');

        (new Credentials())->forget();
    }
}
