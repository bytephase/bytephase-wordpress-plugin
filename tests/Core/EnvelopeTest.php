<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Core;

use BytePhase\Connector\Core\Envelope;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Tests\TestCase;

final class EnvelopeTest extends TestCase
{
    public function test_it_always_includes_provider_and_raw_data(): void
    {
        $body = Envelope::fromSubmission(new Submission('wordpress', '', ['customer_phone' => '999']));

        $this->assertSame('wordpress', $body['provider']);
        $this->assertSame(['customer_phone' => '999'], $body['data']);
    }

    public function test_it_omits_an_empty_form_id_and_null_destination(): void
    {
        $body = Envelope::fromSubmission(new Submission('wordpress', '', ['a' => 'b']));

        $this->assertArrayNotHasKey('form_id', $body);
        $this->assertArrayNotHasKey('destination', $body);
    }

    public function test_it_includes_form_id_and_destination_when_present(): void
    {
        $body = Envelope::fromSubmission(new Submission('cf7', 'contact-1', ['a' => 'b'], 'lead'));

        $this->assertSame('contact-1', $body['form_id']);
        $this->assertSame('lead', $body['destination']);
    }

    public function test_it_forwards_raw_field_names_untouched(): void
    {
        $raw = ['Your Phone' => '999', 'e-mail' => 'a@b.test'];

        $body = Envelope::fromSubmission(new Submission('elementor', 'f', $raw));

        $this->assertSame($raw, $body['data']);
    }
}
