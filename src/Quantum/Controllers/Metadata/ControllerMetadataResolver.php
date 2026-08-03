<?php

declare(strict_types=1);

namespace Quantum\Controllers\Metadata;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Metadata\Contracts\MetadataEngineInterface;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\RouteMatchSubject;

final readonly class ControllerMetadataResolver implements ControllerMetadataResolverInterface
{
    public function __construct(private MetadataEngineInterface $engine)
    {
    }

    public function resolve(ControllerExecution $execution): ControllerMetadata
    {
        $subject = new RouteMatchSubject($execution->context()->match());

        $bag = $this->engine->resolve(new MetadataRequest(
            subject: $subject,
        ));

        return new ControllerMetadata($bag);
    }
}

