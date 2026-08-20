<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Mapping;

/**
 * Registry simple para bridges de tipos customizados.
 *
 * - Por defecto vacío; el ServiceProvider puede registrar:
 *   $registry->registerBridge(new MoneyBridge());
 *   $registry->registerBridge(new UuidBridge());
 */
final class CustomTypeBridgeRegistry
{
    /**
     * @var array<class-string,CustomTypeBridgeInterface>
     */
    private array $byPhpClass = [];

    /**
     * @var array<class-string,CustomTypeBridgeInterface>
     */
    private array $byCustomTypeClass = [];

    public function registerBridge(CustomTypeBridgeInterface $bridge): void
    {
        $this->byPhpClass[$bridge->phpClass()] = $bridge;
    }

    /**
     * @param class-string $customTypeClass el atributo #[Column(customType:X::class)
     */
    public function registerBridgeForType(string $customTypeClass, CustomTypeBridgeInterface $bridge): void
    {
        $this->byCustomTypeClass[ltrim($customTypeClass, '\\')] = $bridge;
    }

    /**
     * Busca bridge por phpClass (de dominio) O por customTypeClass.
     */
    public function findBridge(?string $phpClass, ?string $customTypeClass): ?CustomTypeBridgeInterface
    {
        if ($customTypeClass !== null && $customTypeClass !== '') {
            $k = ltrim($customTypeClass, '\\');
            if (isset($this->byCustomTypeClass[$k])) {
                return $this->byCustomTypeClass[$k];
            }
            if (is_subclass_of($k, CustomTypeBridgeInterface::class, true)) {
                // Permitir que customTypeClass == Bridge::class (instancia singleton al vuelo).
                try {
                    $inst = new $k();
                    if ($inst instanceof CustomTypeBridgeInterface) {
                        $this->byCustomTypeClass[$k] = $inst;
                        return $inst;
                    }
                } catch (\Throwable) {
                }
            }
        }
        if ($phpClass !== null && $phpClass !== '' && isset($this->byPhpClass[$phpClass])) {
            return $this->byPhpClass[$phpClass];
        }
        return null;
    }
}
