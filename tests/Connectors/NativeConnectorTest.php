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
use BytePhase\Connector\Tests\Support\RedirectStop;
use BytePhase\Connector\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class NativeConnectorTest extends TestCase
{
    private MockInterface $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = Mockery::mock(Dispatcher::class);

        // Custom fields are off unless a test says otherwise; see NativeConnectorCustomFieldsTest.
        Functions\when('get_option')->justReturn([]);

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

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function test_a_valid_lead_submission_is_dispatched(): void
    {
        $this->postValidLead();

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function (Submission $submission): bool {
                return $submission->provider === 'wordpress'
                    && $submission->formId === 'native-lead'
                    && $submission->destination === 'lead'
                    && $submission->data['name'] === 'Asha'
                    && $submission->data['mobile_number'] === '9999999999';
            }))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED));

        $this->assertSame('ok', $this->submit());
    }

    public function test_a_failed_dispatch_reports_an_error_to_the_visitor(): void
    {
        $this->postValidLead();

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturn(new ApiResult(ApiResult::OUTCOME_RETRYABLE));

        $this->assertSame('error', $this->submit());
    }

    public function test_a_filled_honeypot_gets_a_fake_success_and_no_dispatch(): void
    {
        $this->postValidLead();
        $_POST['bytephase_hp'] = 'gotcha';

        $this->dispatcher->shouldNotReceive('dispatch');

        $this->assertSame('ok', $this->submit());
    }

    public function test_a_missing_render_timestamp_is_treated_as_spam(): void
    {
        $this->postValidLead();
        unset($_POST['bytephase_ts']);

        $this->dispatcher->shouldNotReceive('dispatch');

        $this->assertSame('ok', $this->submit());
    }

    public function test_a_future_timestamp_is_treated_as_spam(): void
    {
        $this->postValidLead();
        $_POST['bytephase_ts'] = (string) (time() + 60);

        $this->dispatcher->shouldNotReceive('dispatch');

        $this->assertSame('ok', $this->submit());
    }

    public function test_a_too_fast_submission_is_treated_as_spam(): void
    {
        $this->postValidLead();
        $_POST['bytephase_ts'] = (string) (time() - 1);

        $this->dispatcher->shouldNotReceive('dispatch');

        $this->assertSame('ok', $this->submit());
    }

    public function test_an_unknown_destination_is_rejected(): void
    {
        $this->postValidLead();
        $_POST['bytephase_destination'] = 'repair';

        $this->dispatcher->shouldNotReceive('dispatch');

        $this->assertSame('error', $this->submit());
    }

    public function test_a_missing_name_is_invalid(): void
    {
        $this->postValidLead();
        unset($_POST['name']);

        $this->dispatcher->shouldNotReceive('dispatch');

        $this->assertSame('invalid', $this->submit());
    }

    public function test_missing_both_email_and_mobile_is_invalid(): void
    {
        $this->postValidLead();
        unset($_POST['mobile_number'], $_POST['email']);

        $this->dispatcher->shouldNotReceive('dispatch');

        $this->assertSame('invalid', $this->submit());
    }

    public function test_only_whitelisted_fields_are_collected(): void
    {
        $this->postValidLead();
        $_POST['is_admin'] = '1';
        $_POST['role'] = 'administrator';

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function (Submission $submission): bool {
                return ! array_key_exists('is_admin', $submission->data)
                    && ! array_key_exists('role', $submission->data);
            }))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED));

        $this->assertSame('ok', $this->submit());
    }

    public function test_field_values_are_sanitized(): void
    {
        $this->postValidLead();
        $_POST['name'] = '  <script>Asha</script>  ';

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn (Submission $s): bool => $s->data['name'] === 'Asha'))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED));

        $this->assertSame('ok', $this->submit());
    }

    /**
     * Run handleSubmit() and return the bytephase_status the visitor would see.
     */
    private function submit(): string
    {
        try {
            (new NativeConnector(
                $this->dispatcher,
                new CustomFields(),
                new CustomFieldCatalog(Mockery::mock(ApiClient::class)),
            ))->handleSubmit();
        } catch (RedirectStop $stop) {
            parse_str((string) parse_url($stop->url, PHP_URL_QUERY), $query);

            return (string) ($query['bytephase_status'] ?? '');
        }

        $this->fail('handleSubmit() did not redirect');
    }

    private function postValidLead(): void
    {
        $_POST = [
            'bytephase_destination' => 'lead',
            'bytephase_ts' => (string) (time() - 30),
            'name' => 'Asha',
            'mobile_number' => '9999999999',
        ];
    }
}
