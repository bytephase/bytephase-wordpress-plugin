<?php

/**
 * PHPStan bootstrap: the runtime constants the entry file defines, plus stubs
 * for the third-party form-plugin classes the connectors type against.
 * Never loaded at runtime.
 */

declare(strict_types=1);

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/../');

define('BYTEPHASE_CONNECTOR_VERSION', '1.0.0');
define('BYTEPHASE_CONNECTOR_FILE', __FILE__);
define('BYTEPHASE_CONNECTOR_PATH', __DIR__ . '/../');
define('BYTEPHASE_CONNECTOR_URL', 'https://example.test/wp-content/plugins/bytephase-connector/');

if (! class_exists('WPCF7_ContactForm')) {
    class WPCF7_ContactForm
    {
        public function id(): int
        {
            return 0;
        }

        public function title(): string
        {
            return '';
        }

        /**
         * @param  array<string, mixed>  $args
         * @return array<int, self>
         */
        public static function find(array $args = []): array
        {
            return [];
        }
    }
}

if (! class_exists('WPCF7_Submission')) {
    class WPCF7_Submission
    {
        public static function get_instance(): ?self
        {
            return null;
        }

        /**
         * @return array<string, mixed>|null
         */
        public function get_posted_data(): ?array
        {
            return null;
        }
    }
}
