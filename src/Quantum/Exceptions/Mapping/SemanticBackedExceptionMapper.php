<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Mapping;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Contracts\ExceptionMapperInterface;
use Quantum\Exceptions\Contracts\SemanticExceptionMapperInterface;
use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\SemanticError;
use Throwable;

/**
 * Adapter BC-safe que implementa el contrato legacy `ExceptionMapperInterface`
 * delegando en el mapper semántico del nuevo pipeline (`SemanticExceptionMapperInterface`).
 *
 * Permite que código legacy que aún consume `ExceptionMapperInterface` funcione
 * con la configuración semántica compilada, sin romper el binding del handler nuevo.
 */
final class SemanticBackedExceptionMapper implements ExceptionMapperInterface
{
    public function __construct(
        private readonly SemanticExceptionMapperInterface $semanticMapper,
    ) {
    }

    public function statusCode(Throwable $throwable): ?int
    {
        $semantic = $this->resolveSemantic($throwable);

        if ($semantic === null) {
            return 500;
        }

        return match ($semantic->category) {
            SemanticCategory::Validation => 422,
            SemanticCategory::Authentication => 401,
            SemanticCategory::Authorization => 403,
            SemanticCategory::NotFound => 404,
            SemanticCategory::Conflict => 409,
            SemanticCategory::Throttled => 429,
            SemanticCategory::Dependency => 503,
            SemanticCategory::Configuration => 500,
            SemanticCategory::Cancelled => 503,
            SemanticCategory::Internal => 500,
        };
    }

    public function headers(Throwable $throwable): array
    {
        $semantic = $this->resolveSemantic($throwable);

        if ($semantic === null) {
            return [];
        }

        if ($semantic->category === SemanticCategory::Throttled) {
            $retryAfter = $semantic->safeParameters['retry_after_seconds'] ?? null;
            if (is_int($retryAfter) && $retryAfter > 0) {
                return ['Retry-After' => (string) $retryAfter];
            }
        }

        return [];
    }

    public function jsonExtensions(Throwable $throwable, bool $debug): array
    {
        return $this->extensions($throwable, $debug);
    }

    public function voltExtensions(Throwable $throwable, bool $debug): array
    {
        return $this->extensions($throwable, $debug);
    }

    public function message(Throwable $throwable, int $status): ?string
    {
        $semantic = $this->resolveSemantic($throwable);

        return $semantic?->messageKey;
    }

    public function errorCode(Throwable $throwable, int $status): ?string
    {
        $semantic = $this->resolveSemantic($throwable);

        return $semantic?->code;
    }

    public function htmlBody(Throwable $throwable, int $status): ?string
    {
        // El renderizado HTML se delega al renderer del nuevo pipeline.
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extensions(Throwable $throwable, bool $debug): array
    {
        $semantic = $this->resolveSemantic($throwable);

        if ($semantic === null) {
            return [];
        }

        $extensions = [
            'code' => $semantic->code,
            'category' => $semantic->category->value,
            'severity' => $semantic->severity->value,
        ];

        foreach ($semantic->safeParameters as $key => $value) {
            if (is_scalar($value) || $value === null || is_array($value)) {
                $extensions[$key] = $value;
            }
        }

        return $extensions;
    }

    private function resolveSemantic(Throwable $throwable): ?SemanticError
    {
        static $cache = [];

        $oid = spl_object_id($throwable);

        if (array_key_exists($oid, $cache)) {
            return $cache[$oid];
        }

        $snapshot = new FailureSnapshot(
            className: $throwable::class,
            internalMessage: $throwable->getMessage(),
            phpCode: $throwable->getCode(),
            origin: 'legacy_compat',
        );

        $context = new ExceptionContext(
            scopeId: 'legacy-compat',
            debug: false,
        );

        return $cache[$oid] = $this->semanticMapper->map($snapshot, $context);
    }
}
