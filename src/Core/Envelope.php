<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

defined('ABSPATH') || exit;

/**
 * Serialises a Submission into the exact JSON body BytePhase's /integrations/submit expects.
 * The single place the wire format lives. Wire keys stay snake_case to match the API contract.
 */
final class Envelope
{
    /**
     * @return array<string, mixed>
     */
    public static function fromSubmission(Submission $submission): array
    {
        $body = [
            'provider' => $submission->provider,
            'data' => $submission->data,
        ];

        if ($submission->formId !== '') {
            $body['form_id'] = $submission->formId;
        }

        if ($submission->destination !== null && $submission->destination !== '') {
            $body['destination'] = $submission->destination;
        }

        return $body;
    }
}
