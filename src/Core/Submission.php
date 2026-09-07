<?php

declare(strict_types=1);

namespace BytePhase\Connector\Core;

defined('ABSPATH') || exit;

/**
 * Immutable value object describing one submission captured from WordPress.
 *
 * Field names in $data are the RAW names the form author chose — the plugin never
 * renames or maps them. BytePhase maps them to canonical fields, keyed by $formId.
 */
final class Submission
{
    /**
     * @param  array<string, mixed>  $data  Raw submitted fields, verbatim.
     * @param  array<string, mixed>  $meta  Operational context (ip, page) — never business data.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $formId,
        public readonly array $data,
        public readonly ?string $destination = null,
        public readonly array $meta = [],
    ) {
    }
}
