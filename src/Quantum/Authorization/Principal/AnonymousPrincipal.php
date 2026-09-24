<?php

declare(strict_types=1);

namespace Quantum\Authorization\Principal;

final class AnonymousPrincipal extends Principal
{
    public function __construct(?string $id = null)
    {
        parent::__construct(
            $id ?? ('anon-' . bin2hex(random_bytes(8))),
            PrincipalType::Anonymous,
            false,
            [],
        );
    }
}
