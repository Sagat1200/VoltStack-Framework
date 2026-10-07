<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Normalization\ThrowableNormalizer;

final class QuantumThrowableNormalizerTest extends TestCase
{
    public function test_it_normalizes_throwable_without_args_and_with_project_relative_paths(): void
    {
        $throwable = $this->createThrowableWithTrace();
        $context = new ExceptionContext(
            scopeId: 'scope-1',
            correlationId: 'req-1',
            attributes: [
                'origin' => 'controller',
                'request_id' => 'req-1',
                'safe' => ['surface' => 'http'],
            ],
        );

        $normalizer = new ThrowableNormalizer(
            projectRoots: ['C:\\W4\\Packages\\VoltStack\\app-skeleton'],
        );

        $snapshot = $normalizer->normalize($throwable, $context);

        self::assertSame(\RuntimeException::class, $snapshot->className);
        self::assertSame('controller', $snapshot->origin);
        self::assertSame('req-1', $snapshot->safeMetadata['request_id']);
        self::assertStringEndsWith('tests/Unit/QuantumThrowableNormalizerTest.php', (string) $snapshot->frames[0]['file']);
        self::assertArrayNotHasKey('args', $snapshot->frames[0]);
        self::assertArrayNotHasKey('object', $snapshot->frames[0]);
    }

    public function test_it_redacts_secrets_and_truncates_message_and_causes(): void
    {
        $previous = new \RuntimeException(str_repeat('token=abc123 ', 50));
        $throwable = new \RuntimeException('password=super-secret ' . str_repeat('x', 200), previous: $previous);
        $context = new ExceptionContext(scopeId: 'scope-2');

        $normalizer = new ThrowableNormalizer(
            maxCauses: 2,
            maxFramesPerCause: 2,
            messageBytes: 64,
            snapshotBytes: 4096,
        );

        $snapshot = $normalizer->normalize($throwable, $context);

        self::assertStringNotContainsString('super-secret', $snapshot->internalMessage);
        self::assertStringContainsString('[REDACTED]', $snapshot->internalMessage);
        self::assertTrue($snapshot->truncated);
        self::assertNotEmpty($snapshot->causes);
        self::assertStringNotContainsString('abc123', implode(' ', $snapshot->causes));
    }

    private function createThrowableWithTrace(): \RuntimeException
    {
        try {
            return $this->traceSourceLevelOne();
        } catch (\RuntimeException $exception) {
            return $exception;
        }
    }

    private function traceSourceLevelOne(): \RuntimeException
    {
        return $this->traceSourceLevelTwo();
    }

    private function traceSourceLevelTwo(): \RuntimeException
    {
        throw new \RuntimeException('boom');
    }
}
