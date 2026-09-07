<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Settings;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use BytePhase\Connector\Settings\FormDestinations;
use BytePhase\Connector\Tests\TestCase;

final class FormDestinationsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $stored
     */
    private function withOption(array $stored): FormDestinations
    {
        Functions\when('get_option')->alias(
            static fn (string $name, $default = false) => $name === FormDestinations::OPTION ? $stored : $default
        );

        return new FormDestinations();
    }

    public function test_it_resolves_the_choice_saved_for_a_form(): void
    {
        $destinations = $this->withOption(['cf7:123' => 'lead', 'cf7:456' => 'self_checkin']);

        $this->assertSame('lead', $destinations->resolve('cf7', '123'));
        $this->assertSame('self_checkin', $destinations->resolve('cf7', '456'));
    }

    public function test_an_unchosen_form_resolves_to_null(): void
    {
        $this->assertNull($this->withOption([])->resolve('cf7', '123'));
    }

    public function test_a_form_from_another_provider_is_not_confused_with_the_same_id(): void
    {
        $destinations = $this->withOption(['cf7:123' => 'lead']);

        $this->assertNull($destinations->resolve('elementor', '123'));
    }

    public function test_ignore_is_reported_but_never_used_as_a_destination(): void
    {
        $destinations = $this->withOption(['cf7:789' => 'ignore']);

        $this->assertTrue($destinations->isIgnored('cf7', '789'));
        $this->assertNull($destinations->resolve('cf7', '789'));
    }

    public function test_a_junk_value_in_the_option_is_ignored(): void
    {
        $destinations = $this->withOption(['cf7:123' => 'invoice', 'cf7:456' => ['nested'], 7 => 'lead']);

        $this->assertSame([], $destinations->all());
        $this->assertNull($destinations->resolve('cf7', '123'));
    }

    public function test_a_corrupt_option_does_not_break_resolution(): void
    {
        Functions\when('get_option')->justReturn('not-an-array');

        $this->assertSame([], (new FormDestinations())->all());
    }

    public function test_the_filter_can_override_the_saved_choice(): void
    {
        Filters\expectApplied('bytephase_connector_destination')
            ->once()
            ->andReturn('self_checkin');

        $this->assertSame('self_checkin', $this->withOption(['cf7:123' => 'lead'])->resolve('cf7', '123'));
    }

    public function test_a_filter_returning_something_unsupported_falls_back_to_null(): void
    {
        Filters\expectApplied('bytephase_connector_destination')
            ->once()
            ->andReturn('invoice');

        $this->assertNull($this->withOption(['cf7:123' => 'lead'])->resolve('cf7', '123'));
    }

    public function test_save_keeps_only_valid_choices(): void
    {
        $saved = null;

        Functions\when('update_option')->alias(static function (string $name, $value) use (&$saved): bool {
            $saved = $value;

            return true;
        });

        (new FormDestinations())->save([
            'cf7:1' => 'lead',
            'cf7:2' => 'self_checkin',
            'cf7:3' => 'ignore',
            'cf7:4' => 'invoice',
            '' => 'lead',
        ]);

        $this->assertSame(['cf7:1' => 'lead', 'cf7:2' => 'self_checkin', 'cf7:3' => 'ignore'], $saved);
    }
}
