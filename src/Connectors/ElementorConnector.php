<?php

declare(strict_types=1);

namespace BytePhase\Connector\Connectors;

use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Settings\FormDestinations;

defined('ABSPATH') || exit;

/**
 * Elementor Pro Forms. Fires on each new record, forwards the field values verbatim,
 * keyed by the form's name (BytePhase maps them per form_id).
 */
final class ElementorConnector implements Connector
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly FormDestinations $destinations,
    ) {
    }

    public function slug(): string
    {
        return 'elementor';
    }

    public function isAvailable(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION');
    }

    public function register(): void
    {
        add_action('elementor_pro/forms/new_record', [$this, 'handle'], 10, 2);
    }

    /**
     * @param  object  $record   \ElementorPro\Modules\Forms\Classes\Form_Record
     * @param  object  $handler  \ElementorPro\Modules\Forms\Classes\Ajax_Handler
     */
    public function handle($record, $handler): void
    {
        $fields = $record->get('fields');

        if (! is_array($fields)) {
            return;
        }

        $data = [];

        foreach ($fields as $id => $field) {
            $data[(string) $id] = $field['value'] ?? '';
        }

        if ($data === []) {
            return;
        }

        $formId = (string) ($record->get_form_settings('form_name') ?: $record->get_form_settings('id') ?: '');

        if ($this->destinations->isIgnored('elementor', $formId)) {
            return;
        }

        $this->dispatcher->dispatch(new Submission(
            'elementor',
            $formId,
            $data,
            $this->destinations->resolve('elementor', $formId, $data),
        ));
    }
}
