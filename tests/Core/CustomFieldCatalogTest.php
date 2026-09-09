<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Core;

use Brain\Monkey\Functions;
use BytePhase\Connector\Core\ApiClient;
use BytePhase\Connector\Core\CustomFieldCatalog;
use BytePhase\Connector\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class CustomFieldCatalogTest extends TestCase
{
    private MockInterface $client;

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(ApiClient::class);
        $this->transients = [];
        $this->options = [];

        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        // Stateful, because forget() finds the cached form types through this option.
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);

            return true;
        });
    }

    public function test_it_returns_the_fields_bytephase_defines(): void
    {
        $this->client->shouldReceive('customFields')->once()->with('Lead')->andReturn([
            'form_type' => 'Lead',
            'fields' => [
                [
                    'field_name' => 'Warranty status',
                    'field_type' => 'Dropdown',
                    'placeholder' => 'Pick one',
                    'is_field_required' => true,
                    'select_box_items' => ['In warranty', 'Out of warranty'],
                ],
            ],
        ]);

        $fields = (new CustomFieldCatalog($this->client))->fields('Lead');

        $this->assertCount(1, $fields);
        $this->assertSame('Warranty status', $fields[0]['field_name']);
        $this->assertTrue($fields[0]['is_field_required']);
        $this->assertSame(['In warranty', 'Out of warranty'], $fields[0]['select_box_items']);
    }

    public function test_a_second_read_is_served_from_the_cache(): void
    {
        // once() is the assertion: a page with two forms must not cost two round trips.
        $this->client->shouldReceive('customFields')->once()->with('Lead')->andReturn([
            'fields' => [['field_name' => 'Warranty status', 'field_type' => 'Text']],
        ]);

        $catalog = new CustomFieldCatalog($this->client);

        $this->assertSame($catalog->fields('Lead'), $catalog->fields('Lead'));
    }

    public function test_a_malformed_definition_is_discarded_rather_than_rendered(): void
    {
        $this->client->shouldReceive('customFields')->andReturn([
            'fields' => [
                ['field_type' => 'Text'],
                ['field_name' => '', 'field_type' => 'Text'],
                ['field_name' => 'Ref', 'field_type' => 'Nonsense', 'select_box_items' => 'not-an-array'],
            ],
        ]);

        $fields = (new CustomFieldCatalog($this->client))->fields('Lead');

        $this->assertCount(1, $fields);
        $this->assertSame('Ref', $fields[0]['field_name']);
        $this->assertSame('Text', $fields[0]['field_type']);
        $this->assertSame([], $fields[0]['select_box_items']);
    }

    public function test_an_unreachable_bytephase_yields_no_fields_and_caches_nothing(): void
    {
        $this->client->shouldReceive('customFields')->twice()->andReturn(null);

        $catalog = new CustomFieldCatalog($this->client);

        $this->assertSame([], $catalog->fields('Lead'));
        $this->assertNull($catalog->syncedAt('Lead'));
        // Nothing was cached, so the next render retries instead of serving an empty form.
        $this->assertSame([], $catalog->fields('Lead'));
    }

    public function test_it_lists_only_the_form_types_that_have_fields(): void
    {
        $this->client->shouldReceive('customFields')->once()->with(null)->andReturn([
            'form_types' => [
                ['form_type' => 'Lead', 'field_count' => 2],
                ['form_type' => 'Self check-in', 'field_count' => 1],
                ['nonsense' => true],
            ],
        ]);

        $this->assertSame(['Lead', 'Self check-in'], (new CustomFieldCatalog($this->client))->formTypes());
    }

    public function test_refreshing_clears_the_cache_so_the_next_read_asks_again(): void
    {
        $this->client->shouldReceive('customFields')->twice()->with('Lead')->andReturn([
            'fields' => [['field_name' => 'Warranty status', 'field_type' => 'Text']],
        ]);

        $catalog = new CustomFieldCatalog($this->client);
        $catalog->fields('Lead');
        $catalog->forget();
        $catalog->fields('Lead');
    }
}
