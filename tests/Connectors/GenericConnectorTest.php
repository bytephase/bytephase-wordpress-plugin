<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Connectors;

use Brain\Monkey\Functions;
use BytePhase\Connector\Connectors\GenericConnector;
use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Settings\FormDestinations;
use BytePhase\Connector\Tests\TestCase;
use Mockery;

final class GenericConnectorTest extends TestCase
{
    public function test_it_dispatches_a_submission_with_raw_fields(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function (Submission $submission): bool {
                return $submission->provider === 'wordpress'
                    && $submission->formId === 'contact-us'
                    && $submission->destination === 'lead'
                    && $submission->data === ['customer_phone' => '9999999999'];
            }))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED, 201));

        (new GenericConnector($dispatcher, $this->destinations()))->handleAction([
            'form_id' => 'contact-us',
            'destination' => 'lead',
            'data' => ['customer_phone' => '9999999999'],
        ]);
    }

    public function test_it_defaults_provider_and_leaves_destination_null(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function (Submission $submission): bool {
                return $submission->provider === 'wordpress' && $submission->destination === null;
            }))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED, 201));

        (new GenericConnector($dispatcher, $this->destinations()))->handleAction([
            'form_id' => 'x',
            'data' => ['name' => 'Jo'],
        ]);
    }

    public function test_it_ignores_a_payload_with_no_data(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        (new GenericConnector($dispatcher, $this->destinations()))->handleAction(['form_id' => 'x', 'data' => []]);
    }

    public function test_the_filter_returns_the_result_shape(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED, 201, '01H-REQ'));

        $result = (new GenericConnector($dispatcher, $this->destinations()))->handleFilter([
            'form_id' => 'x',
            'data' => ['name' => 'Jo'],
        ]);

        $this->assertSame(ApiResult::OUTCOME_CREATED, $result['outcome']);
        $this->assertSame('01H-REQ', $result['request_id']);
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
