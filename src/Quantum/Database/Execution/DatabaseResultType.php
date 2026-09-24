<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

enum DatabaseResultType: string
{
    case Rows = 'rows';
    case Scalar = 'scalar';
    case AffectedRows = 'affected_rows';
    case NoResult = 'no_result';
}
