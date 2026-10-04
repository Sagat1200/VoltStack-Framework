<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Effect;
use Quantum\Cache\HitState;
use Quantum\Cache\MemoryStore;
use Quantum\Cache\NullStore;
use Quantum\Cache\Repository;

final class CacheRepositoryBaselineTest extends TestCase
{
    public function test_memory_store_preserves_null_and_lookup_reports_fresh_hit(): void
    {
        $store = new MemoryStore($this->clockAt(1_700_000_000));
        $repository = new Repository($store);

        self::assertTrue($repository->put('optional', null));
        self::assertTrue($repository->has('optional'));
        self::assertNull($repository->get('optional', 'fallback'));

        $lookup = $repository->lookup('optional');

        self::assertSame(HitState::Fresh, $lookup->state);
        self::assertNull($lookup->value);
        self::assertNotNull($lookup->metadata);
        self::assertSame('memory', $lookup->metadata?->sourceLevel);
    }

    public function test_memory_store_expires_entries_from_ttl(): void
    {
        $clock = $this->clockAt(1_700_000_000);
        $store = new MemoryStore($clock);
        $repository = new Repository($store);

        self::assertTrue($repository->put('greeting', 'hello', 10));
        self::assertSame('hello', $repository->get('greeting'));
        self::assertSame(HitState::Fresh, $repository->lookup('greeting')->state);

        $clock->advanceSeconds(11);

        self::assertFalse($repository->has('greeting'));
        self::assertSame('fallback', $repository->get('greeting', 'fallback'));
        self::assertSame(HitState::Miss, $repository->lookup('greeting')->state);
    }

    public function test_null_store_discards_writes_but_stays_operational(): void
    {
        $repository = new Repository(new NullStore());

        self::assertTrue($repository->put('banner', ['active' => true], 60));
        self::assertFalse($repository->has('banner'));
        self::assertSame('fallback', $repository->get('banner', 'fallback'));
        self::assertSame(HitState::Miss, $repository->lookup('banner')->state);
    }

    public function test_repository_receipts_report_applied_effect_for_successful_writes(): void
    {
        $repository = new Repository(new MemoryStore($this->clockAt(1_700_000_000)));

        $put = $repository->putReceipt('foo', 'bar', 60);
        $forever = $repository->foreverReceipt('baz', 'qux');
        $forget = $repository->forgetReceipt('baz');

        self::assertSame(Effect::Applied, $put->effect);
        self::assertSame(Effect::Applied, $forever->effect);
        self::assertSame(Effect::Applied, $forget->effect);
        self::assertNotSame('', $put->operationId);
    }

    private function clockAt(int $unixSeconds): object
    {
        return new class($unixSeconds) implements ClockInterface {
            public function __construct(
                private int $unixSeconds,
            ) {}

            public function nowUnixSeconds(): int
            {
                return $this->unixSeconds;
            }

            public function nowUnixMilliseconds(): int
            {
                return $this->unixSeconds * 1000;
            }

            public function monotonicNanoseconds(): int
            {
                return $this->unixSeconds * 1_000_000_000;
            }

            public function advanceSeconds(int $seconds): void
            {
                $this->unixSeconds += $seconds;
            }
        };
    }
}
