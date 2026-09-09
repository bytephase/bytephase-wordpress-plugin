<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

use BytePhase\Connector\Settings\Credentials;
use BytePhase\Connector\Settings\Settings;

defined('ABSPATH') || exit;

/**
 * The only place the plugin talks to BytePhase. Wraps the WordPress HTTP API,
 * injects auth headers, verifies SSL, and classifies the response.
 */
class ApiClient
{
    private const TIMEOUT = 15;

    public function __construct(
        private readonly Settings $settings,
        private readonly Credentials $credentials,
    ) {
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function submit(array $body, string $idempotencyKey): ApiResult
    {
        return $this->request($body, $idempotencyKey);
    }

    /**
     * Verify the credentials without creating anything. An empty body is rejected by
     * BytePhase's Form Request (422) BEFORE any submission is recorded, so this never
     * pollutes the submission log or the integration's failure counters. A bad key
     * still returns 401 from the auth middleware.
     */
    public function testConnection(): ApiResult
    {
        return $this->request([], null);
    }

    /**
     * The shop's custom field definitions for one form type, or the list of form types
     * that have any when $formType is null. Returns null when the call fails for any
     * reason — the caller decides whether to fall back to a cached copy or to no fields
     * at all, because a website form must never break because BytePhase is unreachable.
     *
     * @return array<string, mixed>|null
     */
    public function customFields(?string $formType): ?array
    {
        $url = $this->settings->customFieldsUrl();

        if ($url === '' || $this->credentials->apiKey() === '') {
            return null;
        }

        if ($formType !== null && $formType !== '') {
            $url = add_query_arg('form_type', rawurlencode($formType), $url);
        }

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $this->headers(),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        // The same standing the submit path tracks, so an expired key flips the Health
        // screen to "Reconnect" even if the site has had no submissions since.
        if ($code === 401) {
            $this->settings->markAuthFailed();

            return null;
        }

        if ($code !== 200) {
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-API-Key' => $this->credentials->apiKey(),
            'X-Tenant' => $this->settings->tenantSlug(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function request(array $body, ?string $idempotencyKey): ApiResult
    {
        $url = $this->settings->submitUrl();

        if ($url === '' || $this->credentials->apiKey() === '') {
            return new ApiResult(
                ApiResult::OUTCOME_FATAL,
                message: __('BytePhase is not connected yet — add your API address, store ID and API key.', 'bytephase-connector'),
            );
        }

        $headers = $this->headers();

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = wp_remote_post($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $headers,
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new ApiResult(ApiResult::OUTCOME_RETRYABLE, message: $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $requestId = wp_remote_retrieve_header($response, 'x-request-id');

        $outcome = match (true) {
            $code === 201 => ApiResult::OUTCOME_CREATED,
            $code === 200 => ApiResult::OUTCOME_DUPLICATE,
            $code === 202 => ApiResult::OUTCOME_ACCEPTED,
            $code === 401 => ApiResult::OUTCOME_AUTH,
            $code === 412 => ApiResult::OUTCOME_TENANT,
            $code === 422 => ApiResult::OUTCOME_VALIDATION,
            $code === 429 || $code >= 500 => ApiResult::OUTCOME_RETRYABLE,
            default => ApiResult::OUTCOME_FATAL,
        };

        // Track the key's standing so the Health screen can flip to "Reconnect".
        // 422 clears too: validation runs only after the key authenticated.
        if ($outcome === ApiResult::OUTCOME_AUTH) {
            $this->settings->markAuthFailed();
        } elseif (in_array($outcome, [...ApiResult::SUCCESS_OUTCOMES, ApiResult::OUTCOME_VALIDATION], true)) {
            $this->settings->clearAuthFailed();
        }

        return new ApiResult(
            $outcome,
            $code,
            $requestId !== '' ? $requestId : null,
            $this->extractMessage($response),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractMessage(array $response): ?string
    {
        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return null;
    }
}
