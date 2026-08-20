<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Exception;

/**
 * Lanza cuando el version check en UPDATE devuelve affected rows=0.
 */
class OptimisticLockException extends OrmException {}