<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Connectors;

use Brain\Monkey\Functions;
use BytePhase\Connector\Connectors\NativeConnector;
use BytePhase\Connector\Core\ApiClient;
use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Core\CustomFieldCatalog;
use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Settings\CustomFields;
use BytePhase\Connector\Settings\FormDestinations;
use BytePhase\Connector\Tests\Support\RedirectStop;
use BytePhase\Connector\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * The native form renders whatever the shop defined in BytePhase, so what matters here is
 * the payload that leaves the site: the structured shape BytePhase's own screens render,
 * and nothing a visitor invented.
 */
final class NativeConnectorCustomFieldsTest extends TestCase
{
    private MockInterface $dispatcher;

    private MockInterface $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = Mockery::mock(Dispatcher::class);
        $this->client = Mockery::mock(ApiClient::class);

        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_text_field')->alias(static fn ($value): string => trim(strip_tags((string) $value)));
        Functions\when('sanitize_textarea_field')->alias(static fn ($value): string => trim(strip_tags((string) $value)));
        Functions\when('sanitize_email')->alias(static fn ($value): string => (string) (filter_var((string) $value, FILTER_SANITIZE_EMAIL) ?: ''));
        Functions\when('wp_get_referer')->justReturn('https://shop.test/contact/');
        Functions\when('home_url')->alias(static fn (string $path = '/'): string => 'https://shop.test' . $path);
        Functions\when('add_query_arg')->alias(static fn (string $key, string $value, string $url): string => $url . '?' . $key . '=' . $value);
        Functions\when('wp_safe_redirect')->alias(static function (string $url): void {
            throw new RedirectStop($url);
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function test_it_sends_the_shops_fields_in_the_shape_bytephase_renders(): void
    {
        $this->enableCustomFields();
        $this->postValidLead();
        $_POST['bytephase_cf'] = ['Warranty status' => 'In warranty'];

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function (Submission $submission): bool {
                return $submission->data['custom_fields'] === [
                    [
                        'label' => 'Warranty status',
                        'field_type' => 'Dropdown',
                        'field_value' => 'In warranty',
                        'select_box_items' => ['In warranty', 'Out of warranty'],
                    ],
                ];
            }))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED));

        $this->assertSame('ok', $this->submit());
    }

    public function test_a_value_the_shop_never_offered_is_dropped(): void
    {
        $this->enableCustomFields();
        $this->postValidLead();
        $_POST['bytephase_cf'] = ['Warranty status' => 'Whatever I typed'];

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn (Submission $s): bool => ! isset($s->data['custom_fields'])))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED));

        $this->assertSame('ok', $this->submit());
    }

    public function test_a_missing_required_field_is_refused_before_the_round_trip(): void
    {
        $this->enableCustomFields(true);
        $this->postValidLead();

        $this->dispatcher->shouldNotReceive('dispatch');

        $this->assertSame('invalid', $this->submit());
    }

    public function test_nothing_is_collected_while_the_feature_is_switched_off(): void
    {
        $this->enableCustomFields(false, false);
        $this->postValidLead();
        $_POST['bytephase_cf'] = ['Warranty status' => 'In warranty'];

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn (Submission $s): bool => ! isset($s->data['custom_fields'])))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED));

        $this->assertSame('ok', $this->submit());
    }

    private function enableCustomFields(bool $required = false, bool $enabled = true): void
    {
        Functions\when('get_option')->alias(static fn (string $name, $default = false) => $name === CustomFields::OPTION
            ? [FormDestinations::LEAD => ['enabled' => $enabled, 'form_type' => 'Lead', 'fields' => []]]
            : $default);

        $this->client->shouldReceive('customFields')->with('Lead')->andReturn([
            'fields' => [
                [
                    'field_name' => 'Warranty status',
                    'field_type' => 'Dropdown',
                    'placeholder' => '',
                    'is_field_required' => $required,
                    'select_box_items' => ['In warranty', 'Out of warranty'],
                ],
            ],
        ])->byDefault();
    }

    private function submit(): string
    {
        try {
            (new NativeConnector(
                $this->dispatcher,
                new CustomFields(),
                new CustomFieldCatalog($this->client),
            ))->handleSubmit();
        } catch (RedirectStop $stop) {
            parse_str((string) parse_url($stop->url, PHP_URL_QUERY), $query);

            return (string) ($query['bytephase_status'] ?? '');
        }

        $this->fail('handleSubmit() did not redirect');
    }

    private function postValidLead(): void
    {
        $_POST = array_merge($_POST, [
            'bytephase_destination' => 'lead',
            'bytephase_ts' => (string) (time() - 30),
            'name' => 'Asha',
            'mobile_number' => '9999999999',
        ]);
    }
}
