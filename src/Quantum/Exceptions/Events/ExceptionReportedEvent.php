<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Events;

use DateTimeImmutable;
use Quantum\Controllers\Observability\Contracts\ControllerEventInterface;
use Quantum\Exceptions\Model\ReportRecord;

final readonly class ExceptionReportedEvent implements ControllerEventInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $eventId,
        private string $occurrenceId,
        private DateTimeImmutable $occurredAt,
        private array $payload,
        private int $sequence = 1,
        private string $name = 'quantum.exceptions.reported',
        private int $version = 1,
    ) {
        if ($eventId === '') {
            throw new \InvalidArgumentException('ExceptionReportedEvent eventId must not be empty.');
        }

        if ($occurrenceId === '') {
            throw new \InvalidArgumentException('ExceptionReportedEvent occurrenceId must not be empty.');
        }
    }

    public static function fromRecord(
        ReportRecord $record,
        string $reporterId,
        ?string $deliveryId = null,
        int $sequence = 1,
    ): self {
        $eventId = sha1(sprintf('%s|%s|%s', $record->occurrenceId, $reporterId, $record->fingerprint));

        return new self(
            eventId: $eventId,
            occurrenceId: $record->occurrenceId,
            occurredAt: new DateTimeImmutable('now'),
            payload: [
                'event_id' => $eventId,
                'occurrence_id' => $record->occurrenceId,
                'policy_revision' => (string) ($record->diagnostic['policy_revision'] ?? 'v1'),
                'reporter_id' => $reporterId,
                'delivery_id' => $deliveryId,
                'fingerprint' => $record->fingerprint,
                'semantic' => [
                    'code' => $record->semantic->code,
                    'category' => $record->semantic->category->value,
                    'severity' => $record->semantic->severity->value,
                ],
                'correlation' => $record->correlation,
            ],
            sequence: $sequence,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function executionId(): string
    {
        return $this->occurrenceId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
