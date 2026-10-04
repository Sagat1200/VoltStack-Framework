<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Effect;
use Quantum\Cache\FileStore;
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

    public function test_file_store_lookup_exposes_file_source_metadata(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-file-' . uniqid('', true);
        mkdir($directory, 0777, true);

        try {
            $store = new FileStore($directory, 'voltstack', $this->clockAt(1_700_000_000));
            $repository = new Repository($store);

            self::assertTrue($repository->put('page.home', ['html' => true], 60));

            $lookup = $repository->lookup('page.home');

            self::assertSame(HitState::Fresh, $lookup->state);
            self::assertSame(['html' => true], $lookup->value);
            self::assertNotNull($lookup->metadata);
            self::assertSame('file', $lookup->metadata?->sourceLevel);
        } finally {
            $this->deleteDirectory($directory);
        }
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

    public function test_pull_returns_value_and_removes_the_key(): void
    {
        $repository = new Repository(new MemoryStore($this->clockAt(1_700_000_000)));

        self::assertTrue($repository->put('once', 'token', 60));
        self::assertSame('token', $repository->pull('once'));
        self::assertFalse($repository->has('once'));
        self::assertSame('fallback', $repository->pull('once', 'fallback'));
    }

    public function test_many_returns_values_for_each_requested_key(): void
    {
        $repository = new Repository(new MemoryStore($this->clockAt(1_700_000_000)));

        self::assertTrue($repository->put('a', 1, 60));
        self::assertTrue($repository->put('b', null, 60));

        self::assertSame(
            ['a' => 1, 'b' => null, 'c' => 'missing'],
            $repository->many(['a', 'b', 'c'], 'missing'),
        );
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

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
