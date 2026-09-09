<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

defined('ABSPATH') || exit;

/**
 * The shop's custom field definitions, as BytePhase holds them.
 *
 * Cached in a transient because a definition changes about as often as a shop redesigns
 * its forms, while the pages carrying those forms are hit constantly — and every visitor
 * paying for a round trip to BytePhase would also burn the integration's rate limit. A
 * failed refresh keeps serving the last good copy: a form that renders yesterday's labels
 * is better than a form that renders none.
 */
final class CustomFieldCatalog
{
    private const FIELDS_TRANSIENT = 'bytephase_connector_cf_';
    private const TYPES_TRANSIENT = 'bytephase_connector_cf_types';

    private const TTL = 12 * HOUR_IN_SECONDS;

    public function __construct(private readonly ApiClient $client)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(string $formType): array
    {
        return $this->cached($formType)['fields'];
    }

    public function syncedAt(string $formType): ?int
    {
        $fetchedAt = $this->cached($formType)['fetched_at'];

        return $fetchedAt > 0 ? $fetchedAt : null;
    }

    /**
     * Form types that actually have fields configured — the only ones worth offering.
     *
     * @return array<int, string>
     */
    public function formTypes(): array
    {
        $cached = get_transient(self::TYPES_TRANSIENT);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->client->customFields(null);
        $types = [];

        if (is_array($response) && isset($response['form_types']) && is_array($response['form_types'])) {
            foreach ($response['form_types'] as $row) {
                if (is_array($row) && isset($row['form_type']) && is_string($row['form_type'])) {
                    $types[] = $row['form_type'];
                }
            }

            set_transient(self::TYPES_TRANSIENT, $types, self::TTL);
        }

        return $types;
    }

    /**
     * Drop every cached copy so the next read goes to BytePhase. Called when the shop
     * presses Refresh, and when the connection settings change underneath us.
     */
    public function forget(): void
    {
        delete_transient(self::TYPES_TRANSIENT);

        foreach ($this->knownFormTypes() as $formType) {
            delete_transient(self::FIELDS_TRANSIENT . md5($formType));
        }

        delete_option(self::FIELDS_TRANSIENT . 'index');
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>, fetched_at: int}
     */
    private function cached(string $formType): array
    {
        if ($formType === '') {
            return ['fields' => [], 'fetched_at' => 0];
        }

        $key = self::FIELDS_TRANSIENT . md5($formType);
        $cached = get_transient($key);

        if (is_array($cached) && isset($cached['fields'])) {
            return ['fields' => $cached['fields'], 'fetched_at' => (int) ($cached['fetched_at'] ?? 0)];
        }

        $response = $this->client->customFields($formType);

        if (! is_array($response) || ! isset($response['fields']) || ! is_array($response['fields'])) {
            // Unreachable or malformed: remember nothing, so the next render retries.
            return ['fields' => [], 'fetched_at' => 0];
        }

        $entry = [
            'fields' => $this->normalize($response['fields']),
            'fetched_at' => time(),
        ];

        set_transient($key, $entry, self::TTL);
        $this->rememberFormType($formType);

        return $entry;
    }

    /**
     * Trust nothing about the shape: a form rendered from a malformed definition is a
     * broken public page.
     *
     * @param  array<int, mixed>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! isset($field['field_name']) || (string) $field['field_name'] === '') {
                continue;
            }

            $type = isset($field['field_type']) ? (string) $field['field_type'] : 'Text';
            $items = isset($field['select_box_items']) && is_array($field['select_box_items'])
                ? array_values(array_map('strval', $field['select_box_items']))
                : [];

            $normalized[] = [
                'field_name' => (string) $field['field_name'],
                'field_type' => in_array($type, ['Text', 'Number', 'Dropdown'], true) ? $type : 'Text',
                'placeholder' => isset($field['placeholder']) && $field['placeholder'] !== null
                    ? (string) $field['placeholder']
                    : '',
                'is_field_required' => ! empty($field['is_field_required']),
                'select_box_items' => $items,
            ];
        }

        return $normalized;
    }

    /**
     * Transients have no wildcard delete, so the form types ever cached are tracked in
     * an option purely so Refresh can clear all of them.
     */
    private function rememberFormType(string $formType): void
    {
        $known = $this->knownFormTypes();

        if (! in_array($formType, $known, true)) {
            $known[] = $formType;
            update_option(self::FIELDS_TRANSIENT . 'index', $known, false);
        }
    }

    /**
     * @return array<int, string>
     */
    private function knownFormTypes(): array
    {
        $known = get_option(self::FIELDS_TRANSIENT . 'index', []);

        return is_array($known) ? array_values(array_map('strval', $known)) : [];
    }
}
