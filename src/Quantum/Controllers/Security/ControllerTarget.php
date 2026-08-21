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
        public ?bool $exposed,
        public string $source,
        public string $signature,
    ) {}

    public static function fromDefinition(ControllerDefinition $definition, ?bool $exposed = null, string $source = 'route'): self
    {
        $action = $definition->action();
        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            $type = ControllerTargetType::ControllerMethod;
        } elseif (is_array($action) && isset($action[0], $action[1])) {
            $class = is_object($action[0]) ? get_class($action[0]) : (string) $action[0];
            $method = (string) $action[1];
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

    public function withExposed(?bool $exposed): self
    {
        return new self(
            type: $this->type,
            identifier: $this->identifier,
            method: $this->method,
            exposed: $exposed,
            source: $this->source,
            signature: $this->signature,
        );
    }
}
