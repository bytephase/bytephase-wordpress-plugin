<?php

/**
 * Global-namespace fakes of the Contact Form 7 classes Cf7Connector types
 * against. Loaded from tests/bootstrap.php; never shipped.
 */

declare(strict_types=1);

if (! class_exists('WPCF7_ContactForm')) {
    class WPCF7_ContactForm
    {
        public function __construct(private readonly int $formId)
        {
        }

        public function id(): int
        {
            return $this->formId;
        }

        public function title(): string
        {
            return 'Contact form ' . $this->formId;
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
        public static ?WPCF7_Submission $instance = null;

        /** @var array<string, mixed>|null */
        public ?array $posted = null;

        public static function get_instance(): ?self
        {
            return self::$instance;
        }

        /**
         * @return array<string, mixed>|null
         */
        public function get_posted_data(): ?array
        {
            return $this->posted;
        }
    }
}
