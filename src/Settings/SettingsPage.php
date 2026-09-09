<?php

declare(strict_types=1);

namespace BytePhase\Connector\Settings;

use BytePhase\Connector\Core\ApiClient;
use BytePhase\Connector\Plugin;

defined('ABSPATH') || exit;

/**
 * The Connection screen: web address, store ID, and a write-only API key, plus a
 * Test Connection action. Keeps the key out of the rendered HTML entirely.
 */
final class SettingsPage
{
    public const MENU_SLUG = 'bytephase-connector';

    private const CAPABILITY = 'manage_options';
    private const SAVE_ACTION = 'bytephase_save_settings';
    private const TEST_ACTION = 'bytephase_test_connection';

    /**
     * Screen ids returned by add_menu_page()/add_submenu_page(); they identify this
     * screen inside admin_enqueue_scripts.
     *
     * @var array<int, string>
     */
    private array $hookSuffixes = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Credentials $credentials,
        private readonly ApiClient $client,
    ) {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('admin_post_' . self::TEST_ACTION, [$this, 'handleTest']);
    }

    public function registerMenu(): void
    {
        $hookSuffixes = [];

        $hookSuffixes[] = add_menu_page(
            __('BytePhase', 'bytephase-connector'),
            __('BytePhase', 'bytephase-connector'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-share-alt',
            80,
        );

        $hookSuffixes[] = add_submenu_page(
            self::MENU_SLUG,
            __('Connection', 'bytephase-connector'),
            __('Connection', 'bytephase-connector'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
        );

        $this->hookSuffixes = array_values(array_filter($hookSuffixes, 'is_string'));
    }

    /**
     * Load this screen's stylesheet, and only on this screen — never globally
     * across wp-admin.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if (! in_array($hookSuffix, $this->hookSuffixes, true)) {
            return;
        }

        wp_enqueue_style(
            'bytephase-connector-admin',
            BYTEPHASE_CONNECTOR_URL . 'assets/admin.css',
            [],
            BYTEPHASE_CONNECTOR_VERSION,
        );
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag from our own post-redirect-get; it only picks which notice to show.
        $test = isset($_GET['bytephase_test']) ? sanitize_key(wp_unslash((string) $_GET['bytephase_test'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BytePhase Connection', 'bytephase-connector'); ?></h1>

            <div class="card bytephase-card">
                <h2><?php esc_html_e('How it works', 'bytephase-connector'); ?></h2>
                <p><?php esc_html_e('BytePhase Connector sends your website forms to BytePhase as Leads or Self Check-ins. You do not map any fields here — BytePhase does that for you. Setup takes about a minute:', 'bytephase-connector'); ?></p>
                <ol>
                    <li><?php esc_html_e('Enter your details below and click Test Connection.', 'bytephase-connector'); ?></li>
                    <li>
                        <?php
                        printf(
                            /* translators: %s: shortcode name */
                            esc_html__('Add a form to a page, or drop in the %s shortcode.', 'bytephase-connector'),
                            '<code>[bytephase_lead_form]</code>'
                        );
                        ?>
                    </li>
                    <li><?php esc_html_e('In BytePhase, map that form\'s fields once. Done.', 'bytephase-connector'); ?></li>
                </ol>
                <p>
                    <a href="<?php echo esc_url(Plugin::DOCS_URL); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Where do I find these? Read the step-by-step setup guide →', 'bytephase-connector'); ?>
                    </a>
                </p>
            </div>

            <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flags from our own post-redirect-get; they only pick which notice to show. ?>
            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'bytephase-connector'); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['bytephase_error']) && sanitize_key(wp_unslash((string) $_GET['bytephase_error'])) === 'https') : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('The BytePhase API address must start with https:// — your settings were not saved. Please check the address and try again.', 'bytephase-connector'); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

            <?php $this->renderTestNotice($test); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::SAVE_ACTION); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="bytephase_base_url"><?php esc_html_e('BytePhase API Address', 'bytephase-connector'); ?></label></th>
                        <td>
                            <input name="base_url" id="bytephase_base_url" type="url" class="regular-text" placeholder="https://api.bytephase.com" value="<?php echo esc_attr($this->settings->baseUrl()); ?>">
                            <p class="description"><?php esc_html_e('Copy this from BytePhase: Settings → Integrations → Website Forms → WordPress. It is not the address you sign in at.', 'bytephase-connector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bytephase_tenant"><?php esc_html_e('Store ID', 'bytephase-connector'); ?></label></th>
                        <td>
                            <input name="tenant" id="bytephase_tenant" type="text" class="regular-text" value="<?php echo esc_attr($this->settings->tenantSlug()); ?>">
                            <p class="description"><?php esc_html_e('Identifies your store in BytePhase. Find it — together with your API key — under Settings → Integrations → Website Forms → WordPress in BytePhase.', 'bytephase-connector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bytephase_api_key"><?php esc_html_e('API Key', 'bytephase-connector'); ?></label></th>
                        <td>
                            <input name="api_key" id="bytephase_api_key" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($this->credentials->hasKey() ? $this->credentials->masked() : ''); ?>">
                            <p class="description">
                                <?php
                                echo $this->credentials->hasKey()
                                    ? esc_html__('A key is saved. Enter a new one only to replace it.', 'bytephase-connector')
                                    : esc_html__('Paste the API key from your BytePhase WordPress integration. It is stored securely and never shown again after you save.', 'bytephase-connector');
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save', 'bytephase-connector')); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ACTION); ?>">
                <?php wp_nonce_field(self::TEST_ACTION); ?>
                <?php submit_button(__('Test Connection', 'bytephase-connector'), 'secondary', 'submit', false); ?>
            </form>

            <div class="card bytephase-card">
                <h2><?php esc_html_e('After you connect', 'bytephase-connector'); ?></h2>
                <ul class="bytephase-list">
                    <li>
                        <?php
                        printf(
                            /* translators: 1: lead form shortcode, 2: self check-in shortcode */
                            esc_html__('Add a ready-made form: paste %1$s (an enquiry) or %2$s (a repair booking) into any page or post.', 'bytephase-connector'),
                            '<code>[bytephase_lead_form]</code>',
                            '<code>[bytephase_self_checkin]</code>'
                        );
                        ?>
                    </li>
                    <li><?php esc_html_e('Already using Contact Form 7 or Elementor? Nothing to change — your existing form works. Just map its fields once in BytePhase.', 'bytephase-connector'); ?></li>
                    <li>
                        <?php
                        printf(
                            /* translators: %s: menu location */
                            esc_html__('Want to ask more than the standard questions? Create the fields in BytePhase, then switch them on for the ready-made forms under %s.', 'bytephase-connector'),
                            '<strong>BytePhase &rarr; Forms</strong>'
                        );
                        ?>
                    </li>
                    <li>
                        <?php
                        printf(
                            /* translators: %s: menu location */
                            esc_html__('See what has been sent, and retry anything that failed, under %s.', 'bytephase-connector'),
                            '<strong>BytePhase &rarr; Health</strong>'
                        );
                        ?>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        $this->guard(self::SAVE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() above runs check_admin_referer() before anything is read.
        $rawUrl = sanitize_text_field(wp_unslash((string) ($_POST['base_url'] ?? '')));

        // Be forgiving about a missing scheme, but never accept plain http:// — the
        // API key would travel in cleartext.
        if ($rawUrl !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $rawUrl)) {
            $rawUrl = 'https://' . $rawUrl;
        }

        $baseUrl = esc_url_raw($rawUrl, ['https']);

        if ($rawUrl !== '' && $baseUrl === '') {
            wp_safe_redirect(add_query_arg('bytephase_error', 'https', $this->pageUrl()));
            exit;
        }

        update_option(Settings::OPTION_BASE_URL, $baseUrl);
        update_option(Settings::OPTION_TENANT, sanitize_text_field(wp_unslash((string) ($_POST['tenant'] ?? ''))));

        $key = sanitize_text_field(wp_unslash((string) ($_POST['api_key'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($key !== '') {
            $this->credentials->save($key);
        }

        wp_safe_redirect(add_query_arg('saved', '1', $this->pageUrl()));
        exit;
    }

    public function handleTest(): void
    {
        $this->guard(self::TEST_ACTION);

        $result = $this->client->testConnection();

        $status = match (true) {
            $result->provesConnection() => 'ok',
            $result->isAuthError() => 'auth',
            $result->isTenantError() => 'tenant',
            default => 'unreachable',
        };

        wp_safe_redirect(add_query_arg('bytephase_test', $status, $this->pageUrl()));
        exit;
    }

    private function renderTestNotice(string $test): void
    {
        if ($test === '') {
            return;
        }

        [$class, $message] = match ($test) {
            'ok' => ['notice-success', __('Connected to BytePhase.', 'bytephase-connector')],
            'auth' => ['notice-error', __('The API key was rejected. Enter a new API key and save, then test again.', 'bytephase-connector')],
            'tenant' => ['notice-error', __('The Store ID was not recognized. Check the Store ID and try again.', 'bytephase-connector')],
            default => ['notice-error', __('Could not reach BytePhase. Check the API address and try again.', 'bytephase-connector')],
        };

        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($message));
    }

    private function guard(string $action): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'bytephase-connector'));
        }

        check_admin_referer($action);
    }

    private function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }
}
