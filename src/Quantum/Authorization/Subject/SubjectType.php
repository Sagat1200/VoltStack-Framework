<?php

declare(strict_types=1);

namespace Quantum\Authorization\Subject;

enum SubjectType: string
{
    case None = 'none';
    case Object = 'object';
    case ClassName = 'class';
    case Scalar = 'scalar';
}
