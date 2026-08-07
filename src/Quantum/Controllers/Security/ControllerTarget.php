<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security;

use Quantum\Controllers\ControllerDefinition;

final readonly class ControllerTarget
{
    public function __construct(
        public ControllerTargetType $type,
        public string $identifier,
        public ?string $method,
        public bool $exposed,
        public string $source,
        public string $signature,
    ) {}

    public static function fromDefinition(ControllerDefinition $definition, bool $exposed = true, string $source = 'route'): self
    {
        $action = $definition->action();
        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            $type = ControllerTargetType::ControllerMethod;
        } elseif (is_string($action)) {
            $class = $action;
            $method = '__invoke';
            $type = ControllerTargetType::InvokableController;
        } elseif ($action instanceof \Closure) {
            $class = 'Closure';
            $method = '__invoke';
            $type = ControllerTargetType::Closure;
        } else {
            $class = is_object($action) ? get_class($action) : (string) $action;
            $method = '__invoke';
            $type = ControllerTargetType::Action;
        }

        $identifier = $class;
        $signature = $class . ($method ? '::' . $method : '');

        return new self(
            type: $type,
            identifier: $identifier,
            method: $method,
            exposed: $exposed,
            source: $source,
            signature: $signature,
        );
    }
}
