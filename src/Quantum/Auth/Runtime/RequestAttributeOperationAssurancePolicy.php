<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\OperationAssurancePolicyInterface;

final class RequestAttributeOperationAssurancePolicy implements OperationAssurancePolicyInterface
{
    public function minimumAssuranceFor(AuthenticationOperationContext $context): ?int
    {
        $minAssuranceAttr = $context->request->attributes['min_authentication_assurance'] ?? null;

        if (is_int($minAssuranceAttr)) {
            return $minAssuranceAttr > 0 ? $minAssuranceAttr : null;
        }

        if (is_numeric($minAssuranceAttr)) {
            $value = (int) $minAssuranceAttr;
            return $value > 0 ? $value : null;
        }

        return null;
    }
}
