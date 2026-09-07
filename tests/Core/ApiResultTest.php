<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Core;

use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Tests\TestCase;

final class ApiResultTest extends TestCase
{
    public function test_created_and_duplicate_are_successes(): void
    {
        $this->assertTrue((new ApiResult(ApiResult::OUTCOME_CREATED, 201))->isSuccess());
        $this->assertTrue((new ApiResult(ApiResult::OUTCOME_DUPLICATE, 200))->isSuccess());
        $this->assertFalse((new ApiResult(ApiResult::OUTCOME_RETRYABLE, 500))->isSuccess());
    }

    /**
     * 202 means BytePhase took the payload and parked it until the form is mapped.
     * Nothing failed, so the visitor must not see an error and the health rate must not drop.
     */
    public function test_accepted_is_a_success(): void
    {
        $result = new ApiResult(ApiResult::OUTCOME_ACCEPTED, 202);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->provesConnection());
        $this->assertFalse($result->isRetryable());
        $this->assertFalse($result->isAuthError());
    }

    public function test_only_retryable_is_retryable(): void
    {
        $this->assertTrue((new ApiResult(ApiResult::OUTCOME_RETRYABLE, 503))->isRetryable());
        $this->assertFalse((new ApiResult(ApiResult::OUTCOME_VALIDATION, 422))->isRetryable());
    }

    public function test_auth_error_is_detected(): void
    {
        $this->assertTrue((new ApiResult(ApiResult::OUTCOME_AUTH, 401))->isAuthError());
        $this->assertFalse((new ApiResult(ApiResult::OUTCOME_CREATED, 201))->isAuthError());
    }

    public function test_connection_is_proven_by_success_or_validation_but_not_auth(): void
    {
        $this->assertTrue((new ApiResult(ApiResult::OUTCOME_CREATED, 201))->provesConnection());
        $this->assertTrue((new ApiResult(ApiResult::OUTCOME_VALIDATION, 422))->provesConnection());
        $this->assertFalse((new ApiResult(ApiResult::OUTCOME_AUTH, 401))->provesConnection());
        $this->assertFalse((new ApiResult(ApiResult::OUTCOME_RETRYABLE, 500))->provesConnection());
    }
}
