<?php

declare(strict_types=1);

namespace BytePhase\Connector;

use BytePhase\Connector\Connectors\Cf7Connector;
use BytePhase\Connector\Connectors\ConnectorRegistry;
use BytePhase\Connector\Connectors\ElementorConnector;
use BytePhase\Connector\Connectors\GenericConnector;
use BytePhase\Connector\Connectors\NativeConnector;
use BytePhase\Connector\Core\ActivityLog;
use BytePhase\Connector\Core\ApiClient;
use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\PendingSubmissions;
use BytePhase\Connector\Settings\Credentials;
use BytePhase\Connector\Settings\FormDestinations;
use BytePhase\Connector\Settings\FormsPage;
use BytePhase\Connector\Settings\HealthPage;
use BytePhase\Connector\Settings\Settings;
use BytePhase\Connector\Settings\SettingsPage;

defined('ABSPATH') || exit;

/**
 * Composition root. Wires the collaborators by hand — no container, no framework.
 */
final class Plugin
{
    /** Canonical setup guide — the WordPress integration page on bytephase.com. */
    public const DOCS_URL = 'https://bytephase.com/integrations/wordpress/';
    public const SUPPORT_URL = 'https://wordpress.org/support/plugin/bytephase-connector/';

    private const CRON_SCHEDULE = 'bytephase_five_minutes';
    private const ACTIVATION_REDIRECT = 'bytephase_connector_activation_redirect';

    private static ?Plugin $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        // No load_plugin_textdomain(): WordPress auto-loads translations for
        // directory-hosted plugins since 4.6; the .pot ships for translators.
        $settings = new Settings();
        $credentials = new Credentials();
        $client = new ApiClient($settings, $credentials);
        $log = new ActivityLog();
        $pending = new PendingSubmissions();
        $dispatcher = new Dispatcher($client, $pending, $log);
        $destinations = new FormDestinations();

        (new ConnectorRegistry(
            new GenericConnector($dispatcher, $destinations),
            new Cf7Connector($dispatcher, $destinations),
            new ElementorConnector($dispatcher, $destinations),
            // The shortcodes carry their own destination, so they need no choice.
            new NativeConnector($dispatcher),
        ))->boot();

        add_filter('cron_schedules', [$this, 'registerSchedule']);
        add_action(PendingSubmissions::CRON_HOOK, [$dispatcher, 'processRetries']);

        // Deferred to init: asking for the schedule list runs registerSchedule(), whose
        // display name is translated, and translating before init trips WordPress's
        // _load_textdomain_just_in_time notice.
        add_action('init', [$this, 'ensureCronScheduled']);

        if (is_admin()) {
            (new SettingsPage($settings, $credentials, $client))->boot();
            (new FormsPage($destinations, $log))->boot();
            (new HealthPage($settings, $credentials, $log, $pending))->boot();

            add_action('admin_init', [$this, 'maybeRedirectAfterActivation']);
            add_action('admin_init', [$this, 'registerPrivacyPolicyContent']);
            add_filter(
                'plugin_action_links_' . plugin_basename(BYTEPHASE_CONNECTOR_FILE),
                [$this, 'addSettingsLink'],
            );
            add_filter('plugin_row_meta', [$this, 'addRowMeta'], 10, 2);
        }
    }

    /**
     * Send the owner straight to the Connection screen the first time the plugin is activated.
     */
    public function maybeRedirectAfterActivation(): void
    {
        if (! get_transient(self::ACTIVATION_REDIRECT)) {
            return;
        }

        delete_transient(self::ACTIVATION_REDIRECT);

        // Don't hijack AJAX, or bulk/network activations.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own bulk-activation marker, read only to decide whether to redirect.
        if (wp_doing_ajax() || is_network_admin() || isset($_GET['activate-multi'])) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG));
        exit;
    }

    /**
     * Docs + Support links on the Plugins list row.
     *
     * @param  array<int, string>  $meta
     * @return array<int, string>
     */
    public function addRowMeta(array $meta, string $file): array
    {
        if ($file !== plugin_basename(BYTEPHASE_CONNECTOR_FILE)) {
            return $meta;
        }

        $meta[] = sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(self::DOCS_URL), esc_html__('Docs', 'bytephase-connector'));
        $meta[] = sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(self::SUPPORT_URL), esc_html__('Support', 'bytephase-connector'));

        return $meta;
    }

    /**
     * Suggested text for the site's privacy policy: the plugin transmits form
     * submissions to BytePhase, and site owners must be able to disclose that.
     */
    public function registerPrivacyPolicyContent(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p>' . esc_html__('When a visitor submits a form connected to BytePhase, the submitted details (such as name, email address, phone number, device information, and message) are transmitted to BytePhase (bytephase.com), the repair shop management service this website uses, so that the shop can respond to the enquiry.', 'bytephase-connector') . '</p>'
            . '<p>' . esc_html__('Once a submission reaches BytePhase, this website does not keep the submitted details. A submission that cannot be delivered — for example while the connection is misconfigured — is held in this website\'s database until it is delivered, and in any case for no longer than 30 days, so that the enquiry is not lost. Separately, an operational delivery log (time, delivery status, and a technical request id — never the submitted content) is kept for up to 30 days.', 'bytephase-connector') . '</p>'
            . '<p>' . sprintf(
                /* translators: %s: link to the BytePhase privacy policy */
                esc_html__('Data transmitted to BytePhase is handled according to the BytePhase privacy policy: %s', 'bytephase-connector'),
                '<a href="https://bytephase.com/privacy-policy/">https://bytephase.com/privacy-policy/</a>'
            ) . '</p>';

        wp_add_privacy_policy_content(
            __('BytePhase Connector', 'bytephase-connector'),
            wp_kses_post($content),
        );
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    public function addSettingsLink(array $links): array
    {
        $url = admin_url('admin.php?page=' . SettingsPage::MENU_SLUG);

        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Connect', 'bytephase-connector')));

        return $links;
    }

    /**
     * @param  array<string, array{interval: int, display: string}>  $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function registerSchedule(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every five minutes (BytePhase)', 'bytephase-connector'),
        ];

        return $schedules;
    }

    public static function activate(): void
    {
        PendingSubmissions::createTable();
        set_transient(self::ACTIVATION_REDIRECT, true, 30);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(PendingSubmissions::CRON_HOOK);
    }

    public function ensureCronScheduled(): void
    {
        if (! wp_next_scheduled(PendingSubmissions::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, PendingSubmissions::CRON_HOOK);
        }
    }
}
