<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

defined('ABSPATH') || exit;

/**
 * A capped, operational-only ledger of recent submissions. Never stores customer or
 * business data — status, destination, HTTP code, request id and error message only.
 * Backed by a single option; pruned to the last 50 rows / 30 days on every write.
 */
class ActivityLog
{
    private const OPTION = 'bytephase_connector_activity';
    private const MAX_ROWS = 50;
    private const MAX_AGE_DAYS = 30;

    public function record(Submission $submission, ApiResult $result): void
    {
        $rows = $this->all();

        array_unshift($rows, [
            'time' => time(),
            'provider' => $submission->provider,
            'form_id' => $submission->formId,
            'destination' => $submission->destination,
            'outcome' => $result->outcome,
            'status_code' => $result->statusCode,
            'request_id' => $result->requestId,
            'error' => $result->isSuccess() ? null : $result->message,
        ]);

        update_option(self::OPTION, $this->prune($rows), false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $rows = get_option(self::OPTION, []);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 10): array
    {
        return array_slice($this->all(), 0, $limit);
    }

    /**
     * Success rate + counts within a rolling window (used by the health card; never lifetime).
     *
     * @return array{total: int, success: int, failed: int}
     */
    public function stats(int $sinceDays): array
    {
        $cutoff = time() - ($sinceDays * DAY_IN_SECONDS);
        $total = 0;
        $success = 0;

        foreach ($this->all() as $row) {
            if ((int) ($row['time'] ?? 0) < $cutoff) {
                continue;
            }

            $total++;

            if (in_array($row['outcome'] ?? '', ApiResult::SUCCESS_OUTCOMES, true)) {
                $success++;
            }
        }

        return ['total' => $total, 'success' => $success, 'failed' => $total - $success];
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function prune(array $rows): array
    {
        $cutoff = time() - (self::MAX_AGE_DAYS * DAY_IN_SECONDS);

        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) ($row['time'] ?? 0) >= $cutoff,
        ));

        return array_slice($rows, 0, self::MAX_ROWS);
    }
}
