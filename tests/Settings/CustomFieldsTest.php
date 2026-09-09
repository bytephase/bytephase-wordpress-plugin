<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Settings;

use Brain\Monkey\Functions;
use BytePhase\Connector\Settings\CustomFields;
use BytePhase\Connector\Settings\FormDestinations;
use BytePhase\Connector\Tests\TestCase;

final class CustomFieldsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $stored
     */
    private function withOption(array $stored): CustomFields
    {
        Functions\when('get_option')->alias(
            static fn (string $name, $default = false) => $name === CustomFields::OPTION ? $stored : $default
        );

        return new CustomFields();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function definition(array $overrides = []): array
    {
        return array_merge([
            'field_name' => 'Warranty status',
            'field_type' => 'Dropdown',
            'placeholder' => '',
            'is_field_required' => false,
            'select_box_items' => ['In warranty', 'Out of warranty'],
        ], $overrides);
    }

    public function test_a_site_that_has_never_configured_anything_shows_no_custom_fields(): void
    {
        $customFields = $this->withOption([]);

        $this->assertFalse($customFields->isEnabled(FormDestinations::LEAD));
        $this->assertFalse($customFields->isEnabled(FormDestinations::SELF_CHECKIN));
    }

    public function test_each_form_defaults_to_its_own_form_type(): void
    {
        $customFields = $this->withOption([]);

        $this->assertSame('Lead', $customFields->formType(FormDestinations::LEAD));
        $this->assertSame('Self check-in', $customFields->formType(FormDestinations::SELF_CHECKIN));
    }

    public function test_choosing_nothing_publishes_every_field(): void
    {
        $customFields = $this->withOption([
            FormDestinations::LEAD => ['enabled' => true, 'form_type' => 'Lead', 'fields' => []],
        ]);

        $definitions = [$this->definition(), $this->definition(['field_name' => 'Preferred callback'])];

        $this->assertSame($definitions, $customFields->published(FormDestinations::LEAD, $definitions));
    }

    public function test_unticking_everything_publishes_nothing_optional(): void
    {
        // Distinct from a site that has never saved the screen, which publishes all of them.
        $customFields = $this->withOption([
            FormDestinations::LEAD => [
                'enabled' => true,
                'configured' => true,
                'form_type' => 'Lead',
                'fields' => [],
            ],
        ]);

        $published = $customFields->published(FormDestinations::LEAD, [
            $this->definition(),
            $this->definition(['field_name' => 'Preferred callback', 'is_field_required' => true]),
        ]);

        $this->assertCount(1, $published);
        $this->assertSame('Preferred callback', $published[0]['field_name']);
    }

    public function test_only_the_chosen_fields_are_published(): void
    {
        $customFields = $this->withOption([
            FormDestinations::LEAD => [
                'enabled' => true,
                'form_type' => 'Lead',
                'fields' => ['Preferred callback'],
            ],
        ]);

        $published = $customFields->published(FormDestinations::LEAD, [
            $this->definition(),
            $this->definition(['field_name' => 'Preferred callback']),
        ]);

        $this->assertCount(1, $published);
        $this->assertSame('Preferred callback', $published[0]['field_name']);
    }

    public function test_a_required_field_is_published_even_when_it_was_not_chosen(): void
    {
        $customFields = $this->withOption([
            FormDestinations::LEAD => [
                'enabled' => true,
                'form_type' => 'Lead',
                'fields' => ['Preferred callback'],
            ],
        ]);

        $published = $customFields->published(FormDestinations::LEAD, [
            $this->definition(['is_field_required' => true]),
            $this->definition(['field_name' => 'Preferred callback']),
        ]);

        $this->assertCount(2, $published);
    }

    public function test_saving_normalizes_every_destination_and_drops_duplicates(): void
    {
        $saved = [];

        Functions\when('get_option')->justReturn([]);
        Functions\when('sanitize_text_field')->alias(static fn ($value): string => trim((string) $value));
        Functions\when('update_option')->alias(static function (string $name, $value) use (&$saved): bool {
            $saved = $value;

            return true;
        });

        (new CustomFields())->save([
            FormDestinations::LEAD => [
                'enabled' => '1',
                'form_type' => ' Lead ',
                'fields' => ['Warranty status', 'Warranty status', 'Preferred callback'],
            ],
        ]);

        $this->assertTrue($saved[FormDestinations::LEAD]['enabled']);
        $this->assertTrue($saved[FormDestinations::LEAD]['configured']);
        $this->assertSame('Lead', $saved[FormDestinations::LEAD]['form_type']);
        $this->assertSame(['Warranty status', 'Preferred callback'], $saved[FormDestinations::LEAD]['fields']);

        // A destination the form did not post is still written, switched off.
        $this->assertFalse($saved[FormDestinations::SELF_CHECKIN]['enabled']);
        $this->assertSame('Self check-in', $saved[FormDestinations::SELF_CHECKIN]['form_type']);
    }
}
