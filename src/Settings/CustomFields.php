<?php

declare(strict_types=1);

namespace BytePhase\Connector\Settings;

defined('ABSPATH') || exit;

/**
 * What the site does with the shop's custom fields — never what those fields are.
 *
 * The definitions (label, type, options, required) live in BytePhase and are pulled at
 * runtime, so a field is edited in one place and the website follows. All this option
 * stores is the site's own decisions: show them or not, which form type to pull from,
 * and which of those fields are published on the public form.
 */
final class CustomFields
{
    public const OPTION = 'bytephase_connector_custom_fields';

    /** The form type each destination pulls from until the shop picks another. */
    private const DEFAULT_FORM_TYPES = [
        FormDestinations::LEAD => 'Lead',
        FormDestinations::SELF_CHECKIN => 'Self check-in',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (! is_array($stored)) {
            return [];
        }

        $config = [];

        foreach (FormDestinations::DESTINATIONS as $destination) {
            $config[$destination] = $this->config($destination);
        }

        return $config;
    }

    /**
     * @return array{enabled: bool, form_type: string, fields: array<int, string>, configured: bool}
     */
    public function config(string $destination): array
    {
        $stored = get_option(self::OPTION, []);
        $entry = is_array($stored) && isset($stored[$destination]) && is_array($stored[$destination])
            ? $stored[$destination]
            : [];

        $fields = isset($entry['fields']) && is_array($entry['fields'])
            ? array_values(array_filter(array_map('strval', $entry['fields'])))
            : [];

        return [
            'enabled' => ! empty($entry['enabled']),
            'form_type' => isset($entry['form_type']) && is_string($entry['form_type']) && $entry['form_type'] !== ''
                ? $entry['form_type']
                : self::defaultFormType($destination),
            'fields' => $fields,
            // Set the first time the shop saves this screen. It separates "has not
            // touched this yet" from "deliberately unticked everything", which would
            // otherwise both look like an empty list.
            'configured' => ! empty($entry['configured']),
        ];
    }

    public function isEnabled(string $destination): bool
    {
        return $this->config($destination)['enabled'];
    }

    public function formType(string $destination): string
    {
        return $this->config($destination)['form_type'];
    }

    /**
     * The field names the shop publishes on this form. Before the screen has ever been
     * saved an empty list means "not chosen yet" and everything is published, so turning
     * the feature on is enough. Once saved, an empty list means exactly that: only the
     * required fields, which cannot be withheld, survive.
     *
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, array<string, mixed>>
     */
    public function published(string $destination, array $definitions): array
    {
        $config = $this->config($destination);
        $chosen = $config['fields'];

        if ($chosen === [] && ! $config['configured']) {
            return $definitions;
        }

        return array_values(array_filter(
            $definitions,
            // A required field is never optional to publish: BytePhase rejects the
            // submission without it, so hiding it would only produce silent failures.
            static fn (array $field): bool => in_array((string) $field['field_name'], $chosen, true)
                || ! empty($field['is_field_required']),
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     */
    public function save(array $config): void
    {
        $clean = [];

        foreach (FormDestinations::DESTINATIONS as $destination) {
            $entry = $config[$destination] ?? [];

            $clean[$destination] = [
                'enabled' => ! empty($entry['enabled']),
                'configured' => true,
                'form_type' => isset($entry['form_type']) && is_string($entry['form_type'])
                    ? sanitize_text_field($entry['form_type'])
                    : self::defaultFormType($destination),
                'fields' => isset($entry['fields']) && is_array($entry['fields'])
                    ? array_values(array_unique(array_map(
                        static fn ($field): string => sanitize_text_field((string) $field),
                        $entry['fields'],
                    )))
                    : [],
            ];
        }

        update_option(self::OPTION, $clean, false);
    }

    public static function defaultFormType(string $destination): string
    {
        return self::DEFAULT_FORM_TYPES[$destination] ?? 'Lead';
    }
}
