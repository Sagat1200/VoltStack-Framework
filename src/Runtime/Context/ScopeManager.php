<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Context;

use Quantum\Container\ScopeKind;
use Quantum\Http\Request;
use VoltStack\Framework\Application;

final class ScopeManager
{
    private ?RuntimeContext $context = null;
    private ?string $contextSlotId = null;
    private ?string $executionSlotId = null;

    public function __construct(private readonly Application $app) {}

    public function begin(Request $request): RuntimeContext
    {
        return $this->beginRequest($request);
    }

    public function beginRequest(Request $request): RuntimeContext
    {
        return $this->beginUnitScope(
            ScopeKind::Request,
            $request,
            [],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function beginCommand(?string $commandName = null, ?Request $request = null, array $metadata = []): RuntimeContext
    {
        $commandName = $this->normalizeUnitName($commandName, 'command');

        return $this->beginUnitScope(
            ScopeKind::Command,
            $request ?? $this->syntheticUnitRequest('/_cli/command', $commandName, 'POST'),
            [
                'runtime.channel' => 'cli',
                'runtime.unit_name' => $commandName,
                ...$metadata,
            ],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function beginJob(?string $jobName = null, ?Request $request = null, array $metadata = []): RuntimeContext
    {
        $jobName = $this->normalizeUnitName($jobName, 'job');

        return $this->beginUnitScope(
            ScopeKind::Job,
            $request ?? $this->syntheticUnitRequest('/_runtime/job', $jobName, 'POST'),
            [
                'runtime.channel' => 'worker',
                'runtime.unit_name' => $jobName,
                ...$metadata,
            ],
        );
    }

    public function runInCommand(callable $callback, ?string $commandName = null, ?Request $request = null): mixed
    {
        $this->beginCommand($commandName, $request);

        try {
            return $callback();
        } finally {
            $this->end();
        }
    }

    public function runInJob(callable $callback, ?string $jobName = null, ?Request $request = null): mixed
    {
        $this->beginJob($jobName, $request);

        try {
            return $callback();
        } finally {
            $this->end();
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function beginUnitScope(ScopeKind $scopeKind, Request $request, array $metadata): RuntimeContext
    {
        RuntimeContext::deactivate($this->contextSlotId);
        $this->contextSlotId = null;
        $this->app->deactivateExecutionState($this->executionSlotId);
        $this->executionSlotId = $this->app->activateExecutionState();

        while ($this->app->hasActiveScope()) {
            $this->app->leaveScope();
        }

        $this->app->flushScope();
        match ($scopeKind) {
            ScopeKind::Request => $this->app->enterRequestScope(),
            ScopeKind::Command => $this->app->enterCommandScope(),
            ScopeKind::Job => $this->app->enterJobScope(),
            default => throw new \RuntimeException(sprintf('Unsupported runtime unit scope [%s].', $scopeKind->value)),
        };

        $context = new RuntimeContext(
            bin2hex(random_bytes(16)),
            $request,
            microtime(true),
            [
                'runtime.scope_kind' => $scopeKind->value,
                ...$metadata,
            ],
        );

        $this->contextSlotId = RuntimeContext::activate($context);
        $this->context = $context;

        $this->app->scopedInstance(Request::class, $request);
        $this->app->scopedInstance(RuntimeContext::class, $context);
        $this->app->fireScopeStart($context);

        return $context;
    }

    public function end(): void
    {
        $this->app->fireScopeEnd($this->context);
        $this->context = null;
        RuntimeContext::deactivate($this->contextSlotId);
        $this->contextSlotId = null;
        $this->app->leaveScope();
        $this->app->deactivateExecutionState($this->executionSlotId);
        $this->executionSlotId = null;
    }

    public function current(): ?RuntimeContext
    {
        return $this->context ?? RuntimeContext::current();
    }

    private function syntheticUnitRequest(string $prefix, string $unitName, string $method): Request
    {
        return Request::create($prefix . '/' . $this->slugify($unitName), $method);
    }

    private function normalizeUnitName(?string $unitName, string $default): string
    {
        $unitName = is_string($unitName) ? trim($unitName) : '';

        return $unitName !== '' ? $unitName : $default;
    }

    private function slugify(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($value));
        $slug = is_string($slug) ? trim($slug, '-') : '';

        return $slug !== '' ? $slug : 'unit';
    }
}
