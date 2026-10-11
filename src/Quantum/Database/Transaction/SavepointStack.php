<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

use Quantum\Database\Transaction\Exception\SavepointException;

/**
 * LIFO stack of SavepointId instances tracking nested transaction savepoints.
 *
 * Ownership: the stack belongs to exactly one TransactionContext. Pop on an
 * empty stack is a programming error and raises SavepointException.
 */
final class SavepointStack
{
    /**
     * @var list<SavepointId>
     */
    private array $savepoints = [];

    public function push(SavepointId $savepoint): void
    {
        $this->savepoints[] = $savepoint;
    }

    public function pop(): SavepointId
    {
        $savepoint = array_pop($this->savepoints);

        if (! $savepoint instanceof SavepointId) {
            throw new SavepointException('No savepoint is available to release from an empty stack.');
        }

        return $savepoint;
    }

    public function peek(): ?SavepointId
    {
        $last = end($this->savepoints);

        return $last instanceof SavepointId ? $last : null;
    }

    public function isEmpty(): bool
    {
        return $this->savepoints === [];
    }

    public function count(): int
    {
        return count($this->savepoints);
    }

    /**
     * @return list<SavepointId>
     */
    public function all(): array
    {
        return $this->savepoints;
    }

    public function clear(): void
    {
        $this->savepoints = [];
    }
}
