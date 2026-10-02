<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\AssuranceContextResolverInterface;

final class DefaultAssuranceContextResolver implements AssuranceContextResolverInterface
{
    public function resolve(AuthenticationOperationContext $context): array
    {
        $currentAssurance = 0;
        $currentAssuranceName = null;

        if ($context->currentContext !== null) {
            try {
                $strength = $context->currentContext->authenticationStrength();
                $currentAssurance = $strength->value;
                $currentAssuranceName = $strength->name;
            } catch (\Throwable) {
                $currentAssurance = 0;
            }

            $assuranceOverride = $context->currentContext->attribute('assurance_value');
            if (is_int($assuranceOverride) || is_numeric($assuranceOverride)) {
                $currentAssurance = (int) $assuranceOverride;
                $customName = $context->currentContext->attribute('assurance_name');
                if (is_string($customName) && $customName !== '') {
                    $currentAssuranceName = $customName;
                }
            }
        }

        return [
            'current_assurance' => $currentAssurance,
            'current_assurance_name' => $currentAssuranceName,
        ];
    }
}
