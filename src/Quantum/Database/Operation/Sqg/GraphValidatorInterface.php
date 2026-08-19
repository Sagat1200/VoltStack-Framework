<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;

/**
 * 5-pass GraphValidator (DDD-V1-03 §7).
 *
 * Cada pass devuelve list<Violation> vacía si todo OK.
 */
interface GraphValidatorInterface
{
    /**
     * @return list<ValidationViolation>
     */
    public function validate(SemanticQueryGraph $graph, DatabaseCapabilitySet $caps): array;
}
