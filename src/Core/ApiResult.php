<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

defined('ABSPATH') || exit;

/**
 * Typed outcome of a call to BytePhase, classified from the HTTP response.
 */
final class ApiResult
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_DUPLICATE = 'duplicate';
    /** 202: BytePhase accepted the payload but has not turned it into a record — the form is not mapped yet. */
    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_AUTH = 'auth_error';
    public const OUTCOME_TENANT = 'tenant_error';
    public const OUTCOME_VALIDATION = 'validation_error';
    public const OUTCOME_RETRYABLE = 'retryable';
    public const OUTCOME_FATAL = 'fatal';

    /** Outcomes where BytePhase took the submission — there is nothing for the shop to fix or retry. */
    public const SUCCESS_OUTCOMES = [self::OUTCOME_CREATED, self::OUTCOME_DUPLICATE, self::OUTCOME_ACCEPTED];

    public function __construct(
        public readonly string $outcome,
        public readonly ?int $statusCode = null,
        public readonly ?string $requestId = null,
        public readonly ?string $message = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return in_array($this->outcome, self::SUCCESS_OUTCOMES, true);
    }

    public function isRetryable(): bool
    {
        return $this->outcome === self::OUTCOME_RETRYABLE;
    }

    public function isAuthError(): bool
    {
        return $this->outcome === self::OUTCOME_AUTH;
    }

    /**
     * The Store ID (tenant) was not recognized — BytePhase answers 412 before
     * the API key is even looked at.
     */
    public function isTenantError(): bool
    {
        return $this->outcome === self::OUTCOME_TENANT;
    }

    /**
     * A reach that proves the key is accepted — used by the connection test. 422 counts:
     * it means the key authenticated and only the (empty) payload was rejected.
     */
    public function provesConnection(): bool
    {
        return $this->isSuccess() || $this->outcome === self::OUTCOME_VALIDATION;
    }
}
