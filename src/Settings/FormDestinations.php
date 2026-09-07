<?php

declare(strict_types=1);

namespace BytePhase\Connector\Settings;

defined('ABSPATH') || exit;

/**
 * Which BytePhase record each form on this site creates.
 *
 * The native shortcodes already know: [bytephase_lead_form] is a Lead and
 * [bytephase_self_checkin] is a Self check-in. Contact Form 7, Elementor and theme forms
 * do not — they post their own field names and no destination at all — so without a choice
 * stored here the routing depends on a form id only BytePhase knows. Keys are
 * "<provider>:<form id>"; a form nobody has chosen for sends no destination and behaves
 * exactly as it did before this screen existed.
 */
final class FormDestinations
{
    public const OPTION = 'bytephase_connector_destinations';

    public const LEAD = 'lead';
    public const SELF_CHECKIN = 'self_checkin';
    public const IGNORE = 'ignore';

    /** Every value the screen may store. */
    public const CHOICES = [self::LEAD, self::SELF_CHECKIN, self::IGNORE];

    /** The two that are real destinations; IGNORE means "do not send at all". */
    public const DESTINATIONS = [self::LEAD, self::SELF_CHECKIN];

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (! is_array($stored)) {
            return [];
        }

        $map = [];

        foreach ($stored as $key => $value) {
            if (is_string($key) && is_string($value) && in_array($value, self::CHOICES, true)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    public static function key(string $provider, string $formId): string
    {
        return $provider . ':' . $formId;
    }

    /**
     * The stored choice for one form, whatever it is — including "ignore".
     */
    public function choice(string $provider, string $formId): ?string
    {
        return $this->all()[self::key($provider, $formId)] ?? null;
    }

    /**
     * The destination to pin on a submission, or null to leave the choice to BytePhase's
     * own per-form mapping (which is what happens when the shop has not chosen).
     *
     * @param  array<string, mixed>  $data
     */
    public function resolve(string $provider, string $formId, array $data = []): ?string
    {
        /**
         * Filter the destination for a single form.
         *
         * Return 'lead' or 'self_checkin' to pin it, or null to let BytePhase decide from
         * the form mapping. Useful when one form should become a repair booking only for
         * certain submissions.
         *
         * @param  string|null           $destination  the choice saved on BytePhase → Forms
         * @param  string                $provider     'cf7', 'elementor', 'wordpress'
         * @param  string                $formId
         * @param  array<string, mixed>  $data         the submitted fields
         */
        $destination = apply_filters(
            'bytephase_connector_destination',
            $this->choice($provider, $formId),
            $provider,
            $formId,
            $data,
        );

        return in_array($destination, self::DESTINATIONS, true) ? $destination : null;
    }

    /**
     * The shop asked for this form to be left alone — it is not sent to BytePhase at all.
     */
    public function isIgnored(string $provider, string $formId): bool
    {
        return $this->choice($provider, $formId) === self::IGNORE;
    }

    /**
     * @param  array<string, string>  $map
     */
    public function save(array $map): void
    {
        $clean = [];

        foreach ($map as $key => $value) {
            if (is_string($key) && $key !== '' && in_array($value, self::CHOICES, true)) {
                $clean[$key] = $value;
            }
        }

        update_option(self::OPTION, $clean, false);
    }
}
