<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum ExceptionHandlingStatus: string
{
    case Pending = 'pending';
    case Classified = 'classified';
    case Mapping = 'mapping';
    case Rendering = 'rendering';
    case Handled = 'handled';
    case Aborted = 'aborted';
    case Failed = 'failed';
}

