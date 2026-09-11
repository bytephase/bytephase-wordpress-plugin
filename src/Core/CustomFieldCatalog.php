<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

defined('ABSPATH') || exit;

/**
 * The shop's custom field definitions, as BytePhase holds them.
 *
 * Cached in a transient because a definition changes about as often as a shop redesigns
 * its forms, while the pages carrying those forms are hit constantly — and every visitor
 * paying for a round trip to BytePhase would also burn the integration's rate limit.
 *
 * A failed refresh keeps serving the last good copy: a form that renders yesterday's
 * labels is better than a form that renders none. The failure itself is remembered for a
 * few minutes, because the public form reads this on every page view — without that, an
 * outage (or a revoked key) would cost every visitor a round trip that can time out at
 * ApiClient's 15 seconds before the page finishes loading.
 */
final class CustomFieldCatalog
{
    private const FIELDS_TRANSIENT = 'bytephase_connector_cf_';
    private const TYPES_TRANSIENT = 'bytephase_connector_cf_types';

    /** Marks a failed fetch, so the next reads skip BytePhase until it expires. */
    private const FAILURE_TRANSIENT = 'bytephase_connector_cf_fail_';
    private const TYPES_FAILURE_TRANSIENT = 'bytephase_connector_cf_types_fail';

    /** An option, not a transient: it must outlive the TTL to be served when a refresh fails. */
    private const LAST_GOOD_OPTION = 'bytephase_connector_cf_last_';

    private const TTL = 12 * HOUR_IN_SECONDS;
    private const FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

    private const NOTHING = ['fields' => [], 'fetched_at' => 0];

    /**
     * Results already resolved in this request, so a caller reading both fields() and
     * syncedAt() — the Forms screen does — pays for one round trip, not two.
     *
     * @var array<string, array{fields: array<int, array<string, mixed>>, fetched_at: int}>
     */
    private array $resolved = [];

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

    /**
     * When the copy being served was fetched. After a failed refresh this is the last good
     * copy's time, which is how the Forms screen can show that the fields are stale.
     */
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

        if (get_transient(self::TYPES_FAILURE_TRANSIENT) !== false) {
            return [];
        }

        $response = $this->client->customFields(null);

        if (! is_array($response) || ! isset($response['form_types']) || ! is_array($response['form_types'])) {
            set_transient(self::TYPES_FAILURE_TRANSIENT, 1, self::FAILURE_TTL);

            return [];
        }

        $types = [];

        foreach ($response['form_types'] as $row) {
            if (is_array($row) && isset($row['form_type']) && is_string($row['form_type'])) {
                $types[] = $row['form_type'];
            }
        }

        set_transient(self::TYPES_TRANSIENT, $types, self::TTL);

        return $types;
    }

    /**
     * Drop every cached copy — fresh, last good and any remembered failure — so the next
     * read goes to BytePhase. Called when the shop presses Refresh, and when the connection
     * settings change underneath us, when a copy from the old connection would be wrong.
     */
    public function forget(): void
    {
        delete_transient(self::TYPES_TRANSIENT);
        delete_transient(self::TYPES_FAILURE_TRANSIENT);

        foreach ($this->knownFormTypes() as $formType) {
            $hash = md5($formType);

            delete_transient(self::FIELDS_TRANSIENT . $hash);
            delete_transient(self::FAILURE_TRANSIENT . $hash);
            delete_option(self::LAST_GOOD_OPTION . $hash);
        }

        delete_option(self::FIELDS_TRANSIENT . 'index');

        $this->resolved = [];
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>, fetched_at: int}
     */
    private function cached(string $formType): array
    {
        if ($formType === '') {
            return self::NOTHING;
        }

        return $this->resolved[$formType] ??= $this->resolve($formType);
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>, fetched_at: int}
     */
    private function resolve(string $formType): array
    {
        $hash = md5($formType);
        $fresh = get_transient(self::FIELDS_TRANSIENT . $hash);

        if ($this->isEntry($fresh)) {
            return $this->entry($fresh);
        }

        // Inside a failure window, do not ask again: fall straight through to the last
        // good copy rather than make this visitor wait on a BytePhase that just failed.
        if (get_transient(self::FAILURE_TRANSIENT . $hash) === false) {
            $response = $this->client->customFields($formType);

            if (is_array($response) && isset($response['fields']) && is_array($response['fields'])) {
                $entry = [
                    'fields' => $this->normalize($response['fields']),
                    'fetched_at' => time(),
                ];

                set_transient(self::FIELDS_TRANSIENT . $hash, $entry, self::TTL);
                update_option(self::LAST_GOOD_OPTION . $hash, $entry, false);
                $this->rememberFormType($formType);

                return $entry;
            }

            set_transient(self::FAILURE_TRANSIENT . $hash, 1, self::FAILURE_TTL);
            // Remembered even on failure, so forget() can clear the failure marker too.
            $this->rememberFormType($formType);
        }

        $lastGood = get_option(self::LAST_GOOD_OPTION . $hash, null);

        return $this->isEntry($lastGood) ? $this->entry($lastGood) : self::NOTHING;
    }

    /**
     * @phpstan-assert-if-true array{fields: array<int, array<string, mixed>>} $value
     */
    private function isEntry(mixed $value): bool
    {
        return is_array($value) && isset($value['fields']) && is_array($value['fields']);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{fields: array<int, array<string, mixed>>, fetched_at: int}
     */
    private function entry(array $value): array
    {
        return ['fields' => $value['fields'], 'fetched_at' => (int) ($value['fetched_at'] ?? 0)];
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
                'placeholder' => isset($field['placeholder']) ? (string) $field['placeholder'] : '',
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
