<?php

declare(strict_types=1);

namespace Quantum\Database\Query;

interface QueryInterface
{
    public function type(): QueryType;

    public function metadata(): QueryMetadata;
}
