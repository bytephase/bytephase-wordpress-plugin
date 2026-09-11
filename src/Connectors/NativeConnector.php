<?php

declare(strict_types=1);

namespace BytePhase\Connector\Connectors;

use BytePhase\Connector\Core\CustomFieldCatalog;
use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Settings\CustomFields;

defined('ABSPATH') || exit;

/**
 * The optional zero-config native form: [bytephase_lead_form] / [bytephase_self_checkin].
 * Because BytePhase authors this markup, its field names are already canonical — no mapping
 * is ever needed and the destination is known from the shortcode.
 *
 * Deliberately no nonce: the form is public (logged-out visitors, no privileged action to
 * forge) and page caches serve HTML for longer than a nonce lives, which would break every
 * submission from a cached page. Spam is blunted with a honeypot and a minimum-fill-time
 * trap instead — both cache-safe.
 */
final class NativeConnector implements Connector
{
    private const ACTION = 'bytephase_native_submit';

    /** A human takes longer than this to fill the form; bots that fetch-and-post don't. */
    private const MIN_FILL_SECONDS = 3;

    /** Canonical fields the native form may submit, with their sanitiser. */
    private const FIELDS = [
        'name' => 'text',
        'email' => 'email',
        'mobile_number' => 'text',
        'mobile_country_code' => 'text',
        'phone_number' => 'text',
        'device_type' => 'text',
        'device_brand' => 'text',
        'device_model' => 'text',
        'serial_number' => 'text',
        'comment' => 'textarea',
    ];

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly CustomFields $customFields,
        private readonly CustomFieldCatalog $catalog,
    ) {
    }

    public function slug(): string
    {
        return 'native';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function register(): void
    {
        add_shortcode('bytephase_lead_form', [$this, 'renderLeadForm']);
        add_shortcode('bytephase_self_checkin', [$this, 'renderCheckinForm']);
        add_action('admin_post_nopriv_' . self::ACTION, [$this, 'handleSubmit']);
        add_action('admin_post_' . self::ACTION, [$this, 'handleSubmit']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
    }

    /**
     * Register the form stylesheet and enqueue it only on pages that use a shortcode,
     * so the front-end footprint stays at zero everywhere else.
     */
    public function registerAssets(): void
    {
        wp_register_style(
            'bytephase-native-form',
            BYTEPHASE_CONNECTOR_URL . 'assets/native-form.css',
            [],
            BYTEPHASE_CONNECTOR_VERSION,
        );

        if (! is_singular()) {
            return;
        }

        $post = get_post();

        if ($post !== null && (has_shortcode($post->post_content, 'bytephase_lead_form') || has_shortcode($post->post_content, 'bytephase_self_checkin'))) {
            wp_enqueue_style('bytephase-native-form');
        }
    }

    /**
     * @param  array<string, mixed>|string  $atts
     */
    public function renderLeadForm($atts = []): string
    {
        $atts = shortcode_atts([
            'title' => __('Send an enquiry', 'bytephase-connector'),
            'button' => __('Submit', 'bytephase-connector'),
        ], (array) $atts, 'bytephase_lead_form');

        return $this->renderForm('lead', (string) $atts['title'], (string) $atts['button'], false);
    }

    /**
     * @param  array<string, mixed>|string  $atts
     */
    public function renderCheckinForm($atts = []): string
    {
        $atts = shortcode_atts([
            'title' => __('Book a repair', 'bytephase-connector'),
            'button' => __('Submit', 'bytephase-connector'),
        ], (array) $atts, 'bytephase_self_checkin');

        return $this->renderForm('self_checkin', (string) $atts['title'], (string) $atts['button'], true);
    }

    public function handleSubmit(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- public, logged-out form with no privileged action; a nonce cannot survive page caching. See the class docblock.
        // Honeypot — real users leave it empty.
        if (! empty($_POST['bytephase_hp'])) {
            $this->redirect('ok');
        }

        // Time trap: the render timestamp must exist, not be from the future, and be at
        // least MIN_FILL_SECONDS old. Cached pages carry an old timestamp, which passes.
        // Spam gets the same "ok" as the honeypot so bots learn nothing.
        $renderedAt = (int) sanitize_text_field(wp_unslash((string) ($_POST['bytephase_ts'] ?? '')));

        if ($renderedAt <= 0 || $renderedAt > time() || (time() - $renderedAt) < self::MIN_FILL_SECONDS) {
            $this->redirect('ok');
        }

        $destination = sanitize_text_field(wp_unslash((string) ($_POST['bytephase_destination'] ?? '')));

        if (! in_array($destination, ['lead', 'self_checkin'], true)) {
            $this->redirect('error');
        }

        $data = $this->collect();

        // Mirror the destination's rules: name is required, plus at least one of email / mobile.
        $hasContact = ($data['email'] ?? '') !== '' || ($data['mobile_number'] ?? '') !== '';

        if (($data['name'] ?? '') === '' || ! $hasContact) {
            $this->redirect('invalid');
        }

        $custom = $this->collectCustomFields($destination);

        // A required custom field is required by BytePhase too, so catching it here saves
        // a round trip that could only ever come back 422.
        if ($custom['missing']) {
            $this->redirect('invalid');
        }

        if ($custom['values'] !== []) {
            $data['custom_fields'] = $custom['values'];
        }

        $result = $this->dispatcher->dispatch(
            new Submission('wordpress', 'native-' . $destination, $data, $destination),
        );

        $this->redirect($result->isSuccess() ? 'ok' : 'error');
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $data = [];

        foreach (self::FIELDS as $field => $type) {
            if (! isset($_POST[$field])) {
                continue;
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-type on the next statement.
            $raw = wp_unslash((string) $_POST[$field]);
            $value = $type === 'email'
                ? sanitize_email($raw)
                : ($type === 'textarea' ? sanitize_textarea_field($raw) : sanitize_text_field($raw));

            if ($value !== '') {
                $data[$field] = $value;
            }
        }

        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return $data;
    }

    /**
     * The custom fields this form publishes: the shop's definitions from BytePhase,
     * narrowed to the ones it chose to show. Empty whenever the feature is switched off,
     * nothing is defined, or BytePhase could not be reached.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customFieldDefinitions(string $destination): array
    {
        if (! $this->customFields->isEnabled($destination)) {
            return [];
        }

        return $this->customFields->published(
            $destination,
            $this->catalog->fields($this->customFields->formType($destination)),
        );
    }

    /**
     * @return array{values: array<int, array<string, mixed>>, missing: bool}
     */
    private function collectCustomFields(string $destination): array
    {
        $definitions = $this->customFieldDefinitions($destination);

        if ($definitions === []) {
            return ['values' => [], 'missing' => false];
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- public, logged-out form with no privileged action; see the class docblock.
        $submitted = isset($_POST['bytephase_cf']) && is_array($_POST['bytephase_cf'])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized per field below.
            ? wp_unslash($_POST['bytephase_cf'])
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $values = [];
        $missing = false;

        foreach ($definitions as $definition) {
            $name = (string) $definition['field_name'];
            $value = sanitize_text_field((string) ($submitted[$name] ?? ''));

            // Accept only what the shop defined: a dropdown that took free text would
            // put values into BytePhase that its own screens never offer.
            if (
                $definition['field_type'] === 'Dropdown'
                && $value !== ''
                && ! in_array($value, $definition['select_box_items'], true)
            ) {
                $value = '';
            }

            if ($definition['field_type'] === 'Number' && $value !== '' && ! is_numeric($value)) {
                $value = '';
            }

            if ($value === '') {
                $missing = $missing || ! empty($definition['is_field_required']);

                continue;
            }

            $values[] = [
                'label' => $name,
                'field_type' => $definition['field_type'],
                'field_value' => $value,
                'select_box_items' => $definition['select_box_items'] !== [] ? $definition['select_box_items'] : null,
            ];
        }

        return ['values' => $values, 'missing' => $missing];
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     */
    private function renderCustomFields(array $definitions): void
    {
        foreach ($definitions as $definition) {
            $name = (string) $definition['field_name'];
            $required = ! empty($definition['is_field_required']);
            $placeholder = (string) $definition['placeholder'];
            ?>
            <p><label><?php echo esc_html($name); ?><?php echo $required ? ' *' : ''; ?>
                <?php if ($definition['field_type'] === 'Dropdown') : ?>
                    <select name="bytephase_cf[<?php echo esc_attr($name); ?>]"<?php echo $required ? ' required' : ''; ?>>
                        <option value=""><?php echo esc_html($placeholder !== '' ? $placeholder : __('Select…', 'bytephase-connector')); ?></option>
                        <?php foreach ($definition['select_box_items'] as $item) : ?>
                            <option value="<?php echo esc_attr((string) $item); ?>"><?php echo esc_html((string) $item); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="<?php echo $definition['field_type'] === 'Number' ? 'number' : 'text'; ?>"
                        name="bytephase_cf[<?php echo esc_attr($name); ?>]"
                        <?php echo $placeholder !== '' ? 'placeholder="' . esc_attr($placeholder) . '"' : ''; ?>
                        <?php echo $required ? 'required' : ''; ?>>
                <?php endif; ?>
            </label></p>
            <?php
        }
    }

    private function renderForm(string $destination, string $heading, string $button, bool $withSerial = false): string
    {
        wp_enqueue_style('bytephase-native-form');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag from our own redirect; it only picks which notice the form shows.
        $status = isset($_GET['bytephase_status']) ? sanitize_key(wp_unslash((string) $_GET['bytephase_status'])) : '';

        ob_start();
        ?>
        <form class="bytephase-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php if ($heading !== '') : ?>
                <h3><?php echo esc_html($heading); ?></h3>
            <?php endif; ?>

            <?php if ($status === 'ok') : ?>
                <p class="bytephase-form__notice bytephase-form__notice--ok"><?php esc_html_e('Thanks — we have received your details.', 'bytephase-connector'); ?></p>
            <?php elseif ($status === 'invalid') : ?>
                <p class="bytephase-form__notice bytephase-form__notice--error"><?php esc_html_e('Please enter your name and at least a mobile number or email.', 'bytephase-connector'); ?></p>
            <?php elseif ($status === 'error') : ?>
                <p class="bytephase-form__notice bytephase-form__notice--error"><?php esc_html_e('Sorry, something went wrong. Please try again.', 'bytephase-connector'); ?></p>
            <?php endif; ?>

            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="bytephase_destination" value="<?php echo esc_attr($destination); ?>">
            <input type="hidden" name="bytephase_ts" value="<?php echo esc_attr((string) time()); ?>">
            <?php // Hidden with the [hidden] attribute as well as the stylesheet, so it stays hidden even if the CSS has not painted yet. ?>
            <p class="bytephase-form__hp" hidden>
                <label><?php esc_html_e('Leave this field empty', 'bytephase-connector'); ?>
                    <input type="text" name="bytephase_hp" value="" autocomplete="off" tabindex="-1">
                </label>
            </p>

            <p><label><?php esc_html_e('Name', 'bytephase-connector'); ?> *
                <input type="text" name="name" required></label></p>
            <p><label><?php esc_html_e('Mobile number', 'bytephase-connector'); ?>
                <input type="text" name="mobile_number"></label></p>
            <p><label><?php esc_html_e('Email', 'bytephase-connector'); ?>
                <input type="email" name="email"></label></p>
            <p class="bytephase-form__hint"><?php esc_html_e('Enter at least a mobile number or an email.', 'bytephase-connector'); ?></p>
            <p><label><?php esc_html_e('Device brand', 'bytephase-connector'); ?>
                <input type="text" name="device_brand"></label></p>
            <p><label><?php esc_html_e('Device model', 'bytephase-connector'); ?>
                <input type="text" name="device_model"></label></p>
            <?php if ($withSerial) : ?>
                <p><label><?php esc_html_e('Serial number', 'bytephase-connector'); ?>
                    <input type="text" name="serial_number"></label></p>
            <?php endif; ?>
            <?php $this->renderCustomFields($this->customFieldDefinitions($destination)); ?>

            <p><label><?php esc_html_e('Message', 'bytephase-connector'); ?>
                <textarea name="comment" rows="4"></textarea></label></p>

            <p><button type="submit"><?php echo esc_html($button); ?></button></p>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    private function redirect(string $status): void
    {
        $back = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('bytephase_status', $status, $back));
        exit;
    }
}
