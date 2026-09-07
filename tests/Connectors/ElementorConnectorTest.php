<?php

declare(strict_types=1);

namespace BytePhase\Connector\Tests\Connectors;

use Brain\Monkey\Functions;
use BytePhase\Connector\Connectors\ElementorConnector;
use BytePhase\Connector\Core\ApiResult;
use BytePhase\Connector\Core\Dispatcher;
use BytePhase\Connector\Core\Submission;
use BytePhase\Connector\Settings\FormDestinations;
use BytePhase\Connector\Tests\TestCase;
use Mockery;

final class ElementorConnectorTest extends TestCase
{
    public function test_it_flattens_field_values_keyed_by_form_name(): void
    {
        $record = $this->record(
            fields: [
                'name' => ['value' => 'Jo'],
                'customer_phone' => ['value' => '999'],
            ],
            formName: 'repair-request',
        );

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function (Submission $submission): bool {
                return $submission->provider === 'elementor'
                    && $submission->formId === 'repair-request'
                    && $submission->data === ['name' => 'Jo', 'customer_phone' => '999'];
            }))
            ->andReturn(new ApiResult(ApiResult::OUTCOME_CREATED, 201));

        (new ElementorConnector($dispatcher, $this->destinations()))->handle($record, null);
    }

    public function test_it_ignores_an_empty_record(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        (new ElementorConnector($dispatcher, $this->destinations()))->handle($this->record([], ''), null);
    }

    /**
     * A stand-in for Elementor's Form_Record.
     *
     * @param  array<string, array{value: mixed}>  $fields
     */
    private function record(array $fields, string $formName): object
    {
        return new class($fields, $formName) {
            /**
             * @param  array<string, array{value: mixed}>  $fields
             */
            public function __construct(private array $fields, private string $formName)
            {
            }

            public function get(string $key): mixed
            {
                return $key === 'fields' ? $this->fields : null;
            }

            public function get_form_settings(string $key): mixed
            {
                return $key === 'form_name' ? $this->formName : null;
            }
        };
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
