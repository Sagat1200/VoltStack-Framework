<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Benchmark;

use Quantum\Bootstrap\Release\BootstrapReleaseCheckReport;

final readonly class BootstrapBenchmarkReport
{
    public function __construct(
        private string $profile,
        private string $artifactDirectory,
        private BootstrapReleaseCheckReport $cold,
        private BootstrapReleaseCheckReport $warm,
    ) {
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function artifactDirectory(): string
    {
        return $this->artifactDirectory;
    }

    public function cold(): BootstrapReleaseCheckReport
    {
        return $this->cold;
    }

    public function warm(): BootstrapReleaseCheckReport
    {
        return $this->warm;
    }

    public function passed(): bool
    {
        return $this->cold->passed() && $this->warm->passed();
    }

    public function coldDurationMs(): float
    {
        return $this->cold->budget()->totalDurationMs();
    }

    public function warmDurationMs(): float
    {
        return $this->warm->budget()->totalDurationMs();
    }

    public function deltaMs(): float
    {
        return $this->warmDurationMs() - $this->coldDurationMs();
    }

    public function improvementMs(): float
    {
        return $this->coldDurationMs() - $this->warmDurationMs();
    }

    public function warmFaster(): bool
    {
        return $this->warmDurationMs() < $this->coldDurationMs();
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->cold->budget()->violations() as $violation) {
            $violations[] = '[cold] ' . $violation;
        }

        foreach ($this->warm->budget()->violations() as $violation) {
            $violations[] = '[warm] ' . $violation;
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'profile' => $this->profile,
            'artifact_directory' => $this->artifactDirectory,
            'cold_total_duration_ms' => $this->coldDurationMs(),
            'warm_total_duration_ms' => $this->warmDurationMs(),
            'delta_ms' => $this->deltaMs(),
            'improvement_ms' => $this->improvementMs(),
            'warm_faster' => $this->warmFaster(),
            'violations' => $this->violations(),
            'cold' => $this->cold->toArray(),
            'warm' => $this->warm->toArray(),
        ];
    }
}
