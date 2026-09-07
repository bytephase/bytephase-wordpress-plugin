<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Core;

use Brain\Monkey\Functions;
use BytePhase\Connector\Core\ApiClient;
use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Settings\Credentials;
use BytePhase\Connector\Settings\Settings;
use BytePhase\Connector\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApiClientTest extends TestCase
{
    /** @var array<int, array{string, array<int, mixed>}> */
    private array $optionCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->alias(static fn (string $key, $default = '') => match ($key) {
            Settings::OPTION_BASE_URL => 'https://app.bytephase.com',
            Settings::OPTION_TENANT => 'harness',
            'bytephase_connector_api_key' => 'bp_test_key',
            default => $default,
        });
        Functions\when('untrailingslashit')->alias(static fn ($value) => rtrim((string) $value, '/'));
        Functions\when('wp_json_encode')->alias(static fn ($value) => json_encode($value));
        Functions\when('__')->returnArg();

        // The client records the key's standing after every classified response.
        // Captured (not expect()ed) because Brain Monkey can't combine when()
        // and expect() for the same function within one test.
        $this->optionCalls = [];
        Functions\when('update_option')->alias(function (...$args): bool {
            $this->optionCalls[] = ['update', $args];

            return true;
        });
        Functions\when('delete_option')->alias(function (...$args): bool {
            $this->optionCalls[] = ['delete', $args];

            return true;
        });
    }

    public function test_a_401_marks_the_connection_as_auth_failed(): void
    {
        $this->fakeHttp(401, '{"message":"bad key"}', 'REQ-401');

        $this->client()->submit(['data' => ['a' => 'b']], 'idem-1');

        $this->assertCount(1, $this->optionCalls);
        [$kind, $args] = $this->optionCalls[0];
        $this->assertSame('update', $kind);
        $this->assertSame(Settings::OPTION_AUTH_FAILED, $args[0]);
        $this->assertIsInt($args[1]);
        $this->assertFalse($args[2]);
    }

    public function test_an_authenticated_response_clears_the_auth_failed_flag(): void
    {
        $this->fakeHttp(201, '{}', 'REQ-201');

        $this->client()->submit(['data' => ['a' => 'b']], 'idem-1');

        $this->assertSame([['delete', [Settings::OPTION_AUTH_FAILED]]], $this->optionCalls);
    }

    public function test_a_422_also_clears_the_flag_because_the_key_authenticated(): void
    {
        $this->fakeHttp(422, '{"message":"validation"}', 'REQ-422');

        $this->client()->testConnection();

        $this->assertSame([['delete', [Settings::OPTION_AUTH_FAILED]]], $this->optionCalls);
    }

    public function test_a_202_also_clears_the_flag_because_the_key_authenticated(): void
    {
        $this->fakeHttp(202, '{"status":"received"}', 'REQ-202');

        $this->client()->submit(['data' => ['a' => 'b']], 'idem-1');

        $this->assertSame([['delete', [Settings::OPTION_AUTH_FAILED]]], $this->optionCalls);
    }

    #[DataProvider('httpCodes')]
    public function test_it_classifies_http_codes(int $code, string $expected): void
    {
        $this->fakeHttp($code, '{"message":"ok"}', '01H-REQ');

        $result = $this->client()->submit(['data' => ['a' => 'b']], 'idem-1');

        $this->assertSame($expected, $result->outcome);
        $this->assertSame($code, $result->statusCode);
        $this->assertSame('01H-REQ', $result->requestId);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function httpCodes(): array
    {
        return [
            '201 created' => [201, ApiResult::OUTCOME_CREATED],
            '200 duplicate' => [200, ApiResult::OUTCOME_DUPLICATE],
            '202 accepted, form not mapped yet' => [202, ApiResult::OUTCOME_ACCEPTED],
            '401 auth' => [401, ApiResult::OUTCOME_AUTH],
            '412 unknown store id' => [412, ApiResult::OUTCOME_TENANT],
            '422 validation' => [422, ApiResult::OUTCOME_VALIDATION],
            '429 retryable' => [429, ApiResult::OUTCOME_RETRYABLE],
            '500 retryable' => [500, ApiResult::OUTCOME_RETRYABLE],
            '503 retryable' => [503, ApiResult::OUTCOME_RETRYABLE],
            '400 fatal' => [400, ApiResult::OUTCOME_FATAL],
            '404 fatal' => [404, ApiResult::OUTCOME_FATAL],
        ];
    }

    public function test_a_transport_error_is_retryable(): void
    {
        $error = Mockery::mock();
        $error->shouldReceive('get_error_message')->andReturn('cURL error 7');

        Functions\when('wp_remote_post')->justReturn($error);
        Functions\when('is_wp_error')->justReturn(true);

        $result = $this->client()->submit(['data' => ['a' => 'b']], 'idem-1');

        $this->assertSame(ApiResult::OUTCOME_RETRYABLE, $result->outcome);
        $this->assertSame('cURL error 7', $result->message);
        $this->assertNull($result->statusCode);
    }

    public function test_it_fails_fast_without_configuration(): void
    {
        Functions\when('get_option')->justReturn('');

        $result = $this->client()->submit(['data' => ['a' => 'b']], 'idem-1');

        $this->assertSame(ApiResult::OUTCOME_FATAL, $result->outcome);
    }

    public function test_it_sends_the_tenant_and_api_key_headers(): void
    {
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        Functions\expect('wp_remote_post')
            ->once()
            ->with(
                'https://app.bytephase.com/api/harness/integrations/submit',
                Mockery::on(static function (array $args): bool {
                    return $args['headers']['X-API-Key'] === 'bp_test_key'
                        && $args['headers']['X-Tenant'] === 'harness'
                        && $args['headers']['Idempotency-Key'] === 'idem-1'
                        && $args['sslverify'] === true;
                }),
            )
            ->andReturn(['ok' => true]);

        $result = $this->client()->submit(['data' => ['a' => 'b']], 'idem-1');

        $this->assertSame(ApiResult::OUTCOME_CREATED, $result->outcome);
    }

    private function fakeHttp(int $code, string $body, string $requestId): void
    {
        Functions\when('wp_remote_post')->justReturn(['response' => true]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_header')->justReturn($requestId);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
    }

    private function client(): ApiClient
    {
        return new ApiClient(new Settings(), new Credentials());
    }
}
