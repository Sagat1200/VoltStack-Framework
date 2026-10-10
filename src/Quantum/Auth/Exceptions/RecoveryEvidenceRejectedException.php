<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

/**
 * Raised when a recovery continuation is rejected because the caller did
 * not present acceptable recovery evidences. The exception carries the
 * structured outcome per evidence so UIs can surface which proof failed
 * and why.
 *
 * Mapping to HTTP is intentionally conservative:
 *   - status 422 (syntactically present but semantically rejected)
 *   - `X-Auth-Recovery-Evidence: rejected` header
 *   - JSON `reason_code` = `recovery.evidence.rejected` with a nested
 *     `evidence_results[]` array.
 */
final class RecoveryEvidenceRejectedException extends AuthenticationException
{
    /**
     * @param list<array{kind:string,passed:bool,skip:bool,reason_code:string,metadata:array<string,mixed>}> $evidenceResults
     * @param array<string, mixed> $metadata
     */
    public static function forResults(
        array $evidenceResults,
        array $metadata = [],
        ?\Throwable $previous = null,
    ): self {
        $message = 'Additional proof of identity is required to complete account recovery.';
        $metadata = array_merge($metadata, [
            'evidence_results' => $evidenceResults,
        ]);

        return new self(
            message: $message,
            code: 0,
            previous: $previous,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $message = 'Account recovery evidence rejected.',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message, 'recovery.evidence.rejected');
    }
}
