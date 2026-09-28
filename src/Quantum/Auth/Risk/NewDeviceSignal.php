<?php

declare(strict_types=1);

namespace Quantum\Auth\Risk;

use Quantum\Auth\Contracts\RiskSignalProviderInterface;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;

final class NewDeviceSignal implements RiskSignalProviderInterface
{
    public function evaluate(AuthenticationRequest $request, ?AuthenticationContext $context = null): RiskScore
    {
        $reasonCodes = [];
        $score = 0;
        $metadata = [];

        $deviceRef = $request->attributes['device_ref'] ?? null;
        $knownDevices = [];

        if ($context !== null) {
            $identityAttrs = $context->identity->attributes ?? [];
            if (is_array($identityAttrs) && isset($identityAttrs['known_device_refs']) && is_array($identityAttrs['known_device_refs'])) {
                $knownDevices = array_values(array_filter($identityAttrs['known_device_refs'], 'is_string'));
            }
        }

        $isNewDevice = is_string($deviceRef) && $deviceRef !== '' && $knownDevices !== [] && ! in_array($deviceRef, $knownDevices, true);

        if ($isNewDevice) {
            $score += 25;
            $reasonCodes[] = 'new_device_detected';
        }

        $metadata['known_device_refs'] = $knownDevices;
        $metadata['current_device_ref'] = $deviceRef;
        $metadata['is_new_device'] = $isNewDevice;

        return RiskScore::fromScore($score, $reasonCodes, $metadata);
    }
}
