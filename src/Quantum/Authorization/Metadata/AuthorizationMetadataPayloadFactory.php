<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

final class AuthorizationMetadataPayloadFactory
{
    /**
     * @param array{public?:bool,requirements?:list<array{ability?:string,subject?:mixed,source?:string}>,fingerprint?:string} $data
     */
    public static function fromArray(array $data): AuthorizationMetadataPayload
    {
        $public = isset($data['public']) ? (bool) $data['public'] : false;
        $fingerprint = isset($data['fingerprint']) && is_string($data['fingerprint']) ? $data['fingerprint'] : null;
        $rawRequirements = isset($data['requirements']) && is_array($data['requirements']) ? $data['requirements'] : [];
        $requirements = [];

        foreach ($rawRequirements as $raw) {
            if (! is_array($raw) || ! isset($raw['ability']) || ! is_string($raw['ability'])) {
                continue;
            }

            $ability = trim($raw['ability']);

            if ($ability === '') {
                continue;
            }

            $requirements[] = new AuthorizationRequirement(
                ability: $ability,
                subject: $raw['subject'] ?? null,
                source: isset($raw['source']) && is_string($raw['source']) ? $raw['source'] : 'metadata',
            );
        }

        return new AuthorizationMetadataPayload(
            public: $public,
            requirements: $requirements,
            fingerprint: $fingerprint,
        );
    }
}
