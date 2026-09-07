<?php

declare(strict_types=1);

namespace BytePhase\Connector\Connectors;

use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Settings\FormDestinations;

defined('ABSPATH') || exit;

/**
 * The public integration point for any theme or plugin:
 *
 *   do_action('bytephase_submit_form', ['form_id' => '...', 'data' => [...]]);
 *   $result = apply_filters('bytephase_submission', ['form_id' => '...', 'data' => [...]]);
 */
final class GenericConnector implements Connector
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly FormDestinations $destinations,
    ) {
    }

    public function slug(): string
    {
        return 'generic';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function register(): void
    {
        add_action('bytephase_submit_form', [$this, 'handleAction'], 10, 1);
        add_filter('bytephase_submission', [$this, 'handleFilter'], 10, 1);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handleAction(array $input): void
    {
        $submission = $this->build($input);

        if ($submission !== null) {
            $this->dispatcher->dispatch($submission);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function handleFilter(array $input): ?array
    {
        $submission = $this->build($input);

        if ($submission === null) {
            return null;
        }

        $result = $this->dispatcher->dispatch($submission);

        return [
            'outcome' => $result->outcome,
            'status_code' => $result->statusCode,
            'request_id' => $result->requestId,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function build(array $input): ?Submission
    {
        $data = $input['data'] ?? null;

        if (! is_array($data) || $data === []) {
            return null;
        }

        $provider = (string) ($input['provider'] ?? 'wordpress');
        $formId = (string) ($input['form_id'] ?? '');

        if ($this->destinations->isIgnored($provider, $formId)) {
            return null;
        }

        // An explicit destination in the call always wins; otherwise fall back to the
        // choice saved on BytePhase → Forms.
        $destination = isset($input['destination'])
            ? (string) $input['destination']
            : $this->destinations->resolve($provider, $formId, $data);

        return new Submission(
            provider: $provider,
            formId: $formId,
            data: $data,
            destination: $destination,
        );
    }
}
