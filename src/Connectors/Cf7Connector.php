<?php

declare(strict_types=1);

namespace BytePhase\Connector\Connectors;

use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Settings\FormDestinations;
use WPCF7_ContactForm;
use WPCF7_Submission;

defined('ABSPATH') || exit;

/**
 * Contact Form 7. Fires after a form is successfully submitted, forwards the posted
 * fields verbatim (CF7 field names → BytePhase maps them, keyed by the CF7 form id).
 */
final class Cf7Connector implements Connector
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly FormDestinations $destinations,
    ) {
    }

    public function slug(): string
    {
        return 'cf7';
    }

    public function isAvailable(): bool
    {
        return defined('WPCF7_VERSION');
    }

    public function register(): void
    {
        // Not wpcf7_mail_sent: CF7 fires that only when wp_mail() succeeds, so a shop with
        // broken SMTP would lose every enquiry silently. wpcf7_before_send_mail runs after
        // validation and regardless of the mail outcome.
        add_action('wpcf7_before_send_mail', [$this, 'handle'], 10, 1);
    }

    public function handle(WPCF7_ContactForm $contactForm): void
    {
        $submission = WPCF7_Submission::get_instance();

        if (! $submission instanceof WPCF7_Submission) {
            return;
        }

        $posted = $submission->get_posted_data();

        if (! is_array($posted)) {
            return;
        }

        // Drop CF7's internal control fields (_wpcf7, _wpcf7_version, …).
        $data = array_filter(
            $posted,
            static fn ($key): bool => ! str_starts_with((string) $key, '_wpcf7'),
            ARRAY_FILTER_USE_KEY,
        );

        if ($data === []) {
            return;
        }

        $formId = (string) $contactForm->id();

        // The shop chose "do not send" for this form on BytePhase → Forms.
        if ($this->destinations->isIgnored('cf7', $formId)) {
            return;
        }

        $this->dispatcher->dispatch(new Submission(
            'cf7',
            $formId,
            $data,
            $this->destinations->resolve('cf7', $formId, $data),
        ));
    }
}
