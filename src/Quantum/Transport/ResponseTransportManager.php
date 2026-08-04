<?php

declare(strict_types=1);

namespace Quantum\Transport;

use LogicException;
use Quantum\Transport\Contracts\ResponseInterface;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use Quantum\Transport\Contracts\TransportAdapterInterface;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Enums\TransportStatus;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportExecution;
use Quantum\Transport\Runtime\TransportResult;
use Throwable;

final class ResponseTransportManager implements ResponseTransportManagerInterface
{
    public function __construct(
        private readonly TransportAdapterInterface $adapter,
        private readonly TransportEmitterInterface $emitter,
    ) {
    }

    public function send(ResponseInterface $response, TransportContext $context): TransportResult
    {
        $execution = new TransportExecution($response, $context);

        if (! $this->adapter->supports($response, $context)) {
            throw new LogicException('No transport adapter supports the response for the given context.');
        }

        $execution->status = TransportStatus::Preparing;

        try {
            $execution->prepared = $this->adapter->prepare($response, $context);
            $execution->status = TransportStatus::Prepared;

            $execution->status = TransportStatus::Emitting;
            $execution->result = $this->emitter->emit($execution->prepared, $context);
            $execution->status = $execution->result->status;

            return $execution->result;
        } catch (Throwable $exception) {
            $execution->exception = $exception;
            $execution->status = TransportStatus::Failed;
            $execution->result = new TransportResult(
                status: TransportStatus::Failed,
                bytesEmitted: 0,
                completed: false,
                connectionClosed: false,
                exception: $exception,
            );

            return $execution->result;
        }
    }
}

