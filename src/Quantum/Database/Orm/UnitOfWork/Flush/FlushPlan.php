<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Flush;

final readonly class FlushPlan
{
    /** @var list<FlushStep> */
    public array $steps;

    public function __construct(array $steps)
    {
        // garantizar orden por order
        usort($steps, static fn(FlushStep $a, FlushStep $b) => $a->order <=> $b->order);
        $this->steps = $steps;
    }
}
