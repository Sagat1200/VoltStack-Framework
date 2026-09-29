<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Entity
{
    /**
     * @param class-string|null                                                                  $repository
     * @param list<class-string<\Quantum\Database\ORM\Contracts\EntityLifecycleListenerInterface>> $lifecycleListeners
     */
    public function __construct(
        public ?string $repository = null,
        public array $lifecycleListeners = [],
    ) {
    }
}
