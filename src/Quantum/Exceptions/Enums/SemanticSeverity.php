<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum SemanticSeverity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
}
