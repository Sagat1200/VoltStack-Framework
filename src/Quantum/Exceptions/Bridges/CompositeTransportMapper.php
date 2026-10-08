<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges;

use Quantum\Exceptions\Bridges\Console\CliTransportMapper;
use Quantum\Exceptions\Bridges\Http\HttpTransportMapper;
use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\TransportPlan;

final class CompositeTransportMapper implements TransportMapperInterface
{
    public function __construct(
        private readonly ?HttpTransportMapper $http = null,
        private readonly ?CliTransportMapper $cli = null,
    ) {
    }

    public function map(ExceptionDescriptor $descriptor, TransportContext $context): TransportPlan
    {
        return match ($context->kind) {
            'http' => ($this->http ?? new HttpTransportMapper())->map($descriptor, $context),
            'cli' => ($this->cli ?? new CliTransportMapper())->map($descriptor, $context),
            default => throw new \InvalidArgumentException(sprintf(
                'CompositeTransportMapper does not support the transport kind [%s].',
                $context->kind,
            )),
        };
    }
}
