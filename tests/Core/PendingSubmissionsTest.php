<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Core;

use Brain\Monkey\Functions;
use BytePhase\Connector\Core\PendingSubmissions;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class PendingSubmissionsTest extends TestCase
{
    private MockInterface $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = Mockery::mock();
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\when('wp_json_encode')->alias(static fn ($value) => json_encode($value));
        Functions\when('current_time')->alias(static fn (string $type, $gmt = 0): string => gmdate('Y-m-d H:i:s'));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_enqueue_stores_a_pending_row_with_the_first_backoff_delay(): void
    {
        $submission = new Submission('cf7', 'form-9', ['a' => 'b'], 'lead', ['page' => '/contact']);

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_bytephase_pending_submissions', Mockery::on(function (array $row): bool {
                $payload = json_decode((string) $row['payload'], true);

                return $row['provider'] === 'cf7'
                    && $row['form_id'] === 'form-9'
                    && $row['destination'] === 'lead'
                    && $row['idempotency_key'] === 'idem-42'
                    && $row['status'] === PendingSubmissions::STATUS_PENDING
                    && $row['attempts'] === 0
                    && $row['last_error'] === 'HTTP 503'
                    && $payload === ['data' => ['a' => 'b'], 'meta' => ['page' => '/contact']]
                    && $this->delaySeconds($row['next_attempt_at']) === 60;
            }));

        (new PendingSubmissions())->enqueue($submission, 'idem-42', 'HTTP 503');
    }

    public function test_enqueue_failed_stores_a_row_the_worker_will_not_pick_up(): void
    {
        $submission = new Submission('cf7', 'form-9', ['a' => 'b'], null);

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_bytephase_pending_submissions', Mockery::on(static function (array $row): bool {
                return $row['status'] === PendingSubmissions::STATUS_FAILED
                    && $row['attempts'] === 0
                    && $row['idempotency_key'] === 'idem-42'
                    && $row['last_error'] === 'HTTP 422';
            }));

        (new PendingSubmissions())->enqueueFailed($submission, 'idem-42', 'HTTP 422');
    }

    public function test_purge_expired_deletes_rows_past_the_retention_window(): void
    {
        $cutoff = null;

        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(static function (string $sql, string $date) use (&$cutoff): string {
                $cutoff = $date;

                return $sql . '|' . $date;
            });

        $this->wpdb->shouldReceive('query')->once()->andReturn(3);

        $deleted = (new PendingSubmissions())->purgeExpired();

        $this->assertSame(3, $deleted);
        $this->assertNotNull($cutoff);
        $this->assertSame(
            PendingSubmissions::RETENTION_DAYS,
            (int) round((time() - strtotime($cutoff . ' UTC')) / DAY_IN_SECONDS),
        );
    }

    #[DataProvider('backoffSteps')]
    public function test_reschedule_backs_off_exponentially_and_caps(int $attempts, int $expectedDelay): void
    {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_bytephase_pending_submissions',
                Mockery::on(function (array $row) use ($attempts, $expectedDelay): bool {
                    return $row['attempts'] === $attempts
                        && $this->delaySeconds($row['next_attempt_at']) === $expectedDelay;
                }),
                ['id' => 7],
            );

        (new PendingSubmissions())->reschedule(7, $attempts, 'still down');
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function backoffSteps(): array
    {
        return [
            'attempt 1 -> 5m' => [1, 300],
            'attempt 2 -> 30m' => [2, 1800],
            'attempt 3 -> 2h' => [3, 7200],
            'attempt 4 -> 6h' => [4, 21600],
            'attempt 9 caps at 6h' => [9, 21600],
        ];
    }

    public function test_a_stored_row_round_trips_back_into_a_submission(): void
    {
        $row = [
            'provider' => 'elementor',
            'form_id' => 'contact-us',
            'destination' => null,
            'payload' => (string) json_encode(['data' => ['Phone' => '99'], 'meta' => ['ip' => '::1']]),
        ];

        $submission = (new PendingSubmissions())->toSubmission($row);

        $this->assertSame('elementor', $submission->provider);
        $this->assertSame('contact-us', $submission->formId);
        $this->assertNull($submission->destination);
        $this->assertSame(['Phone' => '99'], $submission->data);
        $this->assertSame(['ip' => '::1'], $submission->meta);
    }

    public function test_a_corrupt_payload_degrades_to_an_empty_submission(): void
    {
        $submission = (new PendingSubmissions())->toSubmission([
            'provider' => 'cf7',
            'form_id' => 'x',
            'destination' => 'lead',
            'payload' => '{not json',
        ]);

        $this->assertSame([], $submission->data);
        $this->assertSame([], $submission->meta);
    }

    /**
     * Seconds between now and a stored UTC datetime, tolerant of test runtime.
     */
    private function delaySeconds(string $datetime): int
    {
        $delta = strtotime($datetime . ' UTC') - time();

        // Collapse sub-second scheduling jitter onto the exact ladder step.
        foreach ([60, 300, 1800, 7200, 21600] as $step) {
            if (abs($delta - $step) <= 2) {
                return $step;
            }
        }

        return $delta;
    }
}
