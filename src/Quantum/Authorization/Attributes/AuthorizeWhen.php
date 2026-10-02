<?php

declare(strict_types=1);

namespace Quantum\Authorization\Attributes;

use Attribute;
use Quantum\Authorization\Authority\AttributeDefinition;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AuthorizeWhen
{
    /**
     * @param array<string, mixed> $constraints
     */
    public function __construct(
        public string $ability,
        public string|array|null $subject = null,
        public string $attribute = '',
        public string $type = AttributeDefinition::TYPE_ANY,
        public array $constraints = [],
        public ?string $description = null,
    ) {}

    /**
     * @return array{attribute:string,type:string,constraints:array<string,mixed>,description:string|null}
     */
    public function condition(): array
    {
        return [
            'attribute' => trim($this->attribute),
            'type' => trim($this->type) !== '' ? trim($this->type) : AttributeDefinition::TYPE_ANY,
            'constraints' => $this->constraints,
            'description' => $this->description,
        ];
    }
}
