<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

defined('ABSPATH') || exit;

/**
 * Durable store for FAILED/retryable submissions only — successful sends never touch it.
 * A custom table so the WP-Cron worker can query due rows and back off per row.
 */
class PendingSubmissions
{
    public const CRON_HOOK = 'bytephase_connector_retry';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    /** Backoff schedule in seconds, indexed by attempt count. */
    private const BACKOFF = [60, 300, 1800, 7200, 21600];

    /** How long an undelivered submission may be held before it is discarded. */
    public const RETENTION_DAYS = 30;

    private const TABLE = 'bytephase_pending_submissions';

    public function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    public static function createTable(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(50) NOT NULL DEFAULT '',
            form_id varchar(191) NOT NULL DEFAULT '',
            destination varchar(50) DEFAULT NULL,
            payload longtext NOT NULL,
            idempotency_key varchar(64) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NOT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status_next (status, next_attempt_at)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function dropTable(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a table name cannot be a prepared placeholder; it is a class constant on $wpdb->prefix, never user input.
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    /**
     * Queue a submission the worker should try again by itself.
     */
    public function enqueue(Submission $submission, string $idempotencyKey, ?string $error): void
    {
        $this->insert($submission, $idempotencyKey, $error, self::STATUS_PENDING);
    }

    /**
     * Hold a submission the worker cannot fix on its own — a rejected API key, an
     * unmapped field, a wrong Store ID. Retrying the same payload this minute would fail
     * the same way, but the shop can put every one of those right and then press Retry on
     * the Health screen, so the payload is kept rather than dropped.
     */
    public function enqueueFailed(Submission $submission, string $idempotencyKey, ?string $error): void
    {
        $this->insert($submission, $idempotencyKey, $error, self::STATUS_FAILED);
    }

    private function insert(Submission $submission, string $idempotencyKey, ?string $error, string $status): void
    {
        global $wpdb;

        $wpdb->insert($this->tableName(), [
            'provider' => $submission->provider,
            'form_id' => $submission->formId,
            'destination' => $submission->destination,
            'payload' => wp_json_encode(['data' => $submission->data, 'meta' => $submission->meta]),
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'attempts' => 0,
            'next_attempt_at' => $this->nextAttemptAt(0),
            'last_error' => $error,
            'created_at' => current_time('mysql', true),
        ]);
    }

    /**
     * Pending rows whose backoff has elapsed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due(int $limit = 20): array
    {
        global $wpdb;

        $table = $this->tableName();

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a class constant, never user input.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated; every value below is a prepared placeholder.
                "SELECT * FROM {$table} WHERE status = %s AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
                self::STATUS_PENDING,
                current_time('mysql', true),
                $limit,
            ),
            ARRAY_A,
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function failed(int $limit = 50): array
    {
        global $wpdb;

        $table = $this->tableName();

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a class constant, never user input.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated; every value below is a prepared placeholder.
                "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d",
                self::STATUS_FAILED,
                $limit,
            ),
            ARRAY_A,
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Drop rows nobody rescued in time. Undelivered submissions hold real customer details,
     * so they must not sit in the site's database indefinitely — this is the retention the
     * plugin's suggested privacy text promises.
     */
    public function purgeExpired(int $days = self::RETENTION_DAYS): int
    {
        global $wpdb;

        $table = $this->tableName();

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a class constant, never user input.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated; every value below is a prepared placeholder.
                "DELETE FROM {$table} WHERE created_at < %s",
                gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS)),
            )
        );

        return is_int($deleted) ? $deleted : 0;
    }

    public function reschedule(int $id, int $attempts, ?string $error): void
    {
        global $wpdb;

        $wpdb->update($this->tableName(), [
            'attempts' => $attempts,
            'next_attempt_at' => $this->nextAttemptAt($attempts),
            'last_error' => $error,
        ], ['id' => $id]);
    }

    public function markFailed(int $id, ?string $error): void
    {
        global $wpdb;

        $wpdb->update($this->tableName(), [
            'status' => self::STATUS_FAILED,
            'last_error' => $error,
        ], ['id' => $id]);
    }

    /**
     * Requeue a failed row for immediate retry (the health-screen "Retry" button).
     */
    public function retryNow(int $id): void
    {
        global $wpdb;

        $wpdb->update($this->tableName(), [
            'status' => self::STATUS_PENDING,
            'next_attempt_at' => current_time('mysql', true),
        ], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->tableName(), ['id' => $id]);
    }

    /**
     * Rebuild a Submission from a stored row.
     *
     * @param  array<string, mixed>  $row
     */
    public function toSubmission(array $row): Submission
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];

        return new Submission(
            (string) ($row['provider'] ?? ''),
            (string) ($row['form_id'] ?? ''),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            $row['destination'] !== null ? (string) $row['destination'] : null,
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        );
    }

    private function nextAttemptAt(int $attempts): string
    {
        $delay = self::BACKOFF[min($attempts, count(self::BACKOFF) - 1)];

        return gmdate('Y-m-d H:i:s', time() + $delay);
    }
}
