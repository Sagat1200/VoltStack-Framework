<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Smoke;

use VoltStack\Runtime\Budget\RuntimeBudgetReport;

final readonly class RuntimeSmokeCheckReport
{
    /**
     * @param list<RuntimeSmokeRequestReport> $requests
     */
    public function __construct(
        private string $driver,
        private string $profile,
        private array $requests,
        private RuntimeBudgetReport $budget,
        private RuntimeBootstrapReuseReport $reuse,
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    /**
     * @return list<RuntimeSmokeRequestReport>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function budget(): RuntimeBudgetReport
    {
        return $this->budget;
    }

    public function reuse(): RuntimeBootstrapReuseReport
    {
        return $this->reuse;
    }

    public function passed(): bool
    {
        return $this->budget->passed() && $this->requestViolations() === [] && $this->reuse->passed();
    }

    /**
     * @return list<string>
     */
    public function requestViolations(): array
    {
        $violations = [];

        foreach ($this->requests as $request) {
            if ($request->passed()) {
                continue;
            }

            $violations[] = sprintf(
                'La request %s %s fallo (status: %s, disposition: %s%s).',
                $request->method(),
                $request->path(),
                $request->statusCode() !== null ? (string) $request->statusCode() : 'null',
                $request->workerDisposition()->value,
                $request->exceptionMessage() !== null ? ', exception: ' . $request->exceptionMessage() : '',
            );
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return [...$this->requestViolations(), ...$this->budget->violations(), ...$this->reuse->violations()];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'driver' => $this->driver,
            'profile' => $this->profile,
            'request_count' => count($this->requests),
            'requests' => array_map(
                static fn(RuntimeSmokeRequestReport $request): array => $request->toArray(),
                $this->requests,
            ),
            'budget' => $this->budget->toArray(),
            'reuse_guard' => $this->reuse->toArray(),
            'violations' => $this->violations(),
        ];
    }
}
