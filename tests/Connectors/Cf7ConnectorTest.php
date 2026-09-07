<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Connectors;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use BytePhase\Connector\Connectors\Cf7Connector;
use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Settings\FormDestinations;
use BytePhase\Connector\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use WPCF7_ContactForm;
use WPCF7_Submission;

final class Cf7ConnectorTest extends TestCase
{
    private MockInterface $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = Mockery::mock(Dispatcher::class);
    }

    protected function tearDown(): void
    {
        WPCF7_Submission::$instance = null;
        parent::tearDown();
    }

    /**
     * Guards the data-loss bug: wpcf7_mail_sent fires only when wp_mail() succeeds, so on a
     * shop with broken SMTP every enquiry vanished. wpcf7_before_send_mail always fires.
     */
    public function test_it_hooks_before_send_mail_so_enquiries_survive_a_mail_failure(): void
    {
        Actions\expectAdded('wpcf7_before_send_mail')->once();
        Actions\expectAdded('wpcf7_mail_sent')->never();

        (new Cf7Connector($this->dispatcher, $this->destinations()))->register();
    }

    public function test_raw_field_names_are_forwarded_verbatim_with_the_form_id(): void
    {
        $this->fakeSubmission([
            '_wpcf7' => '123',
            '_wpcf7_version' => '5.9',
            'Your Phone Number' => '9999999999',
            'customer_name' => 'Asha',
            'devices' => ['Laptop', 'Phone'],
        ]);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function (Submission $submission): bool {
                return $submission->provider === 'cf7'
                    && $submission->formId === '123'
                    && $submission->destination === null
                    && $submission->data === [
                        'Your Phone Number' => '9999999999',
                        'customer_name' => 'Asha',
                        'devices' => ['Laptop', 'Phone'],
                    ];
            }))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED));

        (new Cf7Connector($this->dispatcher, $this->destinations()))->handle(new WPCF7_ContactForm(123));
    }

    public function test_nothing_is_dispatched_without_a_submission_instance(): void
    {
        WPCF7_Submission::$instance = null;

        $this->dispatcher->shouldNotReceive('dispatch');

        (new Cf7Connector($this->dispatcher, $this->destinations()))->handle(new WPCF7_ContactForm(123));
    }

    public function test_nothing_is_dispatched_when_only_internal_fields_remain(): void
    {
        $this->fakeSubmission(['_wpcf7' => '123', '_wpcf7_unit_tag' => 'tag']);

        $this->dispatcher->shouldNotReceive('dispatch');

        (new Cf7Connector($this->dispatcher, $this->destinations()))->handle(new WPCF7_ContactForm(123));
    }

    /**
     * @param  array<string, mixed>  $posted
     */
    private function fakeSubmission(array $posted): void
    {
        $instance = new WPCF7_Submission();
        $instance->posted = $posted;
        WPCF7_Submission::$instance = $instance;
    }

    public function test_it_pins_the_destination_the_shop_chose_for_this_form(): void
    {
        $this->fakeSubmission(['your-name' => 'Repair Booking', 'your-tel' => '9999999999']);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function (Submission $submission): bool {
                return $submission->formId === '123'
                    && $submission->destination === 'self_checkin';
            }))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED, 201));

        (new Cf7Connector($this->dispatcher, $this->destinations(['cf7:123' => 'self_checkin'])))
            ->handle(new WPCF7_ContactForm(123));
    }

    public function test_it_leaves_the_destination_to_bytephase_when_nothing_is_chosen(): void
    {
        $this->fakeSubmission(['your-name' => 'Unchosen', 'your-tel' => '9999999999']);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn (Submission $submission): bool => $submission->destination === null))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED, 201));

        (new Cf7Connector($this->dispatcher, $this->destinations()))->handle(new WPCF7_ContactForm(123));
    }

    public function test_it_sends_nothing_for_a_form_the_shop_switched_off(): void
    {
        $this->fakeSubmission(['your-name' => 'Newsletter', 'your-email' => 'x@y.test']);

        $this->dispatcher->shouldNotReceive('dispatch');

        (new Cf7Connector($this->dispatcher, $this->destinations(['cf7:123' => 'ignore'])))
            ->handle(new WPCF7_ContactForm(123));
    }

    /**
     * A real destinations map backed by a stubbed option, so these tests exercise the
     * choice the shop actually saved on BytePhase → Forms.
     *
     * @param  array<string, string>  $map
     */
    private function destinations(array $map = []): FormDestinations
    {
        Functions\when('get_option')->alias(
            static fn (string $name, $default = false) => $name === FormDestinations::OPTION ? $map : $default
        );

        return new FormDestinations();
    }

}
