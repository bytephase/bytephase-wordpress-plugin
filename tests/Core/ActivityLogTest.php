<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Core;

use Brain\Monkey\Functions;
use BytePhase\Connector\Core\ActivityLog;
use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Tests\TestCase;

final class ActivityLogTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stored = [];

        Functions\when('get_option')->alias(fn (string $key, $default = []) => $this->stored ?: $default);
        Functions\when('update_option')->alias(function (string $key, array $rows, bool $autoload): bool {
            $this->stored = $rows;

            return true;
        });
    }

    public function test_the_log_is_capped_at_fifty_rows_newest_first(): void
    {
        $this->stored = array_map(
            static fn (int $i): array => ['time' => time() - $i, 'form_id' => 'old-' . $i],
            range(1, 55),
        );

        $this->record(new ApiResult(ApiResult::OUTCOME_CREATED, 201, 'REQ-NEW'));

        $this->assertCount(50, $this->stored);
        $this->assertSame('REQ-NEW', $this->stored[0]['request_id']);
    }

    public function test_rows_older_than_thirty_days_are_pruned_on_write(): void
    {
        $this->stored = [
            ['time' => time() - (31 * DAY_IN_SECONDS), 'form_id' => 'ancient'],
            ['time' => time() - (5 * DAY_IN_SECONDS), 'form_id' => 'recent'],
        ];

        $this->record(new ApiResult(ApiResult::OUTCOME_CREATED, 201));

        $formIds = array_column($this->stored, 'form_id');
        $this->assertNotContains('ancient', $formIds);
        $this->assertContains('recent', $formIds);
    }

    public function test_successful_rows_never_store_an_error_message(): void
    {
        $this->record(new ApiResult(ApiResult::OUTCOME_DUPLICATE, 200, 'REQ-1', 'duplicate detail from server'));

        $this->assertNull($this->stored[0]['error']);
    }

    public function test_failed_rows_keep_the_error_message(): void
    {
        $this->record(new ApiResult(ApiResult::OUTCOME_VALIDATION, 422, 'REQ-2', 'The name field is required.'));

        $this->assertSame('The name field is required.', $this->stored[0]['error']);
    }

    public function test_stats_are_windowed_and_count_duplicates_as_success(): void
    {
        $this->stored = [
            ['time' => time() - HOUR_IN_SECONDS, 'outcome' => ApiResult::OUTCOME_CREATED],
            ['time' => time() - HOUR_IN_SECONDS, 'outcome' => ApiResult::OUTCOME_DUPLICATE],
            ['time' => time() - HOUR_IN_SECONDS, 'outcome' => ApiResult::OUTCOME_RETRYABLE],
            ['time' => time() - (10 * DAY_IN_SECONDS), 'outcome' => ApiResult::OUTCOME_CREATED],
        ];

        $stats = (new ActivityLog())->stats(7);

        $this->assertSame(['total' => 3, 'success' => 2, 'failed' => 1], $stats);
    }

    private function record(ApiResult $result): void
    {
        (new ActivityLog())->record(
            new Submission('cf7', 'form-1', ['name' => 'Asha'], 'lead'),
            $result,
        );
    }
}
