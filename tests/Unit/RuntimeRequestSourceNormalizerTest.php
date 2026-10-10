<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Request;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeRequestSourceNormalizer;

final class RuntimeRequestSourceNormalizerTest extends TestCase
{
    public function test_normalize_returns_empty_iterator_for_null_source(): void
    {
        $iterator = RuntimeRequestSourceNormalizer::normalize(null, 'RoadRunner');

        self::assertSame([], iterator_to_array($iterator, false));
    }

    public function test_normalize_invokes_callable_source_and_yields_requests_from_array(): void
    {
        $requests = [
            Request::create('/a', 'GET'),
            Request::create('/b', 'POST'),
        ];

        $iterator = RuntimeRequestSourceNormalizer::normalize(static fn () => $requests, 'FrankenPHP');

        self::assertSame($requests, iterator_to_array($iterator, false));
    }

    public function test_normalize_invokes_callable_source_and_yields_requests_from_generator(): void
    {
        $requests = [
            Request::create('/a', 'GET'),
            Request::create('/b', 'POST'),
        ];

        $callable = static function () use ($requests): \Generator {
            foreach ($requests as $request) {
                yield $request;
            }
        };

        $iterator = RuntimeRequestSourceNormalizer::normalize($callable, 'FrankenPHP');

        self::assertSame($requests, iterator_to_array($iterator, false));
    }

    public function test_normalize_yields_each_request_from_array_source(): void
    {
        $requests = [
            Request::create('/ping', 'GET'),
            Request::create('/pong', 'HEAD'),
        ];

        $iterator = RuntimeRequestSourceNormalizer::normalize($requests, 'OpenSwoole');

        self::assertSame($requests, iterator_to_array($iterator, false));
    }

    public function test_normalize_yields_each_request_from_traversable_source(): void
    {
        $requests = [
            Request::create('/ping', 'GET'),
            Request::create('/pong', 'HEAD'),
        ];

        $iterator = RuntimeRequestSourceNormalizer::normalize(new \ArrayObject($requests), 'OpenSwoole');

        self::assertSame($requests, iterator_to_array($iterator, false));
    }

    public function test_normalize_yields_each_request_from_generator_source(): void
    {
        $requests = [
            Request::create('/ping', 'GET'),
            Request::create('/pong', 'HEAD'),
        ];

        $generator = static function () use ($requests): \Generator {
            foreach ($requests as $request) {
                yield $request;
            }
        };

        $iterator = RuntimeRequestSourceNormalizer::normalize($generator(), 'OpenSwoole');

        self::assertSame($requests, iterator_to_array($iterator, false));
    }

    public function test_normalize_rejects_non_iterable_non_callable_sources(): void
    {
        $cases = [
            ['RoadRunner', 42],
            ['OpenSwoole', '/not-valid'],
            ['FrankenPHP', (object) ['foo' => 'bar']],
        ];

        foreach ($cases as [$label, $source]) {
            try {
                RuntimeRequestSourceNormalizer::normalize($source, $label);
                self::fail(sprintf('Expected exception for label %s and source %s', $label, get_debug_type($source)));
            } catch (RuntimeAdapterException $exception) {
                self::assertSame(sprintf('%s runtime request source must be iterable or callable.', $label), $exception->getMessage());
            }
        }
    }

    public function test_normalize_rejects_items_that_are_not_request_instances(): void
    {
        $cases = [
            ['RoadRunner', ['/ping']],
            [
                'OpenSwoole',
                (static function (): \Generator {
                    yield Request::create('/ok', 'GET');
                    yield 'not a request';
                })(),
            ],
            ['FrankenPHP', new \ArrayObject([123])],
        ];

        foreach ($cases as [$label, $source]) {
            $iterator = RuntimeRequestSourceNormalizer::normalize($source, $label);

            try {
                iterator_to_array($iterator, false);
                self::fail(sprintf('Expected exception for label %s and invalid items', $label));
            } catch (RuntimeAdapterException $exception) {
                self::assertSame(sprintf('%s runtime request source must yield Request instances.', $label), $exception->getMessage());
            }
        }
    }

    public function test_normalize_preserves_driver_label_in_error_messages(): void
    {
        foreach (['FrankenPHP', 'RoadRunner', 'OpenSwoole'] as $label) {
            try {
                RuntimeRequestSourceNormalizer::normalize(42, $label);
                self::fail(sprintf('Expected exception for label %s', $label));
            } catch (RuntimeAdapterException $exception) {
                self::assertStringContainsString(sprintf('%s runtime request source must be iterable or callable.', $label), $exception->getMessage());
            }
        }
    }
}
