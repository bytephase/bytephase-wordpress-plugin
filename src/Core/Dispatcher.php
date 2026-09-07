<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

defined('ABSPATH') || exit;

/**
 * Orchestrates one submission: build envelope → send once → log → store only if retryable.
 * Also drains the retry queue when the WP-Cron worker fires. The single entry point every
 * Connector calls.
 */
class Dispatcher
{
    private const MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly ApiClient $client,
        private readonly PendingSubmissions $pending,
        private readonly ActivityLog $log,
    ) {
    }

    public function dispatch(Submission $submission): ApiResult
    {
        $idempotencyKey = wp_generate_uuid4();
        $result = $this->client->submit(Envelope::fromSubmission($submission), $idempotencyKey);

        $this->log->record($submission, $result);

        if ($result->isRetryable()) {
            $this->pending->enqueue($submission, $idempotencyKey, $result->message);
        } elseif (! $result->isSuccess()) {
            // A rejected key, an unrecognised Store ID, a field BytePhase could not read: the
            // worker cannot fix any of those, but the shop can — and until it does, this is the
            // only copy of the enquiry. Hold it so Health can retry it instead of losing it.
            $this->pending->enqueueFailed($submission, $idempotencyKey, $result->message);
        }

        return $result;
    }

    /**
     * WP-Cron worker: retry every due row, backing off or failing as needed.
     */
    public function processRetries(): void
    {
        $this->pending->purgeExpired();

        foreach ($this->pending->due() as $row) {
            $id = (int) $row['id'];
            $submission = $this->pending->toSubmission($row);

            $result = $this->client->submit(
                Envelope::fromSubmission($submission),
                (string) $row['idempotency_key'],
            );

            $this->log->record($submission, $result);

            if ($result->isSuccess()) {
                $this->pending->delete($id);

                continue;
            }

            if (! $result->isRetryable()) {
                // 401/422/other — retrying the same payload/key won't help; surface it.
                $this->pending->markFailed($id, $result->message);

                continue;
            }

            $attempts = (int) $row['attempts'] + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->pending->markFailed($id, $result->message);
            } else {
                $this->pending->reschedule($id, $attempts, $result->message);
            }
        }
    }
}
