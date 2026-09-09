<?php

declare(strict_types=1);

namespace BytePhase\Connector\Settings;

use BytePhase\Connector\Core\ActivityLog;
use BytePhase\Connector\Core\CustomFieldCatalog;

defined('ABSPATH') || exit;

/**
 * The Forms screen: one choice per form — Lead, Self check-in, or don't send.
 *
 * A shop that runs an enquiry form and a repair-booking form needs both at once, and
 * only the site knows which is which. Contact Form 7 and Elementor post their own field
 * names with no destination, so until the shop says here, BytePhase has to guess from a
 * form id it has never seen.
 */
final class FormsPage
{
    public const PAGE_SLUG = 'bytephase-connector-forms';

    private const CAPABILITY = 'manage_options';
    private const SAVE_ACTION = 'bytephase_save_forms';
    private const REFRESH_ACTION = 'bytephase_refresh_custom_fields';

    /** Set by add_submenu_page(); identifies this screen inside admin_enqueue_scripts. */
    private string $hookSuffix = '';

    public function __construct(
        private readonly FormDestinations $destinations,
        private readonly ActivityLog $log,
        private readonly CustomFields $customFields,
        private readonly CustomFieldCatalog $catalog,
        private readonly Settings $settings,
    ) {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('admin_post_' . self::REFRESH_ACTION, [$this, 'handleRefresh']);
    }

    public function registerMenu(): void
    {
        $hookSuffix = add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Forms', 'bytephase-connector'),
            __('Forms', 'bytephase-connector'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
        );

        if (is_string($hookSuffix)) {
            $this->hookSuffix = $hookSuffix;
        }
    }

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
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $builder = $this->builderForms();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BytePhase Forms', 'bytephase-connector'); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag from our own post-redirect-get; it only picks which notice to show. ?>
            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Form settings saved.', 'bytephase-connector'); ?></p></div>
            <?php endif; ?>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag from our own post-redirect-get; it only picks which notice to show. ?>
            <?php if (isset($_GET['refreshed'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Custom fields refreshed from BytePhase.', 'bytephase-connector'); ?></p></div>
            <?php endif; ?>

            <div class="card bytephase-card">
                <h2><?php esc_html_e('What is this for?', 'bytephase-connector'); ?></h2>
                <p><?php esc_html_e('A form can become either a Lead (an enquiry to follow up) or a Self check-in (a repair booking). Choose once per form, and you can run both kinds side by side — an enquiry form on one page and a booking form on another.', 'bytephase-connector'); ?></p>
                <p><?php esc_html_e('You still map the field names once in BytePhase. This page only decides which record each form creates.', 'bytephase-connector'); ?></p>
            </div>

            <h2><?php esc_html_e('Built-in forms', 'bytephase-connector'); ?></h2>
            <p class="description"><?php esc_html_e('These are fixed — the shortcode you paste decides the destination. Use both if you want both kinds.', 'bytephase-connector'); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Shortcode', 'bytephase-connector'); ?></th>
                    <th><?php esc_html_e('Creates', 'bytephase-connector'); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code>[bytephase_lead_form]</code></td>
                        <td><?php echo esc_html($this->destinationLabel(FormDestinations::LEAD)); ?></td>
                    </tr>
                    <tr>
                        <td><code>[bytephase_self_checkin]</code></td>
                        <td><?php echo esc_html($this->destinationLabel(FormDestinations::SELF_CHECKIN)); ?></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::SAVE_ACTION); ?>

                <?php $this->renderCustomFieldsSection(); ?>

                <h2><?php esc_html_e('Your other forms', 'bytephase-connector'); ?></h2>
                <?php if ($builder === []) : ?>
                    <p><?php esc_html_e('No Contact Form 7, Elementor or theme forms found yet. Submit one once and it will appear here.', 'bytephase-connector'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr>
                            <th><?php esc_html_e('Form', 'bytephase-connector'); ?></th>
                            <th><?php esc_html_e('Source', 'bytephase-connector'); ?></th>
                            <th><?php esc_html_e('Creates', 'bytephase-connector'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($builder as $form) : ?>
                            <?php $key = FormDestinations::key($form['provider'], $form['id']); ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($form['label']); ?></strong><br>
                                    <code><?php echo esc_html($form['id']); ?></code>
                                </td>
                                <td><?php echo esc_html($this->providerLabel($form['provider'])); ?></td>
                                <td>
                                    <label class="screen-reader-text" for="bytephase-form-<?php echo esc_attr(sanitize_key($key)); ?>">
                                        <?php echo esc_html($form['label']); ?>
                                    </label>
                                    <select id="bytephase-form-<?php echo esc_attr(sanitize_key($key)); ?>" name="destinations[<?php echo esc_attr($key); ?>]">
                                        <option value=""<?php selected($form['choice'], null); ?>>
                                            <?php esc_html_e('Let BytePhase decide', 'bytephase-connector'); ?>
                                        </option>
                                        <option value="<?php echo esc_attr(FormDestinations::LEAD); ?>"<?php selected($form['choice'], FormDestinations::LEAD); ?>>
                                            <?php echo esc_html($this->destinationLabel(FormDestinations::LEAD)); ?>
                                        </option>
                                        <option value="<?php echo esc_attr(FormDestinations::SELF_CHECKIN); ?>"<?php selected($form['choice'], FormDestinations::SELF_CHECKIN); ?>>
                                            <?php echo esc_html($this->destinationLabel(FormDestinations::SELF_CHECKIN)); ?>
                                        </option>
                                        <option value="<?php echo esc_attr(FormDestinations::IGNORE); ?>"<?php selected($form['choice'], FormDestinations::IGNORE); ?>>
                                            <?php esc_html_e('Do not send to BytePhase', 'bytephase-connector'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php endif; ?>

                <?php submit_button(__('Save form settings', 'bytephase-connector')); ?>
            </form>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'bytephase-connector'));
        }

        check_admin_referer(self::SAVE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above runs before anything is read.
        $submitted = isset($_POST['destinations']) && is_array($_POST['destinations'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key and value is sanitized in the loop below.
            ? wp_unslash($_POST['destinations'])
            : [];

        $map = [];

        foreach ($submitted as $key => $value) {
            $key = sanitize_text_field((string) $key);
            $value = sanitize_text_field((string) $value);

            // An empty value means "let BytePhase decide" — store nothing at all.
            if ($key !== '' && $value !== '') {
                $map[$key] = $value;
            }
        }

        $this->destinations->save($map);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above runs before anything is read.
        $customFields = isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside CustomFields::save().
            ? wp_unslash($_POST['custom_fields'])
            : [];

        $this->customFields->save($customFields);

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    /**
     * Drop the cached definitions so the next page load asks BytePhase again. Exists
     * because a shop that has just added a field should not have to wait out the cache.
     */
    public function handleRefresh(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'bytephase-connector'));
        }

        check_admin_referer(self::REFRESH_ACTION);

        $this->catalog->forget();

        wp_safe_redirect(add_query_arg('refreshed', '1', admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    /**
     * Custom fields are defined in BytePhase and only displayed here, so this section
     * decides three things and nothing else: whether they appear, which form type they
     * come from, and which of them are published on the public form.
     */
    private function renderCustomFieldsSection(): void
    {
        $shortcodes = [
            FormDestinations::LEAD => '[bytephase_lead_form]',
            FormDestinations::SELF_CHECKIN => '[bytephase_self_checkin]',
        ];

        $formTypes = $this->catalog->formTypes();
        ?>
        <h2><?php esc_html_e('Custom fields', 'bytephase-connector'); ?></h2>
        <p class="description">
            <?php esc_html_e('Show the custom fields you have already created in BytePhase on the built-in forms. Labels, types, options and which are required are managed in BytePhase — this page only decides whether they appear on your website.', 'bytephase-connector'); ?>
        </p>

        <?php foreach ($shortcodes as $destination => $shortcode) : ?>
            <?php
            $config = $this->customFields->config($destination);
            $definitions = $this->catalog->fields($config['form_type']);
            $syncedAt = $this->catalog->syncedAt($config['form_type']);
            $choices = $formTypes;

            if (! in_array($config['form_type'], $choices, true)) {
                $choices[] = $config['form_type'];
            }

            sort($choices);
            ?>
            <div class="card bytephase-card">
                <h3>
                    <?php echo esc_html($this->destinationLabel((string) $destination)); ?>
                    <code><?php echo esc_html($shortcode); ?></code>
                </h3>

                <?php // Marks this screen as saved, so unticking every field means "none", not "all". ?>
                <input type="hidden" name="custom_fields[<?php echo esc_attr((string) $destination); ?>][configured]" value="1">

                <p>
                    <label>
                        <input type="checkbox"
                            name="custom_fields[<?php echo esc_attr((string) $destination); ?>][enabled]"
                            value="1"<?php checked($config['enabled']); ?>>
                        <?php esc_html_e('Show custom fields on this form', 'bytephase-connector'); ?>
                    </label>
                </p>

                <p>
                    <label>
                        <?php esc_html_e('Pull definitions from', 'bytephase-connector'); ?>
                        <select name="custom_fields[<?php echo esc_attr((string) $destination); ?>][form_type]">
                            <?php foreach ($choices as $choice) : ?>
                                <option value="<?php echo esc_attr($choice); ?>"<?php selected($config['form_type'], $choice); ?>>
                                    <?php echo esc_html($choice); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>

                <?php if ($definitions === []) : ?>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: the custom field form type, e.g. "Lead". */
                            esc_html__('No custom fields for "%s" yet. Create them in BytePhase under Settings → Custom fields, choose that form type, then refresh.', 'bytephase-connector'),
                            esc_html($config['form_type']),
                        );
                        ?>
                        <?php if ($this->settings->dashboardUrl() !== '') : ?>
                            <a href="<?php echo esc_url($this->settings->dashboardUrl()); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Open BytePhase', 'bytephase-connector'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p><?php esc_html_e('Fields found in BytePhase — tick the ones to publish on this form:', 'bytephase-connector'); ?></p>
                    <ul>
                        <?php foreach ($definitions as $definition) : ?>
                            <?php $required = ! empty($definition['is_field_required']); ?>
                            <li>
                                <label>
                                    <input type="checkbox"
                                        name="custom_fields[<?php echo esc_attr((string) $destination); ?>][fields][]"
                                        value="<?php echo esc_attr((string) $definition['field_name']); ?>"
                                        <?php checked($required || (! $config['configured'] && $config['fields'] === []) || in_array((string) $definition['field_name'], $config['fields'], true)); ?>
                                        <?php disabled($required); ?>>
                                    <?php echo esc_html((string) $definition['field_name']); ?>
                                    <span class="description">
                                        <?php echo esc_html((string) $definition['field_type']); ?><?php echo $required ? esc_html__(' — required', 'bytephase-connector') : ''; ?>
                                    </span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($this->hasRequiredField($definitions)) : ?>
                        <p class="description"><?php esc_html_e('Required fields are always shown — BytePhase rejects a submission without them.', 'bytephase-connector'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <p class="description">
                    <?php if ($syncedAt !== null) : ?>
                        <?php
                        printf(
                            /* translators: %s: human readable time difference, e.g. "2 hours". */
                            esc_html__('Last checked %s ago.', 'bytephase-connector'),
                            esc_html(human_time_diff($syncedAt)),
                        );
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Not checked yet — connect BytePhase on the Connection screen first.', 'bytephase-connector'); ?>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::REFRESH_ACTION), self::REFRESH_ACTION)); ?>">
                        <?php esc_html_e('Refresh', 'bytephase-connector'); ?>
                    </a>
                </p>
            </div>
        <?php endforeach; ?>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     */
    private function hasRequiredField(array $definitions): bool
    {
        foreach ($definitions as $definition) {
            if (! empty($definition['is_field_required'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every form the shop can choose a destination for: the ones Contact Form 7 can list
     * up front, plus any form that has already sent something (the only way an Elementor
     * or theme form can be discovered), plus anything already chosen so a saved setting
     * never becomes uneditable when the activity log rolls over.
     *
     * @return array<int, array{provider: string, id: string, label: string, choice: string|null}>
     */
    private function builderForms(): array
    {
        $forms = [];

        foreach ($this->cf7Forms() as $form) {
            $forms[FormDestinations::key('cf7', $form['id'])] = $form;
        }

        foreach ($this->seenForms() as $key => $form) {
            $forms[$key] ??= $form;
        }

        $rows = [];

        foreach ($forms as $form) {
            $rows[] = $form + ['choice' => $this->destinations->choice($form['provider'], $form['id'])];
        }

        return $rows;
    }

    /**
     * Contact Form 7 is the one builder that can list its forms before any of them has
     * been submitted, so the shop can set both destinations up front.
     *
     * @return array<int, array{provider: string, id: string, label: string}>
     */
    private function cf7Forms(): array
    {
        if (! class_exists('WPCF7_ContactForm')) {
            return [];
        }

        $forms = [];

        /** @var array<int, object> $found */
        $found = \WPCF7_ContactForm::find(['posts_per_page' => -1]);

        foreach ($found as $form) {
            if (! is_object($form) || ! method_exists($form, 'id') || ! method_exists($form, 'title')) {
                continue;
            }

            $label = (string) $form->title();
            $forms[] = [
                'provider' => 'cf7',
                // CF7 ids are integers; every key here is a string, so cast once at the source.
                'id' => (string) $form->id(),
                'label' => $label !== '' ? $label : (string) $form->id(),
            ];
        }

        return $forms;
    }

    /**
     * @return array<string, array{provider: string, id: string, label: string}>
     */
    private function seenForms(): array
    {
        $forms = [];

        foreach ($this->log->all() as $row) {
            $provider = (string) ($row['provider'] ?? '');
            $id = (string) ($row['form_id'] ?? '');

            if ($provider === '' || $id === '' || $this->isNativeForm($id)) {
                continue;
            }

            $forms[FormDestinations::key($provider, $id)] = ['provider' => $provider, 'id' => $id, 'label' => $id];
        }

        foreach (array_keys($this->destinations->all()) as $key) {
            if (isset($forms[$key]) || ! str_contains($key, ':')) {
                continue;
            }

            [$provider, $id] = explode(':', $key, 2);

            if ($id === '' || $this->isNativeForm($id)) {
                continue;
            }

            $forms[$key] = ['provider' => $provider, 'id' => $id, 'label' => $id];
        }

        return $forms;
    }

    /**
     * The shortcodes carry their own destination, so they are listed read-only above and
     * must never appear as something to choose.
     */
    private function isNativeForm(string $formId): bool
    {
        return str_starts_with($formId, 'native-');
    }

    private function destinationLabel(string $destination): string
    {
        return $destination === FormDestinations::LEAD
            ? __('Lead (an enquiry)', 'bytephase-connector')
            : __('Self check-in (a repair booking)', 'bytephase-connector');
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'cf7' => __('Contact Form 7', 'bytephase-connector'),
            'elementor' => __('Elementor', 'bytephase-connector'),
            default => __('Website form', 'bytephase-connector'),
        };
    }
}
