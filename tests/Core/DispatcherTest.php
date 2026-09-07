<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Core;

use Brain\Monkey\Functions;
use BytePhase\Connector\Core\ActivityLog;
use BytePhase\Connector\Core\ApiClient;
use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\PendingSubmissions;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class DispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_generate_uuid4')->justReturn('idem-1');
    }

    public function test_a_successful_send_is_logged_and_not_queued(): void
    {
        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('submit')->once()->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED, 201));

        $log = Mockery::mock(ActivityLog::class);
        $log->shouldReceive('record')->once();

        $pending = Mockery::mock(PendingSubmissions::class);
        $pending->shouldNotReceive('enqueue');
        $pending->shouldNotReceive('enqueueFailed');

        (new Dispatcher($client, $pending, $log))->dispatch(new Submission('wordpress', 'f', ['a' => 'b']));
    }

    public function test_an_accepted_send_is_not_queued(): void
    {
        // 202 means BytePhase is holding it for field mapping — the shop has nothing to retry.
        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('submit')->once()->andReturn(new ApiResult(ApiResult::OUTCOME_ACCEPTED, 202));

        $log = Mockery::mock(ActivityLog::class);
        $log->shouldReceive('record')->once();

        $pending = Mockery::mock(PendingSubmissions::class);
        $pending->shouldNotReceive('enqueue');
        $pending->shouldNotReceive('enqueueFailed');

        (new Dispatcher($client, $pending, $log))->dispatch(new Submission('wordpress', 'f', ['a' => 'b']));
    }

    public function test_a_retryable_send_is_queued(): void
    {
        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('submit')->once()->andReturn(new ApiResult(ApiResult::OUTCOME_RETRYABLE, 500, null, 'boom'));

        $log = Mockery::mock(ActivityLog::class);
        $log->shouldReceive('record')->once();

        $pending = Mockery::mock(PendingSubmissions::class);
        $pending->shouldReceive('enqueue')->once()->with(Mockery::type(Submission::class), 'idem-1', 'boom');

        (new Dispatcher($client, $pending, $log))->dispatch(new Submission('wordpress', 'f', ['a' => 'b']));
    }

    #[DataProvider('unrecoverableOutcomes')]
    public function test_an_unrecoverable_send_is_held_for_the_shop_to_retry(string $outcome, int $statusCode): void
    {
        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('submit')->once()->andReturn(new ApiResult($outcome, $statusCode, null, 'nope'));

        $log = Mockery::mock(ActivityLog::class);
        $log->shouldReceive('record')->once();

        $pending = Mockery::mock(PendingSubmissions::class);
        $pending->shouldNotReceive('enqueue');
        $pending->shouldReceive('enqueueFailed')->once()->with(Mockery::type(Submission::class), 'idem-1', 'nope');

        (new Dispatcher($client, $pending, $log))->dispatch(new Submission('wordpress', 'f', ['a' => 'b']));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function unrecoverableOutcomes(): array
    {
        return [
            'rejected api key' => [ApiResult::OUTCOME_AUTH, 401],
            'unknown store id' => [ApiResult::OUTCOME_TENANT, 412],
            'payload rejected' => [ApiResult::OUTCOME_VALIDATION, 422],
            'fatal' => [ApiResult::OUTCOME_FATAL, 400],
        ];
    }

    public function test_the_worker_sweeps_expired_rows_before_retrying(): void
    {
        $pending = Mockery::mock(PendingSubmissions::class);
        $pending->shouldReceive('purgeExpired')->once();
        $pending->shouldReceive('due')->once()->andReturn([]);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldNotReceive('submit');

        $log = Mockery::mock(ActivityLog::class);
        $log->shouldNotReceive('record');

        (new Dispatcher($client, $pending, $log))->processRetries();
    }

    public function test_retry_deletes_a_row_that_now_succeeds(): void
    {
        $this->runRetry(attempts: 1, outcome: ApiResult::OUTCOME_CREATED, expect: 'delete');
    }

    public function test_retry_marks_a_non_retryable_row_as_failed(): void
    {
        $this->runRetry(attempts: 1, outcome: ApiResult::OUTCOME_AUTH, expect: 'markFailed');
    }

    public function test_retry_reschedules_a_retryable_row_below_max_attempts(): void
    {
        $this->runRetry(attempts: 2, outcome: ApiResult::OUTCOME_RETRYABLE, expect: 'reschedule', expectedAttempts: 3);
    }

    public function test_retry_fails_a_row_at_the_attempt_ceiling(): void
    {
        // attempts 5 → 6 hits MAX_ATTEMPTS, so it fails instead of rescheduling.
        $this->runRetry(attempts: 5, outcome: ApiResult::OUTCOME_RETRYABLE, expect: 'markFailed');
    }

    private function runRetry(int $attempts, string $outcome, string $expect, ?int $expectedAttempts = null): void
    {
        $row = [
            'id' => 7,
            'attempts' => $attempts,
            'idempotency_key' => 'idem-1',
            'provider' => 'wordpress',
            'form_id' => 'f',
            'destination' => null,
            'payload' => '{"data":{},"meta":[]}',
        ];

        $pending = Mockery::mock(PendingSubmissions::class);
        $pending->shouldReceive('purgeExpired')->once();
        $pending->shouldReceive('due')->once()->andReturn([$row]);
        $pending->shouldReceive('toSubmission')->once()->andReturn(new Submission('wordpress', 'f', []));

        foreach (['delete', 'markFailed', 'reschedule'] as $method) {
            if ($method === $expect) {
                continue;
            }
            $pending->shouldNotReceive($method);
        }

        if ($expect === 'delete') {
            $pending->shouldReceive('delete')->once()->with(7);
        } elseif ($expect === 'markFailed') {
            $pending->shouldReceive('markFailed')->once()->with(7, Mockery::any());
        } else {
            $pending->shouldReceive('reschedule')->once()->with(7, $expectedAttempts, Mockery::any());
        }

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('submit')->once()->andReturn(new ApiResult($outcome, 500));

        $log = Mockery::mock(ActivityLog::class);
        $log->shouldReceive('record')->once();

        (new Dispatcher($client, $pending, $log))->processRetries();
    }
}
