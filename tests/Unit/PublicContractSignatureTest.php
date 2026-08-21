<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @api Baseline de firmas públicas estables del framework v0.17.0.
 *
 * Este test garantiza Backward Compatibility (BC) hasta 2.x:
 *   - Si cambia una firma pública estable sin actualizar este baseline, falla.
 *   - Para regenerar el hash, cambia DUMP_BASELINE a true, ejecuta el test, copia el hash
 *     devuelto y actualiza la constante BASELINE_SHA256 (luego vuelve DUMP_BASELINE a false).
 *
 * ⚠️ Cualquier cambio en las firmas de las clases declaradas aquí se considera
 *     una rotura de contrato público y debe incrementar MINOR (1.x) o MAJOR (2.0).
 */
final class PublicContractSignatureTest extends TestCase
{
    /**
     * Modo dump baseline: true para regenerar el hash SHA256 (roturas BC documentadas).
     * false para modo validación estricta en CI (si cambia una firma estable, FAIL).
     */
    private const DUMP_BASELINE = false;

    /**
     * Baseline actualizado el 2026-08-21 tras extender SecurityDecision con contexto público
     * para compatibilidad del stack ControllerSecurity.
     * Longitud canonical = 27 261 bytes (80+ símbolos públicos estables).
     */
    private const BASELINE_SHA256 = '300eafb65fbaaf5d681e723b36446f8ac942853525e7388cf4cb7b6b8e4b0595';

    /**
     * @return list<class-string> Lista de clases/interfaces/enums/attributes públicos ESTABLES del API.
     *
     * ⚠️ REGLAS para añadir símbolos aquí:
     *   - SI incluir: Interfaces Contracts, Enums, DTO readonly, Attributes, Exceptions, Policy classes (no side-effects)
     *   - NO incluir: Clases concretas que requieren Container/HttpKernel/bootstrap (Application, HttpKernel, Router,
     *     ControllerEngine, RequestFactory, etc.) — su reflection sin bootstrap crashea el proceso PHP.
     *   - NO incluir: Helpers, macros, traits o clases @internal.
     */
    private static function stablePublicSymbols(): array
    {
        return [
            // =========================================================
            // CONTRACTS / INTERFACES (Backward Compatibility GARANTIZADA hasta 2.x)
            // =========================================================

            // Platform
            \VoltStack\Framework\Contracts\Kernel::class,
            \VoltStack\Framework\Contracts\ExceptionHandler::class,

            // Container
            \Quantum\Container\Contracts\ContainerInterface::class,

            // Cache
            \Quantum\Cache\Contracts\StoreInterface::class,

            // Exceptions
            \Quantum\Exceptions\Contracts\ExceptionHandlerInterface::class,
            \Quantum\Exceptions\Contracts\ExceptionMapperInterface::class,

            // Http Kernel
            \Quantum\HttpKernel\Contracts\MiddlewareInterface::class,

            // Controllers
            \Quantum\Controllers\Contracts\ControllerExecutionContextAwareInterface::class,

            // Controllers Observability
            \Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface::class,
            \Quantum\Controllers\Observability\Contracts\ControllerEventInterface::class,
            \Quantum\Controllers\Observability\Contracts\ControllerObservabilityManagerInterface::class,

            // Controllers Interceptors
            \Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorChainInterface::class,
            \Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorInterface::class,
            \Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorRegistryInterface::class,
            \Quantum\Controllers\Interceptors\Conditions\Contracts\InterceptorConditionInterface::class,

            // Controllers Security stack (Bloques 11-16, estable 0.16.0)
            \Quantum\Controllers\Security\Contracts\ControllerSecurityContextFactoryInterface::class,
            \Quantum\Controllers\Security\Contracts\ControllerSecurityDecisionEngineInterface::class,
            \Quantum\Controllers\Security\Contracts\ControllerSecurityManagerInterface::class,
            \Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface::class,
            \Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyRegistryInterface::class,
            \Quantum\Controllers\Security\Contracts\PrincipalInterface::class,
            \Quantum\Controllers\Security\Contracts\SecurityDecisionCacheInterface::class,

            // Metadata
            \Quantum\Metadata\Contracts\MetadataEngineInterface::class,
            \Quantum\Metadata\Contracts\MetadataProviderInterface::class,
            \Quantum\Metadata\Contracts\MetadataSubjectInterface::class,

            // Routing
            \Quantum\Routing\Contracts\RouteBindableInterface::class,
            \Quantum\Routing\Dispatching\Contracts\DispatcherInterface::class,

            // Compilation
            \Quantum\Compilation\Contracts\ArtifactStoreInterface::class,
            \Quantum\Compilation\Contracts\BuildManifestInterface::class,
            \Quantum\Compilation\Contracts\CompiledControllerFactoryInterface::class,
            \Quantum\Compilation\Contracts\CompilerInterface::class,

            // =========================================================
            // ENUMS (Backward Compatibility: no se puede eliminar ni renombrar casos hasta 2.x)
            // =========================================================
            \Quantum\Controllers\Security\Context\PrincipalType::class,
            \Quantum\Controllers\Security\Context\AuthenticationStrength::class,
            \Quantum\Controllers\Security\Decision\SecurityDecisionEffect::class,

            // =========================================================
            // DTO READONLY + CONTEXT CLASSES (sin side-effects, estables 0.16.0)
            // =========================================================

            // Security Context DTOs
            \Quantum\Controllers\Security\Context\Principal::class,
            \Quantum\Controllers\Security\Context\TenantIdentity::class,
            \Quantum\Controllers\Security\Context\SecurityAttributes::class,
            \Quantum\Controllers\Security\Context\ControllerSecurityContext::class,
            \Quantum\Controllers\Security\Decision\SecurityDecision::class,
            \Quantum\Controllers\Security\Decision\SecurityDecisionKey::class,
            \Quantum\Controllers\Security\Decision\SecurityEvaluationRequest::class,
            \Quantum\Controllers\Security\Decision\SecurityDecisionCache::class,
            \Quantum\Controllers\Security\Budget\ControllerSecurityBudget::class,
            \Quantum\Controllers\Security\ControllerTarget::class,
            \Quantum\Controllers\Security\ControllerTargetType::class,

            // Composition Policies (readonly, no side-effects)
            \Quantum\Controllers\Security\Policy\Composition\AllOfPolicy::class,
            \Quantum\Controllers\Security\Policy\Composition\AnyOfPolicy::class,
            \Quantum\Controllers\Security\Policy\Composition\NotPolicy::class,
            \Quantum\Controllers\Security\Policy\Composition\WeightedVotingPolicy::class,
            \Quantum\Controllers\Security\Policy\Composition\AtLeastOnePolicy::class,
            \Quantum\Controllers\Security\Policy\Composition\ExpressionTermPolicy::class,
            \Quantum\Controllers\Security\Policy\Composition\NullCompositePolicy::class,

            // Exceptions estables (se usan en catch blocks de apps consumidoras)
            \Quantum\Controllers\Security\Exceptions\AuthenticationRequiredException::class,
            \Quantum\Controllers\Security\Exceptions\AuthorizationDeniedException::class,
            \Quantum\Controllers\Security\Exceptions\ControllerExposureViolationException::class,
            \Quantum\Controllers\Security\Exceptions\SecurityInfrastructureFailureException::class,
            \Quantum\Controllers\Security\Exceptions\TenantViolationException::class,
            \Quantum\Controllers\Security\Exceptions\SecurityException::class,
            \Quantum\Controllers\Security\Exceptions\ControllerSecurityExceptionMapper::class,

            // Exception Handling DTOs (sin side-effects)
            \Quantum\Exceptions\ExceptionHandlingContext::class,
            \Quantum\Exceptions\ExceptionHandlingResult::class,
            \Quantum\Exceptions\Runtime\RuntimeContext::class,
            \Quantum\Exceptions\Runtime\ExceptionHandlingState::class,
            \Quantum\Exceptions\Enums\ExceptionHandlingStatus::class,
            \Quantum\Exceptions\Enums\ExceptionOrigin::class,
            \Quantum\Exceptions\Enums\WorkerDisposition::class,

            // =========================================================
            // PHP ATTRIBUTES (estables 0.12.0+ — las apps ya los usan en controllers)
            // =========================================================
            \Quantum\Controllers\Security\Attributes\AuthenticationRequired::class,
            \Quantum\Controllers\Security\Attributes\Expose::class,
            \Quantum\Controllers\Security\Attributes\Policies::class,
            \Quantum\Controllers\Security\Attributes\Permissions::class,
            \Quantum\Controllers\Security\Attributes\TenantRequired::class,
            \Quantum\Controllers\Security\Attributes\PolicyClass::class,
        ];
    }

    public function test_public_contract_signatures_match_baseline(): void
    {
        $symbols = self::stablePublicSymbols();
        sort($symbols, \SORT_STRING);

        $lines = [];
        foreach ($symbols as $fqcn) {
            if (!class_exists($fqcn) && !interface_exists($fqcn) && !enum_exists($fqcn)) {
                self::fail(sprintf('Simbolo público declarado en baseline no existe: %s', $fqcn));
            }
            $lines[] = self::canonicalizeSymbol($fqcn);
        }

        $canonical = implode("\n--SYMB--\n", $lines) . "\n";
        $hash = hash('sha256', $canonical);

        if (self::DUMP_BASELINE) {
            echo PHP_EOL . '====== NEW BASELINE SHA256 (copia esto a PublicContractSignatureTest::BASELINE_SHA256) ======' . PHP_EOL;
            echo 'private const string BASELINE_SHA256 = \'' . $hash . '\';' . PHP_EOL;
            echo 'LONGITUD CANONICAL: ' . strlen($canonical) . ' bytes' . PHP_EOL;
            echo '==================================================================================================' . PHP_EOL;
            self::assertSame(true, true, 'Modo dump activo; si la firma es OK actualiza la constante BASELINE_SHA256 y pon DUMP_BASELINE=false');
            return;
        }

        self::assertSame(
            self::BASELINE_SHA256,
            $hash,
            sprintf(
                '⚠️ CONTRATO PÚBLICO MODIFICADO.%s'
                . 'Al menos una clase/interfaz API estable cambió de firma.%s'
                . 'Hash actual: %s (esperado: %s)%s'
                . 'Si el cambio fue intencional (rotura BC documentada para 1.x/2.x),%s'
                . 'pon DUMP_BASELINE = true, ejecuta el test y actualiza BASELINE_SHA256.',
                PHP_EOL . PHP_EOL,
                PHP_EOL,
                $hash,
                self::BASELINE_SHA256,
                PHP_EOL . PHP_EOL,
                PHP_EOL,
            ),
        );
    }

    private static function canonicalizeSymbol(string $fqcn): string
    {
        if (enum_exists($fqcn)) {
            $ref = new \ReflectionEnum($fqcn);
            return self::canonicalizeEnum($ref);
        }
        if (interface_exists($fqcn)) {
            $ref = new \ReflectionClass($fqcn);
            return self::canonicalizeInterfaceOrClass($ref, true);
        }
        $ref = new \ReflectionClass($fqcn);
        return self::canonicalizeInterfaceOrClass($ref, false);
    }

    private static function canonicalizeEnum(\ReflectionEnum $ref): string
    {
        $parts = ['ENUM:' . $ref->getName()];
        if ($ref->isBacked()) {
            $parts[] = 'BACKED:' . ($ref->getBackingType()?->getName() ?? 'int');
        }
        $cases = [];
        foreach ($ref->getCases() as $case) {
            $val = $case instanceof \ReflectionEnumBackedCase ? '=' . var_export($case->getBackingValue(), true) : '';
            $cases[] = 'CASE:' . $case->getName() . $val;
        }
        sort($cases, \SORT_STRING);
        return implode('|', [...$parts, ...$cases]);
    }

    private static function canonicalizeInterfaceOrClass(\ReflectionClass $ref, bool $isInterface): string
    {
        $modifiers = [];
        if ($ref->isFinal() && !$isInterface) {
            $modifiers[] = 'final';
        }
        if ($ref->isAbstract() && !$isInterface) {
            $modifiers[] = 'abstract';
        }
        if ($ref->isReadOnly() && !$isInterface) {
            $modifiers[] = 'readonly';
        }
        $modifiersStr = $modifiers ? implode(' ', $modifiers) . ' ' : '';

        $parts = [($isInterface ? 'INTERFACE:' : 'CLASS:') . $modifiersStr . $ref->getName()];

        $parent = $ref->getParentClass();
        if ($parent !== false) {
            $parts[] = 'EXTENDS:' . $parent->getName();
        }

        $interfaces = [];
        foreach ($ref->getInterfaceNames() as $iface) {
            $interfaces[] = $iface;
        }
        sort($interfaces, \SORT_STRING);
        if ($interfaces !== []) {
            $parts[] = 'IMPLEMENTS:' . implode(',', $interfaces);
        }

        // Properties públicas (readonly public son parte del contrato público estable)
        $pubProps = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            $pStr = 'PROP:';
            $flags = [];
            if ($p->isStatic()) {
                $flags[] = 'static';
            }
            if ($p->isReadOnly()) {
                $flags[] = 'readonly';
            }
            if ($flags !== []) {
                $pStr .= implode(':', $flags) . ':';
            }
            $pStr .= ($p->hasType() ? self::typeToString($p->getType()) : 'mixed') . ' ';
            $pStr .= '$' . $p->getName();
            $pubProps[] = $pStr;
        }
        sort($pubProps, \SORT_STRING);
        if ($pubProps !== []) {
            $parts[] = implode(';', $pubProps);
        }

        // Métodos públicos (ordenados por nombre para estabilidad)
        $pubMethods = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }
            $sig = 'METHOD:';
            $flags = [];
            if ($m->isStatic()) {
                $flags[] = 'static';
            }
            if ($m->isAbstract()) {
                $flags[] = 'abstract';
            }
            if ($m->isFinal()) {
                $flags[] = 'final';
            }
            if ($flags !== []) {
                $sig .= implode(':', $flags) . ':';
            }
            $sig .= self::typeToString($m->getReturnType()) . ' ';
            $sig .= $m->getName() . '(';
            $params = [];
            foreach ($m->getParameters() as $p) {
                $pStr = '';
                if ($p->allowsNull() && !str_starts_with(self::typeToString($p->getType()), '?') && self::typeToString($p->getType()) !== 'mixed') {
                    $pStr .= '?';
                }
                $pStr .= self::typeToString($p->getType()) . ' ';
                if ($p->isVariadic()) {
                    $pStr .= '...';
                }
                if ($p->isPassedByReference()) {
                    $pStr .= '&';
                }
                $pStr .= '$' . $p->getName();
                if ($p->isDefaultValueAvailable()) {
                    $default = $p->getDefaultValue();
                    $pStr .= '=' . var_export($default, true);
                }
                $params[] = $pStr;
            }
            $sig .= implode(',', $params) . ')';
            $pubMethods[$m->getName()] = $sig;
        }
        ksort($pubMethods, \SORT_STRING);
        if ($pubMethods !== []) {
            $parts[] = implode('||', $pubMethods);
        }

        return implode('|', $parts);
    }

    private static function typeToString(?\ReflectionType $t): string
    {
        if ($t === null) {
            return 'void-type';
        }
        if ($t instanceof \ReflectionNamedType) {
            $pre = $t->allowsNull() && $t->getName() !== 'mixed' && !str_starts_with($t->getName(), '?') ? '?' : '';
            return $pre . $t->getName();
        }
        if ($t instanceof \ReflectionIntersectionType) {
            $types = [];
            foreach ($t->getTypes() as $st) {
                $types[] = self::typeToString($st);
            }
            return implode('&', $types);
        }
        if ($t instanceof \ReflectionUnionType) {
            $types = [];
            foreach ($t->getTypes() as $st) {
                $types[] = self::typeToString($st);
            }
            return implode('|', $types);
        }
        return 'unknown';
    }
}
