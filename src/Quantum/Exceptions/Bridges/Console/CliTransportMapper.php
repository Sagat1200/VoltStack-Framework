<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges\Console;

use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\TransportPlan;

final class CliTransportMapper implements TransportMapperInterface
{
    public function map(ExceptionDescriptor $descriptor, TransportContext $context): TransportPlan
    {
        if ($context->kind !== 'cli') {
            throw new \InvalidArgumentException('CliTransportMapper only supports the cli transport kind.');
        }

        return new TransportPlan(
            target: $this->targetFor($context),
            exitCode: $this->exitCodeFor($descriptor),
        );
    }

    private function targetFor(TransportContext $context): string
    {
        $profile = strtolower(trim((string) $context->routeProfile));

        if (in_array($profile, ['json', 'structured'], true)) {
            return 'cli.json';
        }

        foreach ($context->accept as $accept) {
            if (! is_string($accept)) {
                continue;
            }

            $normalized = strtolower($accept);

            if (str_contains($normalized, 'application/json') || str_contains($normalized, '+json')) {
                return 'cli.json';
            }
        }

        return 'cli.text';
    }

    private function exitCodeFor(ExceptionDescriptor $descriptor): int
    {
        return match ($descriptor->semantic->code) {
            'validation.failed' => 2,
            'authentication.required', 'authentication.failed', 'authorization.denied', 'authorization.challenge' => 3,
            'resource.not_found' => 4,
            'resource.conflict' => 5,
            'dependency.unavailable', 'operation.indeterminate' => 6,
            'configuration.invalid' => 78,
            default => 1,
        };
    }
}
