<?php

declare(strict_types=1);

namespace Quantum\Controllers\Metadata;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Metadata\Contracts\MetadataEngineInterface;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\ControllerClassSubject;
use Quantum\Metadata\Subjects\ControllerMethodSubject;
use Quantum\Metadata\Subjects\RouteMatchSubject;

final readonly class ControllerMetadataResolver implements ControllerMetadataResolverInterface
{
    public function __construct(private MetadataEngineInterface $engine)
    {
    }

    public function resolve(ControllerExecution $execution): ControllerMetadata
    {
        $routeSubject = new RouteMatchSubject($execution->context()->match());
        $controllerClass = $execution->controller()->instance()::class;
        $method = $execution->controller()->method();
        $classSubject = new ControllerClassSubject($controllerClass, $routeSubject);
        $subject = new ControllerMethodSubject($controllerClass, $method, $classSubject);

        $bag = $this->engine->resolve(new MetadataRequest(
            subject: $subject,
        ));

        return new ControllerMetadata($bag);
    }
}
