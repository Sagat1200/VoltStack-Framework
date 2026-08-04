<?php

declare(strict_types=1);

namespace Quantum\Transport\ResponseBody;

use Quantum\Transport\Contracts\ResponseBodyInterface;
use Quantum\Transport\Enums\ResponseBodyType;

final readonly class TextResponseBody implements ResponseBodyInterface
{
    public function __construct(
        public string $content,
        public ?string $charset = 'UTF-8',
    ) {
    }

    public function type(): ResponseBodyType
    {
        return ResponseBodyType::Text;
    }

    public function isEmpty(): bool
    {
        return $this->content === '';
    }

    public function isReplayable(): bool
    {
        return true;
    }

    public function length(): ?int
    {
        return strlen($this->content);
    }
}

