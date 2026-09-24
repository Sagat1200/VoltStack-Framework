<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Subject\SubjectDescriptor;

interface SubjectResolverInterface
{
    public function describe(mixed $subject = null): SubjectDescriptor;
}
