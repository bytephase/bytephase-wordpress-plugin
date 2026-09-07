<?php

declare(strict_types=1);

namespace BytePhase\Connector\Settings;

use BytePhase\Connector\Core\ActivityLog;
use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Core\PendingSubmissions;
use BytePhase\Connector\Plugin;

defined('ABSPATH') || exit;

/**
 * Operational status only — "did my submission leave WordPress and did BytePhase accept it?".
 * Never shows customer or business data; links out to BytePhase for that.
 */
final class HealthPage
{
    private const CAPABILITY = 'manage_options';
    private const RETRY_ACTION = 'bytephase_retry_pending';
    private const PAGE_SLUG = 'bytephase-connector-health';

    /** Set by add_submenu_page(); identifies this screen inside admin_enqueue_scripts. */
    private string $hookSuffix = '';

    public function __construct(
        private readonly Settings $settings,
        private readonly Credentials $credentials,
        private readonly ActivityLog $log,
        private readonly PendingSubmissions $pending,
    ) {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_' . self::RETRY_ACTION, [$this, 'handleRetry']);
    }

    public function registerMenu(): void
    {
        $hookSuffix = add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Health', 'bytephase-connector'),
            __('Health', 'bytephase-connector'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
        );

        if (is_string($hookSuffix)) {
            $this->hookSuffix = $hookSuffix;
        }
    }

    /**
     * Load this screen's stylesheet and the copy-to-clipboard script, and only on
     * this screen — never globally across wp-admin.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($this->hookSuffix === '' || $hookSuffix !== $this->hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'bytephase-connector-admin',
            BYTEPHASE_CONNECTOR_URL . 'assets/admin.css',
            [],
            BYTEPHASE_CONNECTOR_VERSION,
        );

        wp_enqueue_script(
            'bytephase-connector-admin',
            BYTEPHASE_CONNECTOR_URL . 'assets/admin.js',
            [],
            BYTEPHASE_CONNECTOR_VERSION,
            true,
        );
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $authFailed = $this->settings->authFailed();
        $connected = $this->settings->isConfigured() && $this->credentials->hasKey() && ! $authFailed;
        $week = $this->log->stats(7);
        $rate = $week['total'] > 0 ? (int) round($week['success'] / $week['total'] * 100) : 100;
        $recent = $this->log->recent(10);
        $failed = $this->pending->failed();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BytePhase Health', 'bytephase-connector'); ?></h1>

            <div class="card bytephase-card">
                <p class="bytephase-connection">
                    <span class="<?php echo esc_attr($connected ? 'bytephase-status--ok' : 'bytephase-status--fail'); ?>" aria-hidden="true">●</span>
                    <strong>
                        <?php
                        echo $authFailed
                            ? esc_html__('Reconnect needed', 'bytephase-connector')
                            : ($connected ? esc_html__('Connected', 'bytephase-connector') : esc_html__('Not connected', 'bytephase-connector'));
                        ?>
                    </strong>
                </p>
                <?php if ($authFailed) : ?>
                    <p>
                        <?php esc_html_e('BytePhase rejected the API key — it may have expired or been replaced. Submissions are not being delivered.', 'bytephase-connector'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)); ?>"><?php esc_html_e('Update your API key', 'bytephase-connector'); ?></a>
                    </p>
                <?php endif; ?>
                <table class="widefat striped">
                    <tbody>
                        <tr><td><?php esc_html_e('API Key', 'bytephase-connector'); ?></td><td><?php echo $this->credentials->hasKey() ? esc_html__('Saved', 'bytephase-connector') : esc_html__('Missing', 'bytephase-connector'); ?></td></tr>
                        <tr><td><?php esc_html_e('Success rate (7 days)', 'bytephase-connector'); ?></td><td><?php echo esc_html($rate . '%'); ?></td></tr>
                        <tr><td><?php esc_html_e('Failed requests (7 days)', 'bytephase-connector'); ?></td><td><?php echo esc_html((string) $week['failed']); ?></td></tr>
                        <tr><td><?php esc_html_e('Last submission', 'bytephase-connector'); ?></td><td><?php echo esc_html($this->lastSubmission($recent)); ?></td></tr>
                    </tbody>
                </table>
                <?php if ($this->settings->dashboardUrl() !== '') : ?>
                    <p><a class="button button-secondary" href="<?php echo esc_url($this->settings->dashboardUrl()); ?>" target="_blank" rel="noopener"><?php esc_html_e('View Dashboard', 'bytephase-connector'); ?> →</a></p>
                <?php endif; ?>
                <p><a href="<?php echo esc_url(Plugin::DOCS_URL); ?>" target="_blank" rel="noopener"><?php esc_html_e('Setup guide & troubleshooting →', 'bytephase-connector'); ?></a></p>
            </div>

            <h2><?php esc_html_e('Recent activity', 'bytephase-connector'); ?></h2>
            <?php $this->renderRecent($recent); ?>

            <?php if ($failed !== []) : ?>
                <h2><?php esc_html_e('Failed — needs retry', 'bytephase-connector'); ?></h2>
                <?php $this->renderFailed($failed); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleRetry(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'bytephase-connector'));
        }

        check_admin_referer(self::RETRY_ACTION);

        $id = (int) sanitize_text_field(wp_unslash((string) ($_POST['id'] ?? '')));

        if ($id > 0) {
            $this->pending->retryNow($id);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant resolves to the prefixed 'bytephase_connector_retry'.
            do_action(PendingSubmissions::CRON_HOOK);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderRecent(array $rows): void
    {
        if ($rows === []) {
            echo '<p>' . esc_html__('No submissions yet.', 'bytephase-connector') . '</p>';

            return;
        }
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Time', 'bytephase-connector'); ?></th>
                <th><?php esc_html_e('Status', 'bytephase-connector'); ?></th>
                <th><?php esc_html_e('Destination', 'bytephase-connector'); ?></th>
                <th><?php esc_html_e('Request ID', 'bytephase-connector'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php $requestId = (string) ($row['request_id'] ?? ''); ?>
                <tr>
                    <td><?php echo esc_html($this->formatTime((int) ($row['time'] ?? 0))); ?></td>
                    <td><?php $this->renderStatusBadge((string) ($row['outcome'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($row['destination'] ?? '—')); ?></td>
                    <td>
                        <?php if ($requestId !== '') : ?>
                            <code><?php echo esc_html($requestId); ?></code>
                            <button type="button" class="button-link bytephase-copy" data-copy="<?php echo esc_attr($requestId); ?>"><?php esc_html_e('Copy', 'bytephase-connector'); ?></button>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderFailed(array $rows): void
    {
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Form', 'bytephase-connector'); ?></th>
                <th><?php esc_html_e('Destination', 'bytephase-connector'); ?></th>
                <th><?php esc_html_e('Error', 'bytephase-connector'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) ($row['form_id'] ?? '—')); ?></td>
                    <td><?php echo esc_html((string) ($row['destination'] ?? '—')); ?></td>
                    <td><?php echo esc_html((string) ($row['last_error'] ?? '')); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::RETRY_ACTION); ?>">
                            <input type="hidden" name="id" value="<?php echo esc_attr((string) ($row['id'] ?? 0)); ?>">
                            <?php wp_nonce_field(self::RETRY_ACTION); ?>
                            <button type="submit" class="button button-small"><?php esc_html_e('Retry', 'bytephase-connector'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderStatusBadge(string $outcome): void
    {
        $ok = in_array($outcome, ApiResult::SUCCESS_OUTCOMES, true);

        printf(
            '<span class="%1$s">%2$s</span>',
            esc_attr($ok ? 'bytephase-status--ok' : 'bytephase-status--fail'),
            esc_html($ok ? '✔' : '✕'),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $recent
     */
    private function lastSubmission(array $recent): string
    {
        return $recent === [] ? __('Never', 'bytephase-connector') : $this->formatTime((int) ($recent[0]['time'] ?? 0));
    }

    private function formatTime(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) ?: '—';
    }
}
